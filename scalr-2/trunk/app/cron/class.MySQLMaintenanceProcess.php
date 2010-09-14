<?
	class MySQLMaintenanceProcess implements IProcess
    {
        public $ThreadArgs;
        public $ProcessDescription = "Maintenance mysql role on farms";
        public $Logger;
        
        public function __construct()
        {
        	// Get Logger instance
        	$this->Logger = Logger::getLogger(__CLASS__);
        }
        
        public function OnStartForking()
        {
			$db = Core::GetDBInstance();
			
			$this->ThreadArgs = $db->GetAll("SELECT id FROM farm_roles WHERE role_id IN (SELECT id FROM roles WHERE alias=?)", 
            	array(ROLE_ALIAS::MYSQL)
            );
        }
        
        public function OnEndForking()
        {
            
        }
        
        public function StartThread($mysql_farm_ami)
        {
        	// Reconfigure observers;
        	Scalr::ReconfigureObservers();
        	
        	$db = Core::GetDBInstance();
            $Shell = ShellFactory::GetShellInstance();
            $SNMP = new SNMP();
        	
            $DBFarmRole = DBFarmRole::LoadByID($mysql_farm_ami['id']);
            $DBFarm = $DBFarmRole->GetFarmObject();    
            
			//skip terminated farms
			if ($DBFarm->Status != FARM_STATUS::RUNNING)
				return;
                    
			//
            // Check replication status
            //
			$this->Logger->info("[FarmID: {$DBFarm->ID}] Checking replication status");
			
			$servers = $DBFarmRole->GetServersByFilter(array(
				'status'		=> SERVER_STATUS::RUNNING
			));
			
			foreach ($servers as $DBServer)
            {
                try
   				{
	   				if ($DBServer->GetProperty(SERVER_PROPERTIES::DB_MYSQL_MASTER) == 1)
	   					continue;
   					
   					$this->Logger->info("[FarmID: {$DBFarm->ID}] {$DBServer->remoteIp} -> SLAVE STATUS");
	   				
	   				$sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
					@socket_set_nonblock($sock);
					
	   				$time = time();
	   				$res = true;
				    while (!@socket_connect($sock, $DBServer->remoteIp, 3306))
				    {
						$err = @socket_last_error($sock);
						if ($err == 115 || $err == 114 || $err == 36 || $err == 37)
						{
							if ((time() - $time) >= 5)
							{
								@socket_close($sock);
				        		$res = false;
				        		break;
				        	}
				        	
				        	sleep(1);
				        	continue;
				      	}
				      	else
				      	{
				      		$res = ($err == 56) ? true : false;
				      		break;
				      	}
				    }
					
	   				if (!$res)
	   				{
	   					Logger::getLogger(LOG_CATEGORY::FARM)->warn(new FarmLogMessage($DBFarm->ID, 
	   						sprintf(_("Scalr cannot connect to server %s:3306 (%s) and check replication status. (Error (%s):%s)"), 
	   						$DBServer->remoteIp, $DBServer->serverId, $err, socket_strerror($err)
	   					)));
	   					continue;
	   				}
	   				
	   				// Connect to Mysql on slave
	   				$conn = &NewADOConnection("mysqli");
	                    $conn->Connect($DBServer->remoteIp, CONFIG::$MYSQL_STAT_USERNAME, $DBServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_MYSQL_STAT_PASSWORD), null);
	   				$conn->SetFetchMode(ADODB_FETCH_ASSOC); 
	                    
	   				// Get Slave status
	   				$r = $conn->GetRow("SHOW SLAVE STATUS");
	   				
	   				// Check slave replication running or not
	   				if ($r['Slave_IO_Running'] == 'Yes' && $r['Slave_SQL_Running'] == 'Yes')
	   					$replication_status = 1;
	   				else
	   					$replication_status = 0;
	   					
	   				if ($replication_status != $DBServer->GetProperty(SERVER_PROPERTIES::DB_MYSQL_REPLICATION_STATUS))
	   				{
	   					if ($replication_status == 0)
	   						Scalr::FireEvent($DBFarm->ID, new MySQLReplicationFailEvent($DBServer));
	   					else
	   						Scalr::FireEvent($DBFarm->ID, new MySQLReplicationRecoveredEvent($DBServer));
	   				}
	   			}
	   			catch(Exception $e)
	   			{
	   				Logger::getLogger(LOG_CATEGORY::FARM)->warn(
	   					new FarmLogMessage(
	   						$DBFarm->ID, 
	   						"Cannot retrieve replication status. {$e->getMessage()}"
	   					)
	   				);
	   			}
			}
                      
            //
            // Check backups and mysql bandle procedures
            //
                                        
            //Backups
			if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BCP_ENABLED) && $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BCP_EVERY) != 0)
			{
				if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_IS_BCP_RUNNING) == 1)
				{
                    // Wait for timeout time * 2 (Example: NIVs problem with big mysql snapshots)
                    // We must wait for running bundle process.
                	$bcp_timeout = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BCP_EVERY)*(60*2);
                    if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_LAST_BCP_TS)+$bcp_timeout < time())
                    	$bcp_timeouted = true;
                    	
	                if ($bcp_timeouted)
	                {
	                	$DBFarmRole->SetSetting(DBFarmRole::SETTING_MYSQL_IS_BCP_RUNNING, 0);
	                	$this->Logger->info("[FarmID: {$DBFarm->ID}] MySQL Backup already running. Timeout. Clear lock.");
	                }
				}
                else
                {
                	$timeout = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BCP_EVERY)*60;
                    if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_LAST_BCP_TS)+$timeout < time())
                    {
                        $this->Logger->info("[FarmID: {$DBFarm->ID}] Need new backup");
                        
                        $servers = $DBFarm->GetMySQLInstances(false, true);
						if (!$servers[0])
							 $servers = $DBFarm->GetMySQLInstances(true);
                        
						$DBServer = $servers[0];
                        
                        if ($DBServer)
                        {
                        	$msg = new Scalr_Messaging_Msg_Mysql_CreateBackup();
                        	$msg->rootPassword = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_ROOT_PASSWORD);
                            $DBServer->SendMessage($msg);
                        	
                            $DBFarmRole->SetSetting(DBFarmRole::SETTING_MYSQL_IS_BCP_RUNNING, 1);
                            $DBFarmRole->SetSetting(DBFarmRole::SETTING_MYSQL_BCP_SERVER_ID, $DBServer->serverId);
                        }
                        else 
                            $this->Logger->info("[FarmID: {$DBFarm->ID}] There is no running mysql instances for run backup procedure!");
                    }
				}
			}
                
			if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_ENABLED) && $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_EVERY) != 0)
			{
				if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_IS_BUNDLE_RUNNING) == 1)
                {	                    
                    // Wait for timeout time * 2 (Example: NIVs problem with big mysql snapshots)
                    // We must wait for running bundle process.
                	$bundle_timeout = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_EVERY)*(3600*2);
	                if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_LAST_BUNDLE_TS)+$bundle_timeout < time())
	                	$bundle_timeouted = true;
                    	
	                if ($bundle_timeouted)
	                {
	                	$DBFarmRole->SetSetting(DBFarmRole::SETTING_MYSQL_IS_BUNDLE_RUNNING, 0);
	                	$this->Logger->info("[FarmID: {$DBFarm->ID}] MySQL Bundle already running. Timeout. Clear lock.");
	                }
                }
                else
                {
					$timeout = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_EVERY)*3600;
					if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_LAST_BUNDLE_TS)+$timeout < time())
					{
						$this->Logger->info("[FarmID: {$DBFarm->ID}] Need mySQL bundle procedure");
	                    
	                	// Rebundle
	               		$servers = $DBFarm->GetMySQLInstances(true, false);
                        $DBServer = $servers[0];
						
						if ($DBServer)
	                    {                            
	                        $DBFarmRole = $DBServer->GetFarmRoleObject();
	                        
	                        $pbw_from = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_WINDOW_START);
	                        $pbw_to = $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_WINDOW_END);
	                        if ($pbw_from && $pbw_to)
	                        {
	                        	$current_time = (int)date("Hi");
	                        	if ($pbw_from <= $current_time && $pbw_to >= $current_time)
									$allow_bundle = true;
	                        }
	                        else
	                        	$allow_bundle = true;
	                        
	                        if ($allow_bundle)
	                        {
		                        $DBServer->SendMessage(new Scalr_Messaging_Msg_Mysql_CreateDataBundle());
		                        
	                            $DBFarmRole->SetSetting(DBFarmRole::SETTING_MYSQL_IS_BUNDLE_RUNNING, 1);
	                            $DBFarmRole->SetSetting(DBFarmRole::SETTING_MYSQL_BUNDLE_SERVER_ID, $DBServer->serverId);
	                        }
	                    }
	                    else 
	                        $this->Logger->info("[FarmID: {$DBFarm->ID}] There is no running mysql master instances for run bundle procedure!");
					}
	            }
			}
       	 }
    }
?>