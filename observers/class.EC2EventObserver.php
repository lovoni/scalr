<?php
	class EC2EventObserver extends EventObserver
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
			$farminfo = $this->DB->GetRow("SELECT * FROM farms WHERE id=?", array($this->FarmID));
						
			try
			{
				// If we need replace old instance to new one
				if ($event->DBInstance->ReplaceIID)
				{
					$this->Logger->debug("Going to termination old instance...");
					
					$EC2Client = $this->GetAmazonEC2ClientObject($farminfo['region']);
					
					$this->DB->Execute("UPDATE farm_instances SET replace_iid='' WHERE id='{$event->DBInstance->ID}'");

					// Get information about replacement instance from database
					$old_instance = $this->DB->GetRow("SELECT * FROM farm_instances WHERE instance_id='{$event->DBInstance->ReplaceIID}'");

					// Update elastic IP
					$this->DB->Execute("UPDATE elastic_ips SET state='1', instance_id=? WHERE instance_id=? AND farmid=?",
						array($event->DBInstance->InstanceID, $old_instance['instance_id'], $this->FarmID)
					);
					
					$this->Logger->debug("Old instance: {$old_instance['id']}.");

					if ($old_instance)
					{
						Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($old_instance["farmid"], "Scheduled termination for instance '{$old_instance["instance_id"]}' ({$old_instance["external_ip"]}). It will be terminated in 3 minutes."));
						Scalr::FireEvent($old_instance["farmid"], new BeforeHostTerminateEvent(DBInstance::LoadByID($old_instance['id']), true));
					}
				}
			}
			catch (Exception $e)
			{
				$this->Logger->fatal($e->getMessage());
			}			
		}
		
		/**
		 * Update database when 'rebundleFail' event recieved from instance
		 *
		 * @param RebundleFailedEvent $event
		 * 
		 */
		public function OnRebundleFailed(RebundleFailedEvent $event)
		{
			$farminfo = $this->DB->GetRow("SELECT * FROM farms WHERE id=?", array($this->FarmID));
			
			$EC2Client = $this->GetAmazonEC2ClientObject($farminfo['region']);
			
			if ($event->DBInstance->State == INSTANCE_STATE::PENDING_TERMINATE)
			{
				try
    			{    				
    				$this->Logger->info("Terminating '{$event->DBInstance->InstanceID}' instance.");
    				
    				$response = $EC2Client->TerminateInstances(array($event->DBInstance->InstanceID));
    					
    				if ($response instanceof SoapFault)
    					$this->Logger->warn($response->faultstring);
    			}
    			catch (Exception $e)
    			{
    				$this->Logger->error($e->getMessage()); 
    			}
			}
		}
		
		/**
		 * Update database when 'newAMI' event recieved from instance
		 *
		 * @param RebundleCompleteEvent $event
		 * 
		 */
		public function OnRebundleComplete(RebundleCompleteEvent $event)
		{
			$farminfo = $this->DB->GetRow("SELECT * FROM farms WHERE id=?", array($this->FarmID));
			
			$EC2Client = $this->GetAmazonEC2ClientObject($farminfo['region']);

			if ($event->DBInstance->State == INSTANCE_STATE::PENDING_TERMINATE)
			{
				try
    			{    				    				
    				$response = $EC2Client->TerminateInstances(array($event->DBInstance->InstanceID));
    					
    				if ($response instanceof SoapFault)
    					$this->Logger->warn($response->faultstring);
    			}
    			catch (Exception $e)
    			{
    				$this->Logger->error($e->getMessage()); 
    			}
			}
		}
				
		/**
		 * Terminate all running instance on farm
		 *
		 * @param FarmTerminatedEvent $event
		 */
		public function OnFarmTerminated(FarmTerminatedEvent $event)
		{
			$farminfo = $this->DB->GetRow("SELECT * FROM farms WHERE id=?", array($this->FarmID));
			
			$EC2Client = $this->GetAmazonEC2ClientObject($farminfo['region']);
						
			if ($farminfo['status'] == FARM_STATUS::SYNCHRONIZING)
			{
				// Do not terminate pending terminate instances.
				$instances = $this->DB->GetAll("SELECT * FROM farm_instances WHERE farmid=? AND state NOT IN (?,?)", 
					array($this->FarmID, INSTANCE_STATE::PENDING_TERMINATE, INSTANCE_STATE::TERMINATED)
				);
			}
			else
			{
				$instances = $this->DB->GetAll("SELECT * FROM farm_instances WHERE farmid=?", 
					array($this->FarmID)
				);
			}
			                    
		    if (count($instances) == 0)
		    	return;
		    	
            foreach ($instances as $instance)
            {                
                try
    			{    				
    				$this->Logger->info("Terminating '{$instance["instance_id"]}' instance. (Farm: {$instance['farmid']})");
    				
    				$response = $EC2Client->TerminateInstances(array($instance["instance_id"]));
    					
    				if ($response instanceof SoapFault)
    					$this->Logger->warn($response->faultstring);
    			}
    			catch (Exception $e)
    			{
    				$this->Logger->error($e->getMessage()); 
    			}
            }
		    
		    $this->DB->Execute("UPDATE farm_instances SET state = ? WHERE farmid=? AND state=?", 
				array(INSTANCE_STATE::TERMINATED, $this->FarmID, INSTANCE_STATE::PENDING)
			);
		}
		
		public function OnBeforeHostTerminate(BeforeHostTerminateEvent $event)
		{
			if ($event->ForceTerminate)
			{ 
				$DBFarm = DBFarm::LoadByID($this->FarmID);
				$AmazonEC2Client = $this->GetAmazonEC2ClientObject($DBFarm->Region);
				
				Logger::getLogger(LOG_CATEGORY::FARM)->warn(new FarmLogMessage($this->FarmID, "Terminating instance '{$event->DBInstance->InstanceID}' (O)."));
                $AmazonEC2Client->TerminateInstances(array($event->DBInstance->InstanceID));
			}
		}
	}
?>