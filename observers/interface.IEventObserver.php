<?php

	interface IEventObserver
	{
		public function OnHostInit($instanceinfo, $local_ip, $remote_ip, $public_key);
		
		public function OnHostUp($instanceinfo);
		
		public function OnHostDown($instanceinfo);
		
		public function OnHostCrash($instanceinfo);
				
		public function OnLAOverMaximum($roleinfo, $LA, $MAX_LA);
		
		public function OnLAUnderMinimum($roleinfo, $LA, $MIN_LA);
		
		public function OnRebundleComplete($ami_id, $instanceinfo);
		
		public function OnRebundleFailed($instanceinfo);
		
		public function OnRebootBegin($instanceinfo);
		
		public function OnRebootComplete($instanceinfo);
		
		public function OnFarmLaunched($mark_instances_as_active);
		
		public function OnFarmTerminated($remove_zone_from_DNS, $keep_elastic_ips, $term_on_sync_fail);
		
		public function OnNewMysqlMasterUp($instanceinfo, $snapurl);
		
		public function OnMysqlBackupComplete($operation);
		
		public function OnMysqlBackupFail($operation);
		
		public function OnIPAddressChanged($instanceinfo, $new_ip_address);
		
		public function OnMySQLReplicationFail($instanceinfo);
		
		public function OnMySQLReplicationRecovered($instanceinfo);
	}
?>