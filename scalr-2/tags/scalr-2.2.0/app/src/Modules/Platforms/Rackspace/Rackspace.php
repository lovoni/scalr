<?php
	class Modules_Platforms_Rackspace implements IPlatformModule
	{
		private $db;
		
		/** Properties **/
		const USERNAME 		= 'rackspace.username';
		const API_KEY		= 'rackspace.api_key';
		
		private $instancesListCache = array();
		
		/**
		 * @return Scalr_Service_Cloud_Rackspace_CS
		 */
		private function getRsClient(Scalr_Environment $environment)
		{
			return Scalr_Service_Cloud_Rackspace::newRackspaceCS(
				$environment->getPlatformConfigValue(self::USERNAME),
				$environment->getPlatformConfigValue(self::API_KEY)
			);
		}
		
		public function __construct()
		{
			
		}
		
		public function getRoleBuilderBaseImages()
		{
			return array(
				'10'	=> array('name' => 'Ubuntu 8.04', 'os_dist' => 'ubuntu', 'location' => 'rs-ORD1', 'architecture' => 'x86_64'),
				'49'	=> array('name' => 'Ubuntu 10.04','os_dist' => 'ubuntu', 'location' => 'rs-ORD1', 'architecture' => 'x86_64'),
				'69'	=> array('name' => 'Ubuntu 10.10','os_dist' => 'ubuntu', 'location' => 'rs-ORD1', 'architecture' => 'x86_64'),
				'51'	=> array('name' => 'CentOS 5.5',  'os_dist' => 'centos', 'location' => 'rs-ORD1', 'architecture' => 'x86_64'),
				'62'	=> array('name' => 'RHEL 5.5',    'os_dist' => 'rhel',   'location' => 'rs-ORD1', 'architecture' => 'x86_64')
			);
		}
		
		public function getLocations()
		{
			return array(
				'rs-ORD1' => 'Rackspace / ORD1'
			);
		}
		
		public function getPropsList()
		{
			return array(
				self::USERNAME	=> 'Username',
				self::API_KEY	=> 'API Key',
			);
		}
		
		public function GetServerCloudLocation(DBServer $DBServer)
		{
			return $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::DATACENTER);
		}
		
		public function GetServerID(DBServer $DBServer)
		{
			return $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID);
		}
		
		public function IsServerExists(DBServer $DBServer, $debug = false)
		{
			return in_array(
				$DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID), 
				array_keys($this->GetServersList($DBServer->GetEnvironmentObject(), $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::DATACENTER)))
			);
		}
		
		public function GetServerIPAddresses(DBServer $DBServer)
		{
			$rsClient = $this->getRsClient($DBServer->GetEnvironmentObject());
			
			$result = $rsClient->getServerDetails($DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID));
		    
		    return array(
		    	'localIp'	=> $result->server->addresses->private[0],
		    	'remoteIp'	=> $result->server->addresses->public[0]
		    );
		}
		
		public function GetServersList(Scalr_Environment $environment, $cloudLocation, $skipCache = false)
		{
			if (!$this->instancesListCache[$environment->id][$cloudLocation] || $skipCache)
			{
				$rsClient = $this->getRsClient($environment);
				
				$result = $rsClient->listServers(true);
				foreach ($result->servers as $server)
					$this->instancesListCache[$environment->id][$cloudLocation][$server->id] = $server->status;
			}
	        
			return $this->instancesListCache[$environment->id][$cloudLocation];
		}
		
		public function GetServerRealStatus(DBServer $DBServer)
		{
			$cloudLocation = $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::DATACENTER);
			$environment = $DBServer->GetEnvironmentObject();
			
			$iid = $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID);
			if (!$iid)
			{
				$status = 'not-found';
			}
			elseif (!$this->instancesListCache[$environment->id][$cloudLocation][$iid])
			{
		        $rsClient = $this->getRsClient($environment);
				
		        try {
					$result = $rsClient->getServerDetails($DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID));
					$status = $result->server->status;
		        }
		        catch(Exception $e)
		        {
		        	if (stristr($e->getMessage(), "404"))
		        		$status = 'not-found';
		        }
			}
			else
			{
				$status = $this->instancesListCache[$environment->id][$cloudLocation][$DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID)];
			}
			
			return Modules_Platforms_Rackspace_Adapters_Status::load($status);
		}
		
		public function TerminateServer(DBServer $DBServer)
		{
			$rsClient = $this->getRsClient($DBServer->GetEnvironmentObject());
	        
	        $rsClient->deleteServer($DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID));
	        
	        return true;
		}
		
		public function RebootServer(DBServer $DBServer)
		{
			$rsClient = $this->getRsClient($DBServer->GetEnvironmentObject());
	        
	        $rsClient->rebootServer($DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID));
	        
	        return true;
		}
		
		public function RemoveServerSnapshot(DBRole $DBRole)
		{
			$rsClient = $this->getRsClient($DBRole->getEnvironmentObject());
			
			foreach ($DBRole->getImageId(SERVER_PLATFORMS::RACKSPACE) as $location => $imageId) {
				
				try {
					$rsClient->deleteImage($imageId);
				}
				catch(Exception $e)
				{
					if (stristr($e->getMessage(), "Cannot destroy a destroyed snapshot"))
						return true;
					else
						throw $e;
				}
			}
			
			return true;
		}
		
		public function CheckServerSnapshotStatus(BundleTask $BundleTask)
		{
			
		}
		
		public function CreateServerSnapshot(BundleTask $BundleTask)
		{
			$DBServer = DBServer::LoadByID($BundleTask->serverId);
        	$BundleTask->status = SERVER_SNAPSHOT_CREATION_STATUS::IN_PROGRESS;
        	$BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::RS_CFILES;
    	
        	$msg = new Scalr_Messaging_Msg_Rebundle(
        		$BundleTask->id,
				$BundleTask->roleName,
				array()
        	);

        	if (!$DBServer->SendMessage($msg))
        	{
        		$BundleTask->SnapshotCreationFailed("Cannot send rebundle message to server. Please check event log for more details.");
        		return;
        	}
        	else
        	{
	        	$BundleTask->Log(sprintf(_("Snapshot creating initialized (MessageID: %s). Bundle task status changed to: %s"), 
	        		$msg->messageId, $BundleTask->status
	        	));
        	}
			
			$BundleTask->setDate('started');
        	$BundleTask->Save();        	
		}
		
		private function ApplyAccessData(Scalr_Messaging_Msg $msg)
		{
			
			
		}
		
		public function GetServerConsoleOutput(DBServer $DBServer)
		{
			throw new Exception("Not supported by Rackspace");
		}
		
		public function GetServerExtendedInformation(DBServer $DBServer)
		{
			try
			{
				try	{
					$rsClient = $this->getRsClient($DBServer->GetEnvironmentObject());
					$iinfo = $rsClient->getServerDetails($DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID));
				}
				catch(Exception $e){}
	
		        if ($iinfo)
		        {
			        return array(
			        	'Server ID'				=> $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID),
			        	'Image ID'				=> $iinfo->server->imageId,
			        	'Flavor ID'				=> $iinfo->server->flavorId,
			        	'Public IP'				=> implode(", ", $iinfo->server->addresses->public),
			        	'Private IP'			=> implode(", ", $iinfo->server->addresses->private),
			        	'Status'				=> $iinfo->server->status,
			        	'Name'					=> $iinfo->server->name,
			        	'Host ID'				=> $iinfo->server->hostId,
			        	'Progress'				=> $iinfo->server->progress
			        );
		        }
			}
			catch(Excpetion $e){}
			
			return false;
		}
		
		/**
		 launchOptions: imageId
		 */
		public function LaunchServer(DBServer $DBServer, Scalr_Server_LaunchOptions $launchOptions = null)
		{
			$rsClient = $this->getRsClient($DBServer->GetEnvironmentObject());
	        
			if (!$launchOptions)
			{
				$launchOptions = new Scalr_Server_LaunchOptions();
				$DBRole = DBRole::loadById($DBServer->roleId);
				
				$launchOptions->imageId = $DBRole->getImageId(SERVER_PLATFORMS::RACKSPACE, $DBServer->GetProperty(RACKSPACE_SERVER_PROPERTIES::DATACENTER));
				$launchOptions->serverType = $DBServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_RS_FLAVOR_ID);
   				$launchOptions->cloudLocation = $DBServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_CLOUD_LOCATION);
				
				foreach ($DBServer->GetCloudUserData() as $k=>$v)
	        		$u_data .= "{$k}={$v};";
				
				$launchOptions->userData = trim($u_data, ";");
				
				$launchOptions->architecture = 'x86_64';
			}
			
			$result = $rsClient->createServer(
				$DBServer->serverId,
				$launchOptions->imageId,
				$launchOptions->serverType,
				array(),
				array(
					'path'		=> '/etc/scalr/private.d/.user-data',
					'contents'	=> base64_encode($launchOptions->userData)
				)
			);
	        
	        if ($result->server)
	        {
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::SERVER_ID, $result->server->id);
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::IMAGE_ID, $result->server->imageId);
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::FLAVOR_ID, $result->server->flavorId);
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::ADMIN_PASS, $result->server->adminPass);
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::NAME, $DBServer->serverId);
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::HOST_ID, $result->server->hostId);
	        	
	        	$DBServer->SetProperty(SERVER_PROPERTIES::ARCHITECTURE, $launchOptions->architecture);
	        	
	        	$DBServer->SetProperty(RACKSPACE_SERVER_PROPERTIES::DATACENTER, $launchOptions->cloudLocation);
	        	
		        return $DBServer;
	        }
	        else 
	            throw new Exception(sprintf(_("Cannot launch new instance. %s"), $result->faultstring));
		}
		
		public function PutAccessData(DBServer $DBServer, Scalr_Messaging_Msg $message)
		{
			$put = false;
			$put |= $message instanceof Scalr_Messaging_Msg_Rebundle;
			$put |= $message instanceof Scalr_Messaging_Msg_HostInitResponse && $DBServer->GetFarmRoleObject()->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::MYSQL);
			$put |= $message instanceof Scalr_Messaging_Msg_Mysql_PromoteToMaster;
			$put |= $message instanceof Scalr_Messaging_Msg_Mysql_NewMasterUp;
			$put |= $message instanceof Scalr_Messaging_Msg_Mysql_CreateDataBundle;
			$put |= $message instanceof Scalr_Messaging_Msg_Mysql_CreateBackup;
			
			
			if ($put) {
				$environment = $DBServer->GetEnvironmentObject();
	        	$accessData = new stdClass();
	        	$accessData->username = $environment->getPlatformConfigValue(self::USERNAME);
	        	$accessData->apiKey = $environment->getPlatformConfigValue(self::API_KEY);
	        	
	        	$message->platformAccessData = $accessData;
			}
			
		}
		
		public function ClearCache ()
		{
			$this->instancesListCache = array();
		}
	}

	
	
?>