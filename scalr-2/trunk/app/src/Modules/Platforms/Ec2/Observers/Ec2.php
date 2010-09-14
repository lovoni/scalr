<?php
	class Modules_Platforms_Ec2_Observers_Ec2 extends EventObserver
	{
		public $ObserverName = 'EC2';
		
		function __construct()
		{
			parent::__construct();
			
			$this->Crypto = Core::GetInstance("Crypto", CONFIG::$CRYPTOKEY);
		}

		/**
		 * Return new instance of AmazonEC2 object
		 *
		 * @return AmazonEC2
		 */
		private function GetAmazonEC2ClientObject($region)
		{
	    	// Get ClientID from database;
			$clientid = $this->DB->GetOne("SELECT clientid FROM farms WHERE id=?", array($this->FarmID));
			
			// Get Client Object
			$Client = Client::Load($clientid);
	
	    	// Return new instance of AmazonEC2 object
			$AmazonEC2Client = AmazonEC2::GetInstance(AWSRegions::GetAPIURL($region)); 
			$AmazonEC2Client->SetAuthKeys($Client->AWSPrivateKey, $Client->AWSCertificate);
			
			return $AmazonEC2Client;
		}
				
		public function OnHostUp(HostUpEvent $event)
		{
			if ($event->DBServer->platform != SERVER_PLATFORMS::EC2)
				return;
					
			try
			{
				// If we need replace old instance to new one terminate old one.
				if ($event->DBServer->replaceServerID)
				{
					Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($this->FarmID, "Host UP. Terminating old server: {$event->DBServer->replaceServerID})."));
					
					try {
						$oldDBServer = DBServer::LoadByID($event->DBServer->replaceServerID);
					}
					catch(Exception $e) {}

					Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($this->FarmID, "OLD Server found: {$oldDBServer->serverId})."));
					
					if ($oldDBServer)
						Scalr::FireEvent($oldDBServer->farmId, new BeforeHostTerminateEvent($oldDBServer));
				}
			}
			catch (Exception $e)
			{
				$this->Logger->fatal($e->getMessage());
			}			
		}
				
		/**
		 * Terminate all running instance on farm
		 *
		 * @param FarmTerminatedEvent $event
		 */
		public function OnFarmTerminated(FarmTerminatedEvent $event)
		{			
			$DBDarm = DBFarm::LoadByID($this->FarmID);
									
			if ($DBDarm->Status == FARM_STATUS::SYNCHRONIZING)
				$servers = $DBDarm->GetServersByFilter(array('platform' => SERVER_PLATFORMS::EC2), array('status' => array(SERVER_STATUS::PENDING_TERMINATE, SERVER_STATUS::TERMINATED)));
			else
				$servers = $DBDarm->GetServersByFilter(array('platform' => SERVER_PLATFORMS::EC2));
			                    
		    if (count($servers) == 0)
		    	return;
		    
		    // TERMINATE RUNNING INSTANCES
		    $EC2Client = $this->GetAmazonEC2ClientObject($DBDarm->Region);
            foreach ($servers as $DBServer)
            {                
                if ($this->DB->GetOne("SELECT id FROM bundle_tasks WHERE server_id=? AND status NOT IN ('success','failed')", array($DBServer->serverId)))
                	continue;
                
            	if ($DBServer->status != SERVER_STATUS::PENDING_LAUNCH)
                {
	            	try {    				
	    				$response = $EC2Client->TerminateInstances(array($DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID)));
	    					
	    				if ($response instanceof SoapFault)
	    					$this->Logger->warn($response->faultstring);
	    					
	    				if ($DBServer->status != SERVER_STATUS::TERMINATED)
	    				{
		    				$this->DB->Execute("UPDATE servers_history SET
								dtterminated	= NOW(),
								terminate_reason	= ?
								WHERE server_id = ?
							", array(
								sprintf("Farm was terminated"),
								$DBServer->serverId
							));
	    				}
	    			}
	    			catch (Exception $e) {
	    				$this->Logger->error($e->getMessage()); 
	    			}
                }
    			
                if ($DBServer->status == SERVER_STATUS::PENDING || $DBServer->status == SERVER_STATUS::PENDING_LAUNCH)
    			{
    				$DBServer->status = SERVER_STATUS::TERMINATED;
    				$DBServer->Save();
    			}
            }
		}
		
		public function OnBeforeHostTerminate(BeforeHostTerminateEvent $event)
		{
			if ($event->DBServer->platform != SERVER_PLATFORMS::EC2)
				return;
			
			if ($event->ForceTerminate)
			{ 
				$DBFarm = DBFarm::LoadByID($this->FarmID);
				$AmazonEC2Client = $this->GetAmazonEC2ClientObject($DBFarm->Region);
				
				$instance_id = $event->DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID);
				
				Logger::getLogger(LOG_CATEGORY::FARM)->warn(new FarmLogMessage($this->FarmID, "Terminating instance '{$instance_id}' (O)."));
                $AmazonEC2Client->TerminateInstances(array($instance_id));
			}
		}
	}
?>