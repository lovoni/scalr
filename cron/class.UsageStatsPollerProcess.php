<?
	class UsageStatsPollerProcess implements IProcess
    {
        public $ThreadArgs;
        public $ProcessDescription = "Farm usage stats poller";
        public $Logger;
        
    	public function __construct()
        {
        	// Get Logger instance
        	$this->Logger = LoggerManager::getLogger(__CLASS__);
        }
        
        public function OnStartForking()
        {
            $db = Core::GetDBInstance();
            
            $this->Logger->info("Fetching running farms...");
            
            $this->ThreadArgs = $db->GetAll("SELECT farms.*, clients.isactive FROM farms 
            	INNER JOIN clients ON clients.id = farms.clientid WHERE clients.isactive='1' AND farms.status=?",
            	array(FARM_STATUS::RUNNING)
            );
                        
            $this->Logger->info("Found ".count($this->ThreadArgs)." farms.");
        }
        
        public function OnEndForking()
        {
			$db = Core::GetDBInstance(null, true);
        }
        
        public function StartThread($farminfo)
        {
            // Reconfigure observers;
        	Scalr::ReconfigureObservers();
        	
        	$db = Core::GetDBInstance();
            $SNMP = new SNMP();
            
            define("SUB_TRANSACTIONID", posix_getpid());
            define("LOGGER_FARMID", $farminfo["id"]);
            
            $this->Logger->info("[".SUB_TRANSACTIONID."] Begin polling usage stats for farm (ID: {$farminfo['id']}, Name: {$farminfo['name']})");
                        
            //
            // Collect information from database
            //
            $Client = Client::Load($farminfo['clientid']);
            
            $farm_roles = $db->GetAll("SELECT * FROM farm_roles WHERE farmid='{$farminfo['id']}'");
            $this->Logger->debug("[FarmID: {$farminfo['id']}] Farm used ".count($farm_roles)." AMIs");
            
            $farm_instances = $db->GetAll("SELECT * FROM farm_instances WHERE farmid='{$farminfo['id']}'");
            $this->Logger->info("[FarmID: {$farminfo['id']}] Found ".count($farm_instances)." farm instances in database");
                        
            // Get AmazonEC2 Object
            $AmazonEC2Client = AmazonEC2::GetInstance(AWSRegions::GetAPIURL($farminfo['region'])); 
			$AmazonEC2Client->SetAuthKeys($Client->AWSPrivateKey, $Client->AWSCertificate);
                        
            // Get instances from EC2
            $this->Logger->debug("[FarmID: {$farminfo['id']}] Receiving instances info from EC2...");
            $result = $AmazonEC2Client->DescribeInstances();
            $ec2_items = array();
            $ec2_items_by_instanceid = array();
                                   
            if (!is_array($result->reservationSet->item))
                $result->reservationSet->item = array($result->reservationSet->item);
            
            if (is_array($result->reservationSet->item))
            {
                $num = 0;
                foreach ($result->reservationSet->item as $item)
                {
					$ami_role_name = $db->GetOne("SELECT role_name FROM farm_instances WHERE instance_id=? AND farmid=?", 
						array($item->instancesSet->item->instanceId, $farminfo['id'])
					);
					if ($ami_role_name)
					{
	                	if (!is_array($ec2_items[$ami_role_name]))
							$ec2_items[$ami_role_name] = array();
	                            
						array_push($ec2_items[$ami_role_name], $item->instancesSet->item);
						$ec2_items_by_instanceid[$item->instancesSet->item->instanceId] = $item->instancesSet->item;
						$num++;
					}
                }
            }                
            
            $db_amis = $db->Execute("SELECT * FROM farm_roles WHERE farmid=?", array($farminfo["id"]));          
            while ($db_ami = $db_amis->FetchRow())
            {
                $role_running_instances = 0;
                $role_pending_instances = 0;
                $role_terminated_instances = 0;
                $role_running_instances_with_la = 0;
                $role_instances_by_time = array();

                $role = $db->GetOne("SELECT name FROM roles WHERE ami_id=?", array($db_ami['ami_id']));

                $DBFarmRole = DBFarmRole::LoadByID($db_ami['id']);
                
                $this->Logger->info("[FarmID: {$farminfo['id']}] Begin check '{$role}' role instances...");
                
                $items = $ec2_items[$role];
                
                foreach ($items as $item)
                {                	
                	$db_item_info = $db->GetRow("SELECT * FROM farm_instances WHERE instance_id=? AND farmid=?", array($item->instanceId, $farminfo["id"]));                        
                    if ($db_item_info)
                    {
                        if ($db_item_info['state'] == INSTANCE_STATE::PENDING_TERMINATE)
                        	continue;
                    	
                    	$role_instance_ids[$item->instanceId] = $item;
                        
                    	// IF instance on EC2 - running AND db state of instance - running
                        if ($item->instanceState->name == 'running' && ($db_item_info["state"] == INSTANCE_STATE::RUNNING))
                        {
                            if ($db_item_info["isrebootlaunched"] == 0)
                            {
                                $instance_dns = $item->dnsName;
                                $community = $farminfo["hash"];
                                
                                if ($instance_dns)
                                {
        	                    	$SNMP->Connect($db_item_info['external_ip'], null, $community, null, null, true);
                                	$res = $SNMP->Get(".1.3.6.1.4.1.2021.10.1.3.3");
                                }
                                else
                                	continue;
                                
                                if ($res === false)
                                {
                                	//Check Manual change of IP address
                                	$ip = @gethostbyname($instance_dns);
                                	
                                	if ($ip != $instance_dns && substr($ip, 0, 3) == '10.')
                                    {
                                    	preg_match("/([0-9]{2,3}-[0-9]{1,3}-[0-9]{1,3}-[0-9]{1,3})/si", $instance_dns, $matches);
										$ip = str_replace("-", ".", $matches[1]);
                                    }
                                	
                                	if ($ip && $ip != $instance_dns && $ip != $db_item_info['external_ip'])
                                    	continue 2;		
                                    else
                                    {
                                    	$chk = @fsockopen("udp://{$instance_dns}", 161, $errno, $errstr, 5);
                                    	if ($chk)
                                    		Logger::getLogger(LOG_CATEGORY::FARM)->warn(new FarmLogMessage($farminfo['id'], "Instance {$db_item_info['instance_id']} ({$db_item_info['external_ip']}) doesn't respond to SNMP. Scalr was able to open connection to UDP port 161, but snmp doesn't respond. Most likely snmpd is hung up. Try to restart it with /etc/init.d/snmpd restart"));
                                    	else
                                    		Logger::getLogger(LOG_CATEGORY::FARM)->warn(new FarmLogMessage($farminfo['id'], "Cannot retrieve LA. Instance {$db_item_info['instance_id']} did not respond on {$db_item_info['external_ip']}:161. (Error {$errno}: {$errstr})"));
                                    	
                                    	if ($db_ami['status_timeout'] != 0 && $DBFarmRole->GetSetting(DBFarmRole::SETTING_TERMINATE_IF_SNMP_FAILS) == 1)
                                    	{
	                                    	if (!$db_item_info['dtlaststatusupdate'])
	                                    		$db_item_info['dtlaststatusupdate'] = strtotime($db_item_info['dtadded'])+$db_ami['launch_timeout'];
	                                    	
	                                    	if ($db_item_info['dtlaststatusupdate']+$db_ami['status_timeout']*60 < time())
	                                    	{
	                                    		$action = $DBFarmRole->GetSetting(DBFarmRole::SETTING_TERMINATE_ACTION_IF_SNMP_FAILS);
	                                    		if (!$action)
	                                    			$action = 'terminate';
	                                    		
	                                    		if ($db_item_info['isrebootlaunched'] != 1)
						                        {
			                                    	Logger::getLogger(LOG_CATEGORY::FARM)->warn(new FarmLogMessage(
			                                				$farminfo['id'], 
			                                				sprintf(
			                                					_("Failed to retrieve LA on instance %s for %s minutes. Try increasing '{$action} instance if cannot retrieve it's status' setting on %s configuration tab."),
			                                					$db_item_info['instance_id'],
			                                					$db_ami['status_timeout'],
			                                					$roleinfo['name']
			                                				)
			                                		));
			                                		
		                                    		try
							                        {	//  reboots or terminates instance depending on the selected value in the farm edit menu	
							                            switch ($action)
							                            {
															default:
																$this->Logger->info("The instance will be terminated by default");
							                            	case "terminate":
																Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($farminfo['id'], "Scheduled termination for instance '{$db_item_info["instance_id"]}' ({$db_item_info["external_ip"]}). It will be terminated in 3 minutes."));														
							                            		Scalr::FireEvent($farminfo['id'], new BeforeHostTerminateEvent(DBInstance::LoadByID($db_item_info['id'])));						                            		
							                            		break;
							                            		
							                            	case "reboot":	
	
																	Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($farminfo['id'], "Sending reboot request to instance '{$db_item_info["instance_id"]}' ({$db_item_info["external_ip"]}). "));															
																	// reboot instance 
																	$AmazonEC2Client->RebootInstances(array($db_item_info['instance_id'])); 															
							                            		
																break;
							                            } 
							                            
														Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($farminfo['id'], "Reboot/terminate for instance '{$db_item_info["instance_id"]}' ({$db_item_info["external_ip"]}). successfully completed "));
	
							                        }
							                        catch (Exception $e)
							                        {
							                            $this->Logger->fatal("[FarmID: {$farminfo['id']}] Cannot terminate {$db_item_info['instance_id']}': {$e->getMessage()}");
							                        }
						                        }
	                                    	}
                                    	}
                                    }
                                }
                                else 
                                {                                	
                                    preg_match_all("/[0-9]+/si", $SNMP->Get(".1.3.6.1.2.1.2.2.1.10.2"), $matches);
                                    $bw_in = $matches[0][0];
						                        
						            preg_match_all("/[0-9]+/si", $SNMP->Get(".1.3.6.1.2.1.2.2.1.16.2"), $matches);
						            $bw_out = $matches[0][0];
						            
						            if ($bw_in > $db_item_info["bwusage_in"] && ($bw_in-(int)$db_item_info["bwusage_in"]) > 0)
						            	$bw_in_used[] = round(((int)$bw_in-(int)$db_item_info["bwusage_in"])/1024, 2);
						            else
						            	$bw_in_used[] = $bw_in/1024;
						            	
						            if ($bw_out > $db_item_info["bwusage_out"] && ($bw_out-(int)$db_item_info["bwusage_out"]) > 0)
						            	$bw_out_used[] = round(((int)$bw_out-(int)$db_item_info["bwusage_out"])/1024, 2);
						            else
						            	$bw_out_used[] = $bw_out/1024;
						            
						            $db->Execute("UPDATE farm_instances SET bwusage_in=?, bwusage_out=?, dtlaststatusupdate=UNIX_TIMESTAMP(NOW()) WHERE id=?",
						            	array($bw_in, $bw_out, $db_item_info["id"])
						            );
                                }
                            }
                                 
                            $role_running_instances++;
                            
                            if ($role_instances_by_time[strtotime($item->launchTime)])
                                $role_instances_by_time[strtotime($item->launchTime)+rand(10, 99)] = $item;
                            else 
                                $role_instances_by_time[strtotime($item->launchTime)] = $item;
                                
                            ksort($role_instances_by_time);
                        }
                    }                    
                } //for each items
            }
            
            //
            // Update statistics
            //
			$this->Logger->debug("Updating statistics for farm.");
                
			$current_stat = $db->GetRow("SELECT * FROM farm_stats WHERE farmid=? AND month=? AND year=?",
				array($farminfo['id'], date("m"), date("Y"))
			);
                
			foreach ($ec2_items as $ami_id => $items)
			{				
				foreach ($items as $item)
				{
					$launch_time = strtotime($item->launchTime);
					$uptime = time() - $launch_time;
	                    
					$last_uptime = $db->GetOne("SELECT uptime FROM farm_instances WHERE instance_id=?", array($item->instanceId));
					$uptime_delta = $uptime-$last_uptime;
	                    
					$stat_uptime[$item->instanceType] += $uptime_delta;
					
					$db->Execute("UPDATE farm_instances SET uptime=? WHERE instance_id=?",
						array($uptime, $item->instanceId)
					);
				}
			}
                                
			if (!$current_stat)
			{
				$db->Execute("INSERT INTO farm_stats SET farmid=?, month=?, year=?",
					array($farminfo['id'], date("m"), date("Y"))
				);
			}
			
			$data = array(
                (int)array_sum((array)$bw_in_used),
                (int)array_sum((array)$bw_out_used),
                (int)$stat_uptime['m1.small'],
                (int)$stat_uptime['m1.large'],
                (int)$stat_uptime['m1.xlarge'],
                (int)$stat_uptime['c1.medium'],
                (int)$stat_uptime['c1.xlarge'],
                
                time(),
                $farminfo['id'],
                date("m"),
                date("Y")
			);
			
			$db->Execute("UPDATE farm_stats SET 
                bw_in		= bw_in+?, 
                bw_out		= bw_out+?, 
                m1_small	= m1_small+?,
                m1_large	= m1_large+?,
                m1_xlarge	= m1_xlarge+?,
                c1_medium	= c1_medium+?,
                c1_xlarge	= c1_xlarge+?,
                dtlastupdate = ?
                WHERE farmid = ? AND month = ? AND year = ?
			", $data);                
                
 			//
			//Statistics update - end
			//
        }
    }
?>