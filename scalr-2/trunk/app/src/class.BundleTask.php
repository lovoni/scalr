<?php
	
	class BundleTask
	{			
		public $id;
		public $clientId;
		public $serverId;
		public $replaceType;
		public $prototypeRoleId;
		public $status;
		public $platform;
		public $roleName;
		public $failureReason;
		public $bundleType;
		public $removePrototypeRole;
		public $dateAdded;
		public $dateStarted;
		public $dateFinished;
		public $snapshotId;
		public $description;
		public $roleId;
		public $farmId;
				
		private $Db;
		private $tz;
		
		private static $FieldPropertyMap = array(
			'id'			=> 'id',
			'client_id'		=> 'clientId',
			'prototype_role_id'	=> 'prototypeRoleId',
			'server_id' 	=> 'serverId',
			'replace_type' 	=> 'replaceType',
			'status'		=> 'status',
			'platform'		=> 'platform',
			'rolename'		=> 'roleName',
			'failure_reason'=> 'failureReason',
			'remove_proto_role'	=> 'removePrototypeRole',
			'bundle_type'	=> 'bundleType',
			'dtadded'		=> 'dateAdded',
			'dtstarted'		=> 'dateStarted',
			'dtfinished'	=> 'dateFinished',
			'snapshot_id'	=> 'snapshotId',
			'description'	=> 'description',
			'role_id'		=> 'roleId',
			'farm_id'		=> 'farmId'
		);
		
		public function __construct($id)
		{
			$this->id = $id;
			$this->Db = Core::GetDBInstance();
		}
		
		public function Log($message)
		{
			if ($this->id)
			{
				try
				{
					$this->Db->Execute("INSERT INTO bundle_task_log SET
						bundle_task_id	= ?,
						dtadded			= NOW(),
						message			= ?
					", array($this->id, $message));
				}
				catch(ADODB_Exception $e){}
			}
		}
		
		public function setDate($dt)
		{
			if (!$this->tz)
			{
				$Client = Client::Load($this->clientId);
    			$this->tz = $Client->GetSettingValue(CLIENT_SETTINGS::TIMEZONE);
			}
			
    		if ($this->tz)
    		{
	    		$tz = date_default_timezone_get();
    			date_default_timezone_set($this->tz);
    		}
			
			switch ($dt)
			{
				case "finished":
					
					$this->dateFinished = date("Y-m-d H:i:s");
					
					break;
					
				case "added":
					
					$this->dateAdded = date("Y-m-d H:i:s");
					
					break;
					
				case "started":
					
					$this->dateStarted = date("Y-m-d H:i:s");
					
					break;
			}
			
			if ($tz)
				date_default_timezone_set($tz);
		}
		
		public static function GenerateRoleName($DBFarmRole, $DBServer)
		{
			$db = Core::GetDBInstance();
			
			$n = $DBFarmRole->GetRoleName();
			preg_match('/^([A-Za-z0-9-]+)-([0-9]+)-([0-9]+)$/si', $n, $m);
			if ($m[0] == $n)
			{
				if (date("Ymd") != $m[2])
				{
					$name = "{$m[1]}-".date("Ymd")."-01";
					$i = 1;
				}
				else
				{
					$s = $m[3]++;
					$i = $s;
					$s = ($s < 10) ? "0{$s}" : $s;
					$name = "{$m[1]}-{$m[2]}-{$s}";
				}
			}
			else
			{
				$name = "{$n}-".date("Ymd")."-01";
				$i = 1;
			}
			
            $role = $db->GetOne("SELECT id FROM roles WHERE name=? AND clientid=?", array($name, $DBServer->clientId));
            if ($role)
            {
                while ($role)
                {
               		$i++;
                	preg_match('/^([A-Za-z0-9-]+)-([0-9]+)-([0-9]+)$/si', $name, $m);
					$s = ($i < 10) ? "0{$i}" : $i;
					$name = "{$m[1]}-{$m[2]}-{$s}";
                        
                    $role = $db->GetOne("SELECT id FROM roles WHERE name=? AND clientid=?", array($name, $DBServer->clientId));                    
                }
            }
            
            return $name;
		}
		
		public function SnapshotCreationComplete($snapshotId)
		{
			$this->snapshotId = $snapshotId;
			$this->status = SERVER_SNAPSHOT_CREATION_STATUS::CREATING_ROLE;
			
			$this->Log(sprintf(_("Snapshot creation complete. SnapshotID: '%s'. Bundle task status changed to: %s"), 
				$snapshotId, $this->status
			));
			
			$this->Save();
		}
		
		public function SnapshotCreationFailed($failed_reason)
		{
			$this->status = SERVER_SNAPSHOT_CREATION_STATUS::FAILED;
			$this->failureReason = $failed_reason;
			
			if ($this->farmId)
			{
				$DBFarm = DBFarm::LoadByID($this->farmId);
				if ($DBFarm->Status == FARM_STATUS::SYNCHRONIZING && !$DBFarm->TermOnSyncFail)
				{
					$this->Db->Execute("UPDATE farms SET status=? WHERE id=?", array(
						FARM_STATUS::RUNNING,
						$this->farmId
					));
				}
			}
			
			$this->Log(sprintf(_("Snapshot creation failed. Reason: %s. Bundle task status changed to: %s"), $failed_reason, $this->status));
			
			$this->Save();
		}
		
		private function Unbind () {
			$row = array();
			foreach (self::$FieldPropertyMap as $field => $property) {
				$row[$field] = $this->{$property};
			}
			
			return $row;		
		}
		
		function Save () {
				
			$row = $this->Unbind();
			unset($row['id']);
			
			// Prepare SQL statement
			$set = array();
			$bind = array();
			foreach ($row as $field => $value) {
				$set[] = "`$field` = ?";
				$bind[] = $value;
			}
			$set = join(', ', $set);
	
			try	{
				// Perform Update
				$bind[] = $this->id;
				$this->Db->Execute("UPDATE bundle_tasks SET $set WHERE id = ?", $bind);
				
			} catch (Exception $e) {
				throw new Exception ("Cannot save bundle task. Error: " . $e->getMessage(), $e->getCode());			
			}
		}
		
		/**
		 * 
		 * @param ServerSnapshotCreateInfo $ServerSnapshotCreateInfo
		 * @return BundleTask
		 */
		public static function Create(ServerSnapshotCreateInfo $ServerSnapshotCreateInfo)
		{
			$db = Core::GetDBInstance();
			
			$db->Execute("INSERT INTO bundle_tasks SET
				client_id	= ?,
				server_id	= ?,
				farm_id		= ?,
				prototype_role_id	= ?,
				replace_type		= ?,
				remove_proto_role	= ?,
				status		= ?,
				platform	= ?,
				rolename	= ?,
				description	= ?
			", array(
				$ServerSnapshotCreateInfo->DBServer->clientId,
				$ServerSnapshotCreateInfo->DBServer->serverId,
				$ServerSnapshotCreateInfo->DBServer->farmId,
				$ServerSnapshotCreateInfo->DBServer->roleId,
				$ServerSnapshotCreateInfo->replaceType,
				(int)$ServerSnapshotCreateInfo->removePrototypeRole,
				SERVER_SNAPSHOT_CREATION_STATUS::PENDING,
				$ServerSnapshotCreateInfo->DBServer->platform,
				$ServerSnapshotCreateInfo->roleName,
				$ServerSnapshotCreateInfo->description
			));

			$bundleTaskId = $db->Insert_Id();
			
			$task = self::LoadById($bundleTaskId);
			
			$task->setDate('added');
			
			$task->save();
			
			$task->Log(sprintf(_("Bundle task created. ServerID: %s, FarmID: %s, Platform: %s."), 
				$ServerSnapshotCreateInfo->DBServer->serverId,
				($ServerSnapshotCreateInfo->DBServer->farmId) ? $ServerSnapshotCreateInfo->DBServer->farmId : '-',
				$ServerSnapshotCreateInfo->DBServer->platform
			));
			
			$task->Log(sprintf(_("Bundle task status: %s"), 
				$task->status
			));
			
			try
			{
				$platformModule = PlatformFactory::NewPlatform($ServerSnapshotCreateInfo->DBServer->platform);
				$platformModule->CreateServerSnapshot($task);
			}
			catch(Exception $e)
			{	
				Logger::getLogger(LOG_CATEGORY::BUNDLE)->error($e->getMessage());
				$task->SnapshotCreationFailed($e->getMessage());
			}
			
			return $task;
		}
		
		/**
		 * 
		 * @param integer $id
		 * @return BundleTask
		 */
		public static function LoadById($id)
		{
			$db = Core::GetDBInstance();
			
			$taskinfo = $db->GetRow("SELECT * FROM bundle_tasks WHERE id=?", array($id));
			if (!$taskinfo)
				throw new Exception(sprintf(_("Bundle task ID#%s not found in database"), $id));
				
			$task = new BundleTask($id);
			foreach(self::$FieldPropertyMap as $k=>$v)
			{
				if (isset($taskinfo[$k]))
					$task->{$v} = $taskinfo[$k];
			}
			
			return $task;
		}
	}
?>