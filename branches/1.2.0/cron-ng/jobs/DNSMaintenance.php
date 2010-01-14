<?php

	class Scalr_Cronjob_DNSMaintenance extends Scalr_System_Cronjob_MultiProcess_DefaultWorker {

        static function getConfig () {
        	return array(
        		"description" => "DNS maintenance poller"
        	);
        }
		
        private $logger;

        private $db;
        
    	function __construct() {
        	// Get Logger instance
        	$this->logger = LoggerManager::getLogger(__CLASS__);
        	$this->db = Core::GetDBInstance();
        }
        
        function startChild () {
        	// Reopen DB connection in child
        	$this->db = Core::GetDBInstance(null, true);
            // Reconfigure observers;
        	Scalr::ReconfigureObservers();
        }        
        
        function enqueueWork ($workQueue) {
            $this->logger->info("Fetching completed farms...");
            
            $rows = $this->db->GetAll("SELECT farms.id FROM farms 
           		INNER JOIN clients ON clients.id = farms.clientid 
           		WHERE farms.status=? AND clients.isactive='1'",
           		array(FARM_STATUS::RUNNING)
           	);
            $this->logger->info("Found ".count($rows)." farms.");
            
            foreach ($rows as $row) {
            	$workQueue->put($row["id"]);
            }
        }
        
        function handleWork ($farmId) {
        	
            $SNMP = new SNMP();
            $DBFarm = DBFarm::LoadByID($farmId);
            
            $GLOBALS["SUB_TRANSACTIONID"] = abs(crc32(posix_getpid().$farmId));
            $GLOBALS["LOGGER_FARMID"] = $DBFarm->ID;
            
            $this->logger->info(sprintf("[%s] Begin DNS maintenance for farm (ID: %d , Name: '%s', Status: %d)", 
            		$GLOBALS["SUB_TRANSACTIONID"], $DBFarm->ID, $DBFarm->Name, $DBFarm->Status));
            
            //
            // Check farm status
            //
            if ($this->db->GetOne("SELECT status FROM farms WHERE id=?", array($DBFarm->ID)) != 1)
            {
            	$this->logger->warn("[FarmID: {$DBFarm->ID}] Farm terminated by client.");
            	return;
            }
            
            $DNSZoneController = new DNSZoneControler();
              
            //
            // Start DNS Zone maintenance
            //
            $this->logger->info("[FarmID: {$DBFarm->ID}] Checking DNS zones");
            try
            {
				// Check zomby records
				$this->logger->debug("[FarmID: {$DBFarm->ID}] Checking zomby records");
				
				$records = $this->db->GetAll("SELECT * FROM records WHERE rtype = 'A' AND 
					issystem = '1' AND 
					zoneid IN (
					SELECT id FROM zones WHERE farmid = ? AND 
					allow_manage_system_records = '0' )"			
				, array($DBFarm->ID));
					
				
	            $malformed_zones = array();
	            if ($records && count($records) > 0)
	            {
		            foreach ($records as $record)
		            {
		            	$zoneinfo = $this->db->GetRow("SELECT * FROM zones WHERE id='{$record['zoneid']}'");
									
		            	$exclude = false;
	            		$instance = $this->db->GetRow("SELECT * FROM farm_instances 
		            		WHERE farmid='{$DBFarm->ID}' AND 
		            		(external_ip = '{$record['rvalue']}' OR 
		            		internal_ip = '{$record['rvalue']}') AND isactive='1'");
	            		if ($instance)
	            		{
		            		$use_elb = false;
	            			
	            			try
		            		{
		            			$DBFarmRole = DBFarmRole::LoadByID($instance['farm_roleid']);
		            			
		            			$use_elb = ($DBFarmRole->GetSetting(DBFarmRole::SETTING_BALANCING_USE_ELB) == 1);
		            			
		            			if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_EXCLUDE_FROM_DNS) == 1)
		            				$exclude = true;
		            		}
		            		catch(Exception $e)
		            		{
		            			$this->logger->warn(sprintf("DNSMaintenance: %s", $e->getMessage()));
		            		}
	            		}
	            		
		            	if (!$instance || $exclude) {
		            		$this->logger->warn("[FarmID: {$DBFarm->ID}] Found zomby record: '{$record['rkey']} {$record['ttl']} IN A {$record['rvalue']}'");
		            		$malformed_zones[$record['zoneid']] = 1;
		            		$this->db->Execute("DELETE FROM records WHERE id='{$record['id']}'");
		            	}
		            	
		            	
		            	
		            	if ($record["rkey"] == "@")
		            	{
		            		$instance_rolename = $this->db->GetOne("SELECT role_name FROM farm_instances 
		            			WHERE external_ip=? AND farmid=? AND isactive='1'",
		            		array($record["rvalue"], $zoneinfo["farmid"]));
		            		
		            		if (!$instance_rolename || $instance_rolename != $zoneinfo["role_name"])
		            		{
		            			$malformed_zones[$record['zoneid']] = 1;
		            			$this->db->Execute("DELETE FROM records WHERE id='{$record['id']}'");
		            		}
		            	}
		            }
	            }
	            
	            $nss = $this->db->GetAll("SELECT * FROM nameservers WHERE isproxy='0' AND isbackup='0'");
				
				// Check malformed zones           
				$zones = $this->db->GetAll("SELECT * FROM zones 
								WHERE farmid = ? AND
								allow_manage_system_records = '0'"
							,array($DBFarm->ID));							
							
	            foreach ($zones as $zone)
	            {
	            	// Check for NS records
	            	$this->logger->debug("[FarmID: {$DBFarm->ID}] Checking for malformed NS records");
	            	if (count($nss) != $this->db->GetOne("SELECT COUNT(*) FROM records WHERE rtype='NS' AND zoneid='{$zone['id']}' AND issystem='1'"))
	            	{
	            		$this->logger->warn("[FarmID: {$DBFarm->ID}] Outdated NS records for zone '{$zone['zone']}'");
	            		
	            		$this->db->Execute("DELETE FROM records WHERE rtype='NS' AND zoneid='{$zone['id']}' AND issystem='1'");
	            		foreach ($nss as $ns)
	            			$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
	            				$zone['id'], "NS", 14400, null, "{$ns["host"]}.", "{$zone['zone']}.", 1
	            			));
	            		$malformed_zones[$zone['id']] = 1;
	            	}
	            	
	            	// Check for A records
	            	$this->logger->debug("[FarmID: {$DBFarm->ID}] Checking for malformed A records");
	            	
	            	$instances = $DBFarm->GetInstancesByFilter(array("state" => INSTANCE_STATE::RUNNING, "isactive" => '1'));
	            	foreach ($instances as $DBInstance)
	            	{
	            		$DBFarmRole = $DBInstance->GetDBFarmRoleObject();
	            		
	            		$use_elb = false;	            		
	            		try
	            		{
	            			$use_elb = ($DBFarmRole->GetSetting(DBFarmRole::SETTING_BALANCING_USE_ELB) == 1);
							if ($use_elb)
								$elb_hostname = $DBFarmRole->GetSetting(DBFarmRole::SETTING_BALANCING_HOSTNAME);
	            			
	            			if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_EXCLUDE_FROM_DNS) == 1)
	            			{
	            				$this->logger->info("[FarmID: {$DBFarm->ID}] Role excluded from DNS. Skipping instance {$DBInstance->InstanceID}.");
	            				continue;
	            			}
	            		}
	            		catch(Exception $e)
	            		{
	            			$this->logger->warn(sprintf("DNSMaintenance: %s", $e->getMessage()));
	            			continue;
	            		}
	            		
	            		$this->db->Execute("DELETE FROM records WHERE issystem='1' AND rtype='CNAME' AND zoneid=?", array($zone['id']));
	            			            		
	            		if ($DBInstance->RoleName == $zone["role_name"])
	            		{
	            			// Check A records for external IP
	            			if (!$this->db->GetOne("SELECT id FROM records WHERE rtype='A' AND rkey='@' AND issystem='1' AND rvalue=? AND zoneid=?",
	            				array($DBInstance->ExternalIP, $zone['id'])
	            			))
	            			{
	            				$this->logger->warn("[FarmID: {$DBFarm->ID}] Outdated A records for external IP: {$DBInstance->ExternalIP} ({$zone['zone']})");
	            				
	            				// Missed A record, add it
	            				$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
		            				$zone['id'], "A", 90, null, "{$DBInstance->ExternalIP}", "@", 1
		            			));
		            			
		            			$malformed_zones[$zone['id']] = 1;
	            			}
	            		}
	            		
	            		// Check int-mysql records
	            		if ($DBFarmRole->GetRoleAlias() == ROLE_ALIAS::MYSQL)
	            		{
	            			if ($DBInstance->IsDBMaster == 1)
	            			{
	            				if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype=? AND rkey=? AND rvalue=?",
	            					array($zone['id'], "A", "int-{$DBInstance->RoleName}-master", $DBInstance->InternalIP)))
	            				{
	            					$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
		            					$zone['id'], "A", 20, null, $DBInstance->InternalIP, "int-{$DBInstance->RoleName}-master", 1
		            				));
		            				$malformed_zones[$zone['id']] = 1;
	            				}
								
	            				if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype=? AND rkey=? AND rvalue=?",
	            					array($zone['id'], "A", "ext-{$DBInstance->RoleName}-master", $DBInstance->ExternalIP)))
	            				{
			            			$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
			            				$zone['id'], "A", 20, null, "{$DBInstance->ExternalIP}", "ext-{$DBInstance->RoleName}-master", 1
			            			));
			            			$malformed_zones[$zone['id']] = 1;
	            				}
	            			
	            				if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype='A' AND rkey LIKE '%-slave' AND (rvalue=? OR rvalue=?) AND issystem='1'",
	            					array($zone['id'], $DBInstance->InternalIP, $DBInstance->ExternalIP)))
	            				{
		            				$this->db->Execute("DELETE FROM records WHERE zoneid=? AND rtype='A' AND rkey LIKE '%-slave' AND (rvalue=? OR rvalue=?) AND issystem='1'",
										array($zone['id'], $DBInstance->InternalIP, $DBInstance->ExternalIP)
									);
	            				}
	            				
		            			if ($DBFarmRole->GetRoleName() != ROLE_ALIAS::MYSQL)
		            			{
		            				if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype=? AND rkey=? AND rvalue=?",
		            					array($zone['id'], "A", "int-mysql-master", $DBInstance->InternalIP)))
		            				{
				            			$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
				            				$zone['id'], "A", 20, null, $DBInstance->InternalIP, "int-mysql-master", 1
				            			));
				            			$malformed_zones[$zone['id']] = 1;
		            				}
		            				
		            				if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype=? AND rkey=? AND rvalue=?",
		            					array($zone['id'], "A", "ext-mysql-master", $DBInstance->ExternalIP)))
		            				{
				            			$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
				            				$zone['id'], "A", 20, null, $DBInstance->ExternalIP, "ext-mysql-master", 1
				            			));
				            			$malformed_zones[$zone['id']] = 1;
		            				}
		            			}
	            			}
	            			
	            			if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype=? AND rkey=? AND rvalue=?",
            					array($zone['id'], "A", "int-mysql", $DBInstance->InternalIP)))
            				{
	            				$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
		            				$zone['id'], "A", 20, null, $DBInstance->InternalIP, "int-mysql", 1
		            			));
		            			$malformed_zones[$zone['id']] = 1;
            				}
            				
            				if (!$this->db->GetOne("SELECT id FROM records WHERE zoneid=? AND rtype=? AND rkey=? AND rvalue=?",
            					array($zone['id'], "A", "ext-mysql", $DBInstance->ExternalIP)))
            				{
		            			$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
		            				$zone['id'], "A", 20, null, $DBInstance->ExternalIP, "ext-mysql", 1
		            			));
		            			$malformed_zones[$zone['id']] = 1;
            				}
	            		}
	            		
	            		// Check A records for internal IP
	            		if (!$this->db->GetOne("SELECT id FROM records WHERE rtype='A' AND issystem='1' AND rvalue=? AND zoneid=? AND rkey=?", array(
	            			$DBInstance->InternalIP, $zone['id'], "int-{$DBInstance->RoleName}" 
	            		)))
            			{
            				$this->logger->warn("[FarmID: {$DBFarm->ID}] Outdated A records for internal IP: {$DBInstance->InternalIP} ({$zone['zone']})");
            				
            				            				
            				// Missed A record, add it
            				$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
	            				$zone['id'], "A", 20, null, $DBInstance->InternalIP, "int-{$DBInstance->RoleName}", 1
	            			));
	            			
	            			$malformed_zones[$zone['id']] = 1;
            			}
            			
            			// Check A records for external IP (ext-)
	            		if (!$this->db->GetOne("SELECT id FROM records WHERE rtype='A' AND issystem='1' AND rvalue=? AND zoneid=? AND rkey=?", array(
	            			$DBInstance->ExternalIP, $zone['id'], "ext-{$DBInstance->RoleName}"
	            		)))
            			{
            				$this->logger->warn("[FarmID: {$DBFarm->ID}] Outdated A records for external IP: {$DBInstance->ExternalIP} ({$zone['zone']})");
            				
            				// Missed A record, add it
            				$this->db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array(
	            				$zone['id'], "A", 20, null, $DBInstance->ExternalIP, "ext-{$DBInstance->RoleName}", 1
	            			));
	            			
	            			$malformed_zones[$zone['id']] = 1;
            			}
	            	}
	            }
	            
	            try
	            {
		            $obsoleted_zones = $this->db->GetAll("SELECT * FROM zones WHERE isobsoleted='1' AND status='1' AND farmid='{$DBFarm->ID}'");
		            foreach ($obsoleted_zones as $obsoleted_zone)
		            {
		            	$malformed_zones[$obsoleted_zone['id']] = 1;
		            	$this->db->Execute("UPDATE zones SET isobsoleted='0' WHERE id=?", array($obsoleted_zone['id']));
		            }
	            }
	            catch(Exception $e)
	            {
	            	$this->logger->fatal($e->getMessage());
	            }
	            
	            // Set more retries for locked zone for maintenance process
	            CONFIG::$ZONE_LOCK_WAIT_RETRIES = 10;
	            
	            if (count($malformed_zones) > 0)
	            {
	            	foreach(array_keys($malformed_zones) as $malformed_zoneid)
	            	{
	            		$zoneinfo = $this->db->GetRow("SELECT * FROM zones WHERE id='{$malformed_zoneid}'");
	            		$this->logger->info("[FarmID: {$DBFarm->ID}] Fixing malformed zone (ID: {$malformed_zoneid}, Name: {$zoneinfo['zone']})");
	            		$DNSZoneController->Update($malformed_zoneid);
	            	}
	            }
            }
            catch(Exception $e)
            {
            	$this->logger->fatal("[FarmID: {$DBFarm->ID}]".$e->getMessage());
            }
            $this->logger->debug("[FarmID: {$DBFarm->ID}] DNS zones check complete");
            
            //
            // End DNS Zone maintenance
            //
        	
        }
    }
