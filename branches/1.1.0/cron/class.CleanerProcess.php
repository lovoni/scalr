<?
	class CleanerProcess implements IProcess
    {
        public $ThreadArgs;
        public $ProcessDescription = "Clean garbage from garbage queue";
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
            
            
            /** Clear zomby database instances **/
            /*
            $farms = $db->Execute("SELECT * FROM farms WHERE status=?", array(FARM_STATUS::TERMINATED));
            while($farm = $farms->FetchRow())
            {
            	$instances = $db->GetAll("SELECT * FROM farm_instances WHERE farmid=? AND state = ?", 
            		array($farm['id'], INSTANCE_STATE::RUNNING)
            	);
            	
            	if (count($instances) != 0)
            	{
            		$this->Logger->warn("Found ".count($instances)." zomby instances in database for farm ID {$farm['id']}.");
            		foreach ($instances as $instance)
            		{
            			$this->Logger->warn("Removing zomby record '{$instance['instance_id']}' ('{$instance['external_ip']}') from database");
            			Scalr::FireEvent($instance['farmid'], new HostDownEvent($instance));
            		}
            	}
            }
            */
                        
            /** Process garbage queue **/
            $queue = $db->Execute("SELECT * FROM garbage_queue");
            while ($queue_item = $queue->FetchRow())
            {
            	$Client = Client::Load($queue_item['clientid']);
	        	
	        				    
			    // Create Amazon s3 client object
			    $AmazonS3 = new AmazonS3($Client->AWSAccessKeyID, $Client->AWSAccessKey);
			    
			    $data = unserialize($queue_item['data']);
			    
			    if ($data['region'])
			    {
				    // Create AmazonEC2 cleint object
				    $AmazonEC2Client = AmazonEC2::GetInstance(AWSRegions::GetAPIURL($data['region']));
			    }
			    else
			    	$AmazonEC2Client = AmazonEC2::GetInstance();
			    	 
			    $AmazonEC2Client->SetAuthKeys($Client->AWSPrivateKey, $Client->AWSCertificate);
			    
			    // Remove keypairs
			    foreach ($data['keypairs'] as $keypair)
			    {
			    	if ($keypair != '')
			    	{
				    	try
				    	{
				    		$AmazonEC2Client->DeleteKeyPair($keypair);
				    	}
				    	catch(Excdeption $e)
				    	{
				    		$this->Logger->warn("Cannot remove keypair: {$keypair}. {$e->getMessage()}");
				    	}
			    	}
			    }
			    
			    // Remove backets
			    foreach ($data['buckets'] as $bucket)
			    {
			    	if ($bucket != '')
			    	{
				    	try
				    	{
					    	$items = $AmazonS3->ListBucket($bucket);
					    	foreach ($items as $item)
					    		$AmazonS3->DeleteObject($item->Key, $bucket);
							
					    	$AmazonS3->DeleteBucket($bucket);
				    	}
				    	catch(Exception $e)
				    	{
				    		$this->Logger->warn("Cannot remove bucket: {$bucket}. {$e->getMessage()}");
				    		continue;
				    	}
			    	}
			    }
			    
			    $db->Execute("DELETE FROM garbage_queue WHERE id=?", array($queue_item["id"]));
            }
        }
        
        public function OnEndForking()
        {
            
        }
        
        public function StartThread($arg)
        {
            
        }
    }
?>