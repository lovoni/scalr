<? 
	require("src/prepend.inc.php"); 
	
	$display["title"] = "Applications&nbsp;&raquo;&nbsp;Add / Edit";
	
	if ($_POST) 
	{
		$ZoneControler = new DNSZoneControler();
	    
	    if ($post_ezone && $post_formadded)	
		{
		    if ($_SESSION["uid"] != 0)
    		  $zoneinfo = $db->GetRow("SELECT * FROM zones WHERE zone=? AND clientid='{$_SESSION['uid']}'", array($post_ezone));
    		else
    		  $zoneinfo = $db->GetRow("SELECT * FROM zones WHERE zone=?", array($post_ezone));
    		  
    		if (!$zoneinfo)
		      UI::Redirect("sites_view.php");
		    
		    $serial = AbstractDNSZone::RaiseSerial($zoneinfo["soa_serial"]);
			
		    
		    $err = array();
		    
		    try
			{
			    $db->Execute("UPDATE zones SET soa_serial='{$serial}' WHERE id='{$zoneinfo['id']}'");
			    
				foreach ((array)$post_zone["records"] as $k=>$v)
				{
					if ($v["rkey"] != '' && $v["rvalue"] != '')
					{
						//
						// Validate Record
						//
						$GLOBALS['warnings'] = array();
						$reflection = new ReflectionClass("{$v['rtype']}DNSRecord");
						if ($v['rtype'] != 'MX')
							$c = $reflection->newInstance($v["rkey"], $v["rvalue"], $v["ttl"]);
						else
							$c = $reflection->newInstance($v["rkey"], $v["rvalue"], $v["ttl"], $v["rpriority"]);
						
						if ($c->__toString() == "")
						{
							$err = array_merge($GLOBALS['warnings'], $err);
						}
						else
							$db->Execute("UPDATE records SET `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=? WHERE id=?", array($v["rtype"], $v["ttl"], $v["rpriority"], $v["rvalue"], $v["rkey"], $k));
					}
					else
						$db->Execute("DELETE FROM records WHERE id=?", array($k));
				}
				
				foreach ((array)$post_add as $k=>$v)
				{
					if ($v["rkey"] != '' && $v["rvalue"] != '')
					{
						//
						// Validate Record
						//
						$GLOBALS['warnings'] = array();
						$reflection = new ReflectionClass("{$v['rtype']}DNSRecord");
						if ($v['rtype'] != 'MX')
							$c = $reflection->newInstance($v["rkey"], $v["rvalue"], $v["ttl"]);
						else
							$c = $reflection->newInstance($v["rkey"], $v["rvalue"], $v["ttl"], $v["rpriority"]);
						
						if ($c->__toString() == "")
							$err = array_merge($GLOBALS['warnings'], $err);
						else
							$db->Execute("INSERT INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?", array($zoneinfo["id"], $v["rtype"], $v["ttl"], $v["rpriority"], $v["rvalue"], $v["rkey"]));
					}
				}
				
				if (count($err) == 0)
				{
					if (!$ZoneControler->Update($zoneinfo["id"]))
					{
						$errmsg = "Cannot update DNS zone: ";
					    $err = $GLOBALS['warnings'];
					}
				}
				else
					$errmsg = "Cannot update DNS zone: ";
			}
			catch(Exception $e)
			{
				if (!$errmsg) 
					$errmsg = $e->getMessage();
			}
			
			if (!$errmsg)
			{
				$okmsg = "Zone successfully updated";
				UI::Redirect("sites_view.php");	
			}
		}
		elseif ($post_domainname && $post_formadded)
		{		
			if ($_SESSION["uid"] == 0)
				UI::Redirect("index.php");
		    
		    $status = false;
			$post_hostname = $post_domainname;
			
		    $records = array();
			$nss = $db->GetAll("SELECT * FROM nameservers");
			foreach ($nss as $ns)
                $records[] = array("rtype" => "NS", "ttl" => 14400, "rvalue" => "{$ns["host"]}.", "rkey" => "{$post_domainname}.", "issystem" => 1);
			                
			$instances = $db->GetAll("SELECT * FROM farm_instances WHERE farmid=? AND ami_id=? AND state='Running'", array($post_farmid, $post_ami_id));
    		foreach ($instances as $instance)
    		    $records[] = array("rtype" => "A", "ttl" => CONFIG::$DYNAMIC_A_REC_TTL, "rvalue" => $instance["external_ip"], "rkey" => "@", "issystem" => 1);
    		    
    		$records = array_merge($records, $post_add);
    		$records = array_merge($records, $_POST['zone']['records']);

    		foreach($records as $k=>$v)
    		{
    			if ($v["rkey"] != '' && $v["rvalue"] != '')
    			{
	    			$reflection = new ReflectionClass("{$v['rtype']}DNSRecord");
					if ($v['rtype'] != 'MX')
						$c = $reflection->newInstance($v["rkey"], $v["rvalue"], $v["ttl"]);
					else
						$c = $reflection->newInstance($v["rkey"], $v["rvalue"], $v["ttl"], $v["rpriority"]);
					
					if ($c->__toString() == "")
						$err = array_merge($GLOBALS['warnings'], $err);
    			}
    		}
    		
    		if (count($err) == 0)
    		{
	    		$roleinfo = $db->GetRow("SELECT * FROM ami_roles WHERE ami_id=?", array($post_ami_id));
	    		
	    		$db->Execute("insert into zones (`zone`, `soa_owner`, `soa_ttl`, `soa_parent`, `soa_serial`, `soa_refresh`, `soa_retry`, `soa_expire`, `min_ttl`, `dtupdated`, `farmid`, `ami_id`, `clientid`, `role_name`)
				values (?,'".CONFIG::$DEF_SOA_OWNER."','14400','".CONFIG::$DEF_SOA_PARENT."',?,'14400','7200','3600000','86400',NOW(), ?, ?, ?, ?)", 
				array($post_domainname, date("Ymd")."01", $post_farmid, $post_ami_id, $_SESSION["uid"], $roleinfo['name']));
				$zoneid = $db->Insert_ID();
				
				$zoneinfo = $db->GetRow("SELECT * FROM zones WHERE id='{$zoneid}'");
	    		
				foreach ($records as $k=>$v)
				{
					if ($v["rkey"] != '' && $v["rvalue"] != '')
						$db->Execute("REPLACE INTO records SET zoneid=?, `rtype`=?, `ttl`=?, `rpriority`=?, `rvalue`=?, `rkey`=?, `issystem`=?", array($zoneinfo["id"], $v["rtype"], $v["ttl"], $v["rpriority"], $v["rvalue"], $v["rkey"], $v["issystem"] ? 1 : 0));
				}
					
				if (!$ZoneControler->Update($zoneid))
				{
				    $errmsg = "Cannot add new DNS zone: ".Core::GetLastWarning();
				    
				    $db->Execute("DELETE FROM zones WHERE id='{$zoneid}'");
				    $db->Execute("DELETE FROM records WHERE zoneid='{$zoneid}'");
				}
				else 
				{
				    $okmsg = "Zone successfully added";
				    UI::Redirect("sites_view.php");
				}
    		}
		}
	}
	
	if ($post_ezone || $req_ezone)
	{
		$zone = ($post_ezone) ? $post_ezone : $req_ezone;
		
		if ($_SESSION["uid"] != 0)
		  $zoneinfo = $db->GetRow("SELECT * FROM zones WHERE zone=? AND clientid='{$_SESSION['uid']}'", array($zone));
		else
		  $zoneinfo = $db->GetRow("SELECT * FROM zones WHERE zone=?", array($zone));
		  
		if (!$zoneinfo)
			UI::Redirect("sites_view.php");
		
		$records = $db->GetAll("SELECT * FROM records WHERE zoneid='{$zoneinfo["id"]}'");
		
		$display["zone"] = $zoneinfo;
		$display["zone"]["records"] = $records;
		$display["domainname"] = $display["zone"]["zone"];
	}
	else
	{
		$display = array_merge($display, $_POST);
		$display["zone"] = $_POST;
		
		if ($post_domainname)
		{
			$db_chk = $db->GetRow("SELECT * FROM zones WHERE zone='{$post_domainname}'");
			if (!$db_chk["id"])
				$display["domainname"] = $post_domainname;	
			else
			{
				$display["domainname"] = false;
				$errmsg = "DNS zone for this domain already added!";
			}
		}
		
		$display["ami_id"] = $post_ami_id;
		
		if ($req_farmid)
		{
    		if ($_POST['createtype'] == 2)
    		{
    			UI::Redirect("app_wizard.php");
    		}
			
			if ($_SESSION['uid'] != 0)
                $display["farm"] = $db->GetRow("SELECT * FROM farms WHERE id=? AND clientid=?", array($req_farmid, $_SESSION['uid']));
    		else 
    		    $display["farm"] = $db->GetRow("SELECT * FROM farms WHERE id=?", array($req_farmid));  
    		
    		if (!$display["farm"])
    		{
    		    $errmsg = "Farm not found";
                UI::Redirect("farms_view.php");
    		}
		}
		else 
		{
		    $template_name = "site_add_step1.tpl";
		    $display["farms"] = $db->GetAll("SELECT * FROM farms WHERE clientid=?", array($_SESSION['uid']));
		    
		    if (count($display["farms"]) == 0)
		    	UI::Redirect("app_wizard.php");
		}
		
		$records = array();
		if (count($display["zone"]["records"]) == 0)
		{
    		$instances = $db->GetAll("SELECT * FROM farm_instances WHERE farmid=? AND ami_id=? AND state='Running'", array($display["farm"]["id"], $post_ami_id));
    		foreach ($instances as $instance)
    		    $records[] = array("rtype" => "A", "ttl" => CONFIG::$DYNAMIC_A_REC_TTL, "rvalue" => $instance["external_ip"], "rkey" => "{$display["domainname"]}.", "issystem" => 1);
    		    
            $nss = $db->GetAll("SELECT * FROM nameservers");
            foreach ($nss as $ns)
                $records[] = array("rtype" => "NS", "ttl" => 14400, "rvalue" => "{$ns["host"]}.", "rkey" => "{$display["domainname"]}.", "issystem" => 1);
                
                
            $def_records = $db->GetAll("SELECT * FROM default_records WHERE clientid='{$_SESSION['uid']}'");
            foreach ($def_records as $record)
            {
                $record["rkey"] = str_replace("%hostname%", "{$display["domainname"]}.", $record["rkey"]);
                $record["rvalue"] = str_replace("%hostname%", "{$display["domainname"]}.", $record["rvalue"]);
            	$records[] = $record;
            }
        }
    		    
		$display["zone"]["records"] = $records;
		
		$display["roles"] = $db->GetAll("SELECT * FROM ami_roles WHERE iscompleted='1' AND ami_id IN (SELECT ami_id FROM farm_amis WHERE farmid=?)", array($req_farmid));
	}
	
	$display["add"] = array(1, 2, 3, 4, 5);
	$display["def_sn"] = date("Ymd")."01";
	$display["ezone"] = ($_GET["ezone"]) ? $_GET["ezone"] : $_POST["ezone"];
	$display["def_soa_owner"] = CONFIG::$DEF_SOA_OWNER;
	$display["def_soa_parent"] = CONFIG::$DEF_SOA_PARENT;

		
	require("src/append.inc.php"); 
?>