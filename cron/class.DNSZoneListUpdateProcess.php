<?
	class DNSZoneListUpdateProcess implements IProcess
    {
        public $ThreadArgs;
        public $ProcessDescription = "Remove locks and update named.conf";
        public $Logger;
        
    	public function __construct()
        {
        	// Get Logger instance
        	$this->Logger = LoggerManager::getLogger(__CLASS__);
        	$this->Crypto = Core::GetInstance("Crypto", CONFIG::$CRYPTOKEY);
        }
        
        public function OnStartForking()
        {
            $db = Core::GetDBInstance();
            
            $cpwd = $this->Crypto->Decrypt(@file_get_contents(dirname(__FILE__)."/../etc/.passwd"));
            
            $Shell = ShellFactory::GetShellInstance();
            
            // Remove old locks
            $timeout = CONFIG::$ZONE_LOCK_WAIT_RETRIES*(CONFIG::$ZONE_LOCK_WAIT_TIMEOUT/1000000)+10;
            $db->Execute("UPDATE zones SET islocked='0' WHERE dtlocked < ? AND islocked='1'", array(time()-$timeout));
                        
            // Prepare nameservers
            foreach((array)$db->GetAll("SELECT * FROM nameservers WHERE isproxy='0'") as $ns)
			{
				if ($ns["host"]!='')
				{
				    $nameservers[$ns["host"]] = new RemoteBIND($ns["host"], 
											$ns["port"],
											array("type" => "password", "login" => $ns["username"], "password" => $this->Crypto->Decrypt($ns["password"], $cpwd)),
											$ns["rndc_path"],
											$ns["namedconf_path"],
											$ns["named_path"], 
											CONFIG::$NAMEDCONFTPL
										  );
				}
			}
            
			// Update allowed hosts for zones
			try
			{
				$uzones = $db->GetAll("SELECT * FROM zones WHERE hosts_list_updated='0' AND status=?", array(ZONE_STATUS::ACTIVE));
				foreach ($uzones as $uzone)
				{
					foreach ($nameservers as $host=>$nameserver)
	            	{
	            		$this->Logger->info("Updating list of allowed hosts for '{$uzone["zone"]}' on '{$host}'");
	            		
	            		$allowed_hosts = ($uzone['axfr_allowed_hosts']) ? $uzone['axfr_allowed_hosts'] : "none"; 
	            		
	            		$nameserver->UpdateZoneDirectives($uzone['zone'], $allowed_hosts);
	            		$reload_bind = true;
	            	}
	            	
	            	$db->Execute("UPDATE zones SET hosts_list_updated='1' WHERE id=?", array($uzone['id']));
				}
			}
			catch(Exception $e)
			{
				$this->Logger->fatal($e->getMessage());
			}
			
			while ($Task = TaskQueue::Attach(QUEUE_NAME::CREATE_DNS_ZONE)->Poll())
	        {
	        	$zone = $db->GetRow("SELECT * FROM zones WHERE id=?", array($Task->ZoneID));
	        	if ($zone["status"] != ZONE_STATUS::PENDING)
	        		continue;
	        		
				$zone_add_failed = false;
				
	        	foreach ($nameservers as $host=>$nameserver)
            	{
            		$this->Logger->info("Adding zone '{$zone["zone"]}' to '{$host}'");
            			
            		$add_status = $nameserver->AddZone($zone["zone"]);
					
					if (!$add_status)
					{
						$this->Logger->fatal("Cannot add zone to named.conf on '{$host}'");
						foreach ($GLOBALS["warnings"] as $warn)
                            $this->Logger->error("{$warn}");
                            
                        $zone_add_failed = true;
            			break;
					}
            	}
            	
	        	// If zone successfully added to nameservers - update db
            	if (!$zone_add_failed)
            	{
            		$this->Logger->info("Zone '{$zone["zone"]}' successfully added to nameservers");
            		
            		$farmstatus = $db->GetOne("SELECT status FROM farms WHERE id='{$zone['farmid']}'");
            		$zonestatus = ($farmstatus == 1) ? ZONE_STATUS::ACTIVE : ZONE_STATUS::INACTIVE;
            		
            		$reload_bind = true;
            		
            		$db->Execute("UPDATE zones SET status=? WHERE id=?", array($zonestatus, $zone['id']));
            	}
            	else
            		TaskQueue::Attach(QUEUE_NAME::CREATE_DNS_ZONE)->Put($Task);
	        }
			
	        while ($Task = TaskQueue::Attach(QUEUE_NAME::DELETE_DNS_ZONE)->Poll())
	        {	        	
	        	$zone = $db->GetRow("SELECT * FROM zones WHERE id=?", array($Task->ZoneID));
	        	if ($zone["status"] != ZONE_STATUS::DELETED)
	        		continue;
	        		
	        	$zone_remove_failed = false;
	        	
	        	foreach ($nameservers as $host=>$nameserver)
            	{
            		$remove_status = $nameserver->DeleteZone($zone["zone"], false);
					if(!$remove_status)
					{
						$this->Logger->fatal("Cannot remove zone from named.conf on '{$host}'");
						
						$zone_remove_failed = true;
            			break;
					}         		
            	} // foreach nameservers
            	
	        	// If zone successfully deleted from nameservers - update db
            	if (!$zone_remove_failed)
            	{
            		$this->Logger->info("DNS zone '{$zone["zone"]}' deleted from database!");
            		
            		$reload_bind = true;
            		
           			$db->Execute("DELETE from zones WHERE id='{$zone['id']}'");
   					$db->Execute("DELETE from records WHERE zoneid='{$zone['id']}'");
            	}
            	else
            		TaskQueue::Attach(QUEUE_NAME::DELETE_DNS_ZONE)->Put($Task);
	        }

	        if ($reload_bind)
	        {
	            // run rndc reload
	            foreach ($nameservers as $host=>$nameserver)
	            {
	            	$this->Logger->info("Reloading bind on '{$host}'!");
	            	$res = $nameserver->ReloadRndc();
	            	$this->Logger->info("RNDC reload result: {$res}");
	            }
	        }
        }
        
        public function OnEndForking()
        {
            
        }
        
        public function StartThread($farminfo)
        {
            
        }
    }
?>