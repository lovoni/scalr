<?php

	class Scalr_Cronjob_Cleaner extends Scalr_System_Cronjob
    {
        static function getConfig () {
        	return array(
        		"description" => "Clean garbage from garbage queue"
        	);
        }
    	
        private $logger;
        
    	function __construct() {
        	$this->logger = LoggerManager::getLogger(__CLASS__);
        }
        
        function run ($options)
        {
            $db = Core::GetDBInstance();
            $crypto = Core::GetInstance("Crypto", CONFIG::$CRYPTOKEY);
            
            $cpwd = $crypto->Decrypt(@file_get_contents(dirname(__FILE__)."/../../etc/.passwd"));
            
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
				    		$this->logger->warn("Cannot remove keypair: {$keypair}. {$e->getMessage()}");
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
				    		$this->logger->warn("Cannot remove bucket: {$bucket}. {$e->getMessage()}");
				    		continue;
				    	}
			    	}
			    }
			    
			    $db->Execute("DELETE FROM garbage_queue WHERE id=?", array($queue_item["id"]));
            }
        }
    }
