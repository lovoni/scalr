<?php
	
	class MySQLReplicationRecoveredEvent extends Event
	{
		/**
		 * 
		 * @var DBInstance
		 */
		public $DBServer;
		
		public function __construct(DBServer $DBServer)
		{
			parent::__construct();
			
			$this->DBServer = $DBServer;
		}
	}
?>