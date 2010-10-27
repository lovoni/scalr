<?php

	require(dirname(__FILE__)."/../src/prepend.inc.php");
	
	if ($_SESSION["uid"] == 0)
	{
		$errmsg = _("Requested page cannot be viewed from admin account");
		UI::Redirect("index.php");
	}
		
	if ($req_farmid)
	{
		$DBFarm = DBFarm::LoadByID($req_farmid);
		
		if ($DBFarm->ClientID != $_SESSION['uid'])
			UI::Redirect("/index.php");
	}
	else
		UI::Redirect("/index.php");
		
	$servers = $DBFarm->GetMySQLInstances(true);
	$DBServer = $servers[0];	
	
	if ($DBServer)
	{
		$DBFarmRole = $DBServer->GetFarmRoleObject();
		
		if ($DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_PMA_USER))
		{
			$r = array();
			define('PMA_KEY', '!80uy98hH&)#0gsg695^39gsvt7s853r%#dfscvJKGSG67gVB@');
			$r['s'] = md5(mt_rand());
			$key = substr(abs(crc32($r['s'])), 5).PMA_KEY;
			$r['r'] = $Crypto->Encrypt(serialize(array(
				'user' => $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_PMA_USER), 
				'password' => $DBFarmRole->GetSetting(DBFarmRole::SETTING_MYSQL_PMA_PASS), 
				'host' => $DBServer->remoteIp
			)), $key);
			$r['h'] = $Crypto->Hash($r['r'].$r['s'].PMA_KEY);
	
			$query = http_build_query($r);
			UI::Redirect("http://phpmyadmin.scalr.net/auth/pma_sso.php?{$query}");
		}
		else
		{
			$okmsg = _("There is no MySQL access credentials for PMA");
			UI::Redirect("/farm_mysql_info.php?farmid={$req_farmid}");
		}
	}
	else
	{
		$errmsg = _("There is no running MySQL master. Please wait until master starting up.");
		UI::Redirect("/farm_mysql_info.php?farmid={$req_farmid}");
	}
?>