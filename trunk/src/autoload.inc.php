<?
	function __autoload($class_name)
	{
    	$paths = array(
    		/****************************** Basic Objects ***********************/
    		'Client'				=> SRCPATH.'/class.Client.php',
    		'Farm'					=> SRCPATH.'/class.Farm.php',
    		'DBEBSVolume'			=> SRCPATH.'/class.DBEBSVolume.php',
    		'DBEBSArray'			=> SRCPATH.'/class.DBEBSArray.php',
    		'AWSRegions'			=> SRCPATH.'/class.AWSRegions.php',
    		'DBInstance'			=> SRCPATH.'/class.DBInstance.php',
    		'XMLMessageSerializer'	=> SRCPATH.'/class.XMLMessageSerializer.php',
    		'DBFarmRole'			=> SRCPATH.'/class.DBFarmRole.php',
    	
    		/****************************** LIB **********************************/
    	
    		
    		/***************************** Scaling *******************************/
    		'IScalingAlgo'			=> SRCPATH.'/scaling/interface.IScalingAlgo.php',
    		'IScalingSensor'		=> SRCPATH.'/scaling/interface.IScalingSensor.php',
    	
    		'LAScalingAlgo'			=> SRCPATH.'/scaling/class.LAScalingAlgo.php',
    		'BWScalingAlgo'			=> SRCPATH.'/scaling/class.BWScalingAlgo.php',
    		'SQSScalingAlgo'		=> SRCPATH.'/scaling/class.SQSScalingAlgo.php',
    	
    		'LAScalingSensor'		=> SRCPATH.'/scaling/class.LAScalingSensor.php',
    		'BWScalingSensor'		=> SRCPATH.'/scaling/class.BWScalingSensor.php',
    		'SQSScalingSensor'		=> SRCPATH.'/scaling/class.SQSScalingSensor.php',
    	
    		'ScalingAlgo'			=> SRCPATH.'/scaling/class.ScalingAlgo.php',
    		'SensorFactory'			=> SRCPATH.'/scaling/class.SensorFactory.php',
    	
    		'RoleScalingManager'	=> SRCPATH.'/scaling/class.RoleScalingManager.php',
    	
    		/****************************** Messaging  ***************************/
    		'ScalrMessage'						=> SRCPATH.'/messaging/messages/abstract.ScalrMessage.php',
    		'EventNoticeScalrMessage'			=> SRCPATH.'/messaging/messages/class.EventNoticeScalrMessage.php',
    		'HostDownScalrMessage'				=> SRCPATH.'/messaging/messages/class.HostDownScalrMessage.php',
    		'HostInitScalrMessage'				=> SRCPATH.'/messaging/messages/class.HostInitScalrMessage.php',
    		'HostUpScalrMessage'				=> SRCPATH.'/messaging/messages/class.HostUpScalrMessage.php',
    		'MakeMySQLBackupScalrMessage'		=> SRCPATH.'/messaging/messages/class.MakeMySQLBackupScalrMessage.php',
    		'MakeMySQLDataBundleScalrMessage'	=> SRCPATH.'/messaging/messages/class.MakeMySQLDataBundleScalrMessage.php',
    		'MountPointsReconfigureScalrMessage'=> SRCPATH.'/messaging/messages/class.MountPointsReconfigureScalrMessage.php',
    		'NewMySQLMasterUpScalrMessage'		=> SRCPATH.'/messaging/messages/class.NewMySQLMasterUpScalrMessage.php',
    		'StartRebundleScalrMessage'			=> SRCPATH.'/messaging/messages/class.StartRebundleScalrMessage.php',
    		'VhostReconfigureScalrMessage'		=> SRCPATH.'/messaging/messages/class.VhostReconfigureScalrMessage.php',
    		'ScalarizrUpdateAvailableScalrMessage' => SRCPATH.'/messaging/messages/class.ScalarizrUpdateAvailableScalrMessage.php',
    	
    		/******************* Environment objects ****************************/
    		'ScalrEnvironmentFactory'	=> SRCPATH.'/class.ScalrEnvironmentFactory.php',
    		'ScalrEnvironment'			=> SRCPATH.'/class.ScalrEnvironment.php',
    		'ScalrRESTService'			=> SRCPATH.'/class.ScalrRESTService.php',
    		'ScalarizrCallbackService'  => SRCPATH.'/class.ScalarizrCallbackService.php',
    	
    		/****************************** Events ******************************/
    		'Event'					=> SRCPATH.'/events/abstract.Event.php',
    		'FarmLaunchedEvent' 	=> SRCPATH.'/events/class.FarmLaunchedEvent.php',
    		'FarmTerminatedEvent' 	=> SRCPATH.'/events/class.FarmTerminatedEvent.php',
    		'HostCrashEvent' 		=> SRCPATH.'/events/class.HostCrashEvent.php',
    		'HostDownEvent'			=> SRCPATH.'/events/class.HostDownEvent.php',
    		'HostInitEvent' 		=> SRCPATH.'/events/class.HostInitEvent.php',
    		'HostUpEvent'			=> SRCPATH.'/events/class.HostUpEvent.php',
    		'IPAddressChangedEvent'	=> SRCPATH.'/events/class.IPAddressChangedEvent.php',
    		'LAOverMaximumEvent'	=> SRCPATH.'/events/class.LAOverMaximumEvent.php',
    		'LAUnderMinimumEvent'	=> SRCPATH.'/events/class.LAUnderMinimumEvent.php',
    		'MysqlBackupCompleteEvent'		=> SRCPATH.'/events/class.MysqlBackupCompleteEvent.php',
    		'MysqlBackupFailEvent'			=> SRCPATH.'/events/class.MysqlBackupFailEvent.php',
    		'MySQLReplicationFailEvent'		=> SRCPATH.'/events/class.MySQLReplicationFailEvent.php',
    		'MySQLReplicationRecoveredEvent'=> SRCPATH.'/events/class.MySQLReplicationRecoveredEvent.php',
    		'NewMysqlMasterUpEvent'	=> SRCPATH.'/events/class.NewMysqlMasterUpEvent.php',
    		'RebootBeginEvent'		=> SRCPATH.'/events/class.RebootBeginEvent.php',
    		'RebootCompleteEvent'	=> SRCPATH.'/events/class.RebootCompleteEvent.php',
    		'RebundleCompleteEvent'	=> SRCPATH.'/events/class.RebundleCompleteEvent.php',
    		'RebundleFailedEvent'	=> SRCPATH.'/events/class.RebundleFailedEvent.php',
    		'EBSVolumeMountedEvent'	=> SRCPATH.'/events/class.EBSVolumeMountedEvent.php',
    		'BeforeInstanceLaunchEvent'		=> SRCPATH.'/events/class.BeforeInstanceLaunchEvent.php',
    		'BeforeHostTerminateEvent'		=> SRCPATH.'/events/class.BeforeHostTerminateEvent.php',
    	
    		/****************************** Structs ******************************/
    		'CONTEXTS'				=> SRCPATH."/structs/struct.CONTEXTS.php",
			'CONFIG'				=> SRCPATH."/structs/struct.CONFIG.php",
    	
    		/****************************** ENUMS ******************************/
    		'APPCONTEXT'			=> SRCPATH."/types/enum.APPCONTEXT.php",
			'FORM_FIELD_TYPE'		=> SRCPATH."/types/enum.FORM_FIELD_TYPE.php",
			'SUBSCRIPTION_STATUS'	=> SRCPATH."/types/enum.SUBSCRIPTION_STATUS.php",
			'INSTANCE_TYPE'			=> SRCPATH."/types/enum.INSTANCE_TYPE.php",
    		'X86_64_TYPE'			=> SRCPATH."/types/enum.X86_64_TYPE.php",
    		'I386_TYPE'				=> SRCPATH."/types/enum.I386_TYPE.php",
			'INSTANCE_ARCHITECTURE'	=> SRCPATH."/types/enum.INSTANCE_ARCHITECTURE.php",
			'ZONE_STATUS'			=> SRCPATH."/types/enum.ZONE_STATUS.php",
			'EVENT_TYPE'			=> SRCPATH."/types/enum.EVENT_TYPE.php",
			'RRD_STORAGE_TYPE'		=> SRCPATH."/types/enum.RRD_STORAGE_TYPE.php",
			'GRAPH_TYPE'			=> SRCPATH."/types/enum.GRAPH_TYPE.php",
			'SNMP_TRAP'				=> SRCPATH."/types/enum.SNMP_TRAP.php",
			'MYSQL_BACKUP_TYPE'		=> SRCPATH."/types/enum.MYSQL_BACKUP_TYPE.php",
			'FARM_STATUS'			=> SRCPATH."/types/enum.FARM_STATUS.php",
			'INSTANCE_COST'			=> SRCPATH."/types/enum.INSTANCE_COST.php",
			'INSTANCE_STATE'		=> SRCPATH."/types/enum.INSTANCE_STATE.php",	
			'QUEUE_NAME'			=> SRCPATH."/types/enum.QUEUE_NAME.php",
			'ROLE_ALIAS'			=> SRCPATH."/types/enum.ROLE_ALIAS.php",
			'ROLE_TYPE'				=> SRCPATH."/types/enum.ROLE_TYPE.php",
			'FARM_EBS_STATE'		=> SRCPATH."/types/enum.FARM_EBS_STATE.php",
			'AMAZON_EBS_STATE'		=> SRCPATH."/types/enum.AMAZON_EBS_STATE.php",
    		'SCRIPTING_TARGET'		=> SRCPATH."/types/enum.SCRIPTING_TARGET.php",
    		'APPROVAL_STATE'		=> SRCPATH."/types/enum.APPROVAL_STATE.php",
    		'SCRIPT_ORIGIN_TYPE'	=> SRCPATH."/types/enum.SCRIPT_ORIGIN_TYPE.php",
    		'COMMENTS_OBJECT_TYPE'	=> SRCPATH."/types/enum.COMMENTS_OBJECT_TYPE.php",
    		'EBS_ARRAY_STATUS'		=> SRCPATH."/types/enum.EBS_ARRAY_STATUS.php",
    		'EBS_ARRAY_SNAP_STATUS' => SRCPATH."/types/enum.EBS_ARRAY_SNAP_STATUS.php",
    		'MYSQL_STORAGE_ENGINE'	=> SRCPATH."/types/enum.MYSQL_STORAGE_ENGINE.php",
    		'CLIENT_SETTINGS'		=> SRCPATH."/types/enum.CLIENT_SETTINGS.php",
    	
    		/****************************** Observers ***************************/
		    'EventObserver'			=> APPPATH.'/observers/abstract.EventObserver.php',
		    'DNSEventObserver'		=> APPPATH.'/observers/class.DNSEventObserver.php',
		    'DBEventObserver'		=> APPPATH.'/observers/class.DBEventObserver.php',
		    'ScriptingEventObserver'=> APPPATH.'/observers/class.ScriptingEventObserver.php',
		    'EBSEventObserver'		=> APPPATH.'/observers/class.EBSEventObserver.php',
		    'SNMPInformer'			=> APPPATH.'/observers/class.SNMPInformer.php',
		    'EC2EventObserver'		=> APPPATH.'/observers/class.EC2EventObserver.php',
		    'SSHWorker'				=> APPPATH.'/observers/class.SSHWorker.php',
		    'ElasticIPsEventObserver'	=> APPPATH.'/observers/class.ElasticIPsEventObserver.php',
    		'ScalarizrEventObserver'	=> APPPATH.'/observers/class.ScalarizrEventObserver.php',
    		
    		// Deferred observers
    		'MailEventObserver'		=> APPPATH.'/observers/class.MailEventObserver.php',
    		'RESTEventObserver'		=> APPPATH.'/observers/class.RESTEventObserver.php'
    	);
    	
    	if (key_exists($class_name, $paths))
			require_once $paths[$class_name];
	}
?>