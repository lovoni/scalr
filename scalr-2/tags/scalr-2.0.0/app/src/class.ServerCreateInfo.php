<?php

	class ServerCreateInfo
	{
		public $platform;
		
		/**
		 * 
		 * @var DBFarmRole
		 */
		public $dbFarmRole;
		public $index;
		public $remoteIp;
		public $localIp;
		public $clientId;		
		public $roleId;
		public $farmId;
		
		private $platformProps = array();
				
		private $properties;
		
		/**
		 * 
		 * @param string $platform (From SERVER_PLATFORMS class)
		 * @param integer $farmid
		 * @param integer $farm_roleid
		 * @param integer $index
		 * @return void
		 */
		public function __construct($platform, DBFarmRole $DBFarmRole, $index = null, $role_id = null)
		{
			$this->platform = $platform;
			$this->dbFarmRole = $DBFarmRole;
			$this->index = $index;
			$this->roleId = $role_id === null ? $this->dbFarmRole->RoleID : $role_id;
			
			//Refletcion
			$Reflect = new ReflectionClass(DBServer::$platformPropsClasses[$this->platform]);
			foreach ($Reflect->getConstants() as $k=>$v)
				$this->platformProps[] = $v;
				
			if ($DBFarmRole)
			{
				switch($this->platform)
				{
					case SERVER_PLATFORMS::EC2:
						$this->SetProperties(array(
							EC2_SERVER_PROPERTIES::AVAIL_ZONE => $DBFarmRole->GetSetting(DBFarmRole::SETTING_AWS_AVAIL_ZONE),
							EC2_SERVER_PROPERTIES::REGION => $DBFarmRole->GetFarmObject()->Region
						));
					break;
					case SERVER_PLATFORMS::RDS:
						$this->SetProperties(array(
							RDS_SERVER_PROPERTIES::AVAIL_ZONE => $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_AVAIL_ZONE),
							RDS_SERVER_PROPERTIES::REGION => $DBFarmRole->GetFarmObject()->Region,
							RDS_SERVER_PROPERTIES::INSTANCE_CLASS => $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_INSTANCE_CLASS),
							RDS_SERVER_PROPERTIES::STORAGE	=> $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_STORAGE),
							RDS_SERVER_PROPERTIES::INSTANCE_ENGINE => $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_INSTANCE_ENGINE),
							RDS_SERVER_PROPERTIES::MASTER_USER => $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_MASTER_USER),
							RDS_SERVER_PROPERTIES::MASTER_PASS => $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_MASTER_PASS),	
							RDS_SERVER_PROPERTIES::MULTI_AZ	=> $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_MULTI_AZ),		
							RDS_SERVER_PROPERTIES::PORT	=> $DBFarmRole->GetSetting(DBFarmRole::SETTING_RDS_PORT)
						));
					break;
				}
			}
		}
		
		public function SetProperties(array $props)
		{
			foreach($props as $k=>$v)
			{
				if (in_array($k, $this->platformProps))
					$this->properties[$k] = $v;
				else
					throw new Exception(sprintf("Unknown property '%s' for server on '%s'", $k, $this->platform));
			}	
		}
		
		public function GetProperty($propName)
		{
			if (in_array($propName, $this->platformProps))
				return $this->properties[$propName];
			else
				throw new Exception(srpintf("Unknown property '%s' for server on '%s'", $name, $this->platform));
		}
		
		public function GetProperties()
		{
			return $this->properties;
		}
	}
	
?>