<?php
	
	class HostDownScalrMessage extends ScalrMessage
	{
		const SNMP_TRAP = "SNMPv2-MIB::snmpTrap.11.0 SNMPv2-MIB::sysUpTime.0 s \"{MessageID}\" SNMPv2-MIB::sysName.0 s \"{RoleAlias}\" SNMPv2-MIB::sysLocation.0 s \"{InternalIP}\" SNMPv2-MIB::sysDescr.0 s \"{IsFirstInRole}\" SNMPv2-MIB::sysContact.0 s \"{RoleName}\"";
		
		public $RoleAlias;
		public $InternalIP;
		public $RoleName;
		public $IsFirstInRole;
		
		public function __construct($role_alias, $internal_ip, $is_first_in_role, $role_name)
		{
			parent::__construct();
			
			$this->RoleAlias = $role_alias;
			$this->InternalIP = $internal_ip;
			$this->RoleName = $role_name;
			$this->IsFirstInRole = $is_first_in_role;
		}
	}
?>