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
            $db = Core::GetDBInstance(null, true);
            
            $cpwd = $this->Crypto->Decrypt(@file_get_contents(dirname(__FILE__)."/../etc/.passwd"));
            
            
            $queue = $db->Execute("SELECT * FROM garbage_queue");
            while ($queue_item = $queue->FetchRow())
            {
            	$clientinfo = $db->GetRow("SELECT * FROM clients WHERE id=?", 
            		array($queue_item['clientid'])
            	);
            	
            	// Decrypt keys
            	$private_key = $this->Crypto->Decrypt($clientinfo["aws_private_key_enc"], $cpwd);
    			$certificate = $this->Crypto->Decrypt($clientinfo["aws_certificate_enc"], $cpwd);
    			
    			$aws_accesskey = $this->Crypto->Decrypt($clientinfo["aws_accesskey"], $cpwd);
	        	$aws_accesskeyid = $this->Crypto->Decrypt($clientinfo["aws_accesskeyid"], $cpwd);
	        	
	        	// Create AmazonEC2 cleint object
			    $AmazonEC2Client = new AmazonEC2($private_key, $certificate);
			    
			    // Create Amazon s3 client object
			    $AmazonS3 = new AmazonS3($aws_accesskeyid, $aws_accesskey);
			    
			    $data = unserialize($queue_item['data']);
			    
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