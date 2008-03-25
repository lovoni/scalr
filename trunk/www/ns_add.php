<? 
	require("src/prepend.inc.php"); 
	
	if ($_SESSION["uid"] != 0)
	   CoreUtils::Redirect("index.php");
	   
	$display["title"] = "Nameservers&nbsp;&raquo;&nbsp;Add";
	
	if ($_POST) 
	{
		$Validator = new Validator();
		
		// Check FTP login
		if (!$Validator->IsAlpha($post_username))
			$err[] = "Username is invalid";
		
		// Check FTP Upload Bandwidth
		if (!$Validator->IsNumeric($post_port))
			$err[] = "Invalid Server port";
		
		// Check hostname
		if (!$post_id)
		{
    		if (!preg_match("/^[A-Za-z0-9]+[A-Za-z0-9-\.]*[A-Za-z0-9]+\.[A-Za-z0-9-\.]+$/", $post_host))
    			$err[] = "Invalid server hostname";
		}
	
	    if (count($err) == 0)
	    {
    		if (!$post_id)
    		{
    			$db->Execute("INSERT INTO nameservers (host, port, username, password, rndc_path, named_path, namedconf_path) values (?,?,?,?,?,?,?)",
                    			array(   $post_host, 
                    			         $post_port, 
                    			         $post_username, 
                    			         $Crypto->Encrypt($post_password, $_SESSION['cpwd']), 
                    			         $post_rndc_path, 
                    			         $post_named_path, 
                    			         $post_namedconf_path
                    			     )
    			     );
    			     
    			$zones = $db->GetAll("SELECT * FROM zones");
                if (count($zones) > 0)
                {
                    $DNSZoneController = new DNSZoneControler();
                    
                    foreach ($zones as $zone)
                    {
                        if ($zone['id'])
                        {
                            $db->Execute("REPLACE INTO records SET zoneid='{$zone['id']}', rtype='NS', ttl=?, rvalue=?, rkey='@', issystem='1'", 
                            array(14400, "{$post_host}."));
                            
                            if ($zone["isdeleted"] == 0)
                            {
                                if (!$DNSZoneController->Update($zone["id"]))
                                    Log::Log("Cannot add NS record to zone '{$zone['zone']}'", E_ERROR);
                                else 
                                    Log::Log("NS record for instance {$instanceinfo['instance_id']} with host {$post_host} added to zone '{$zone['zone']}'", E_USER_NOTICE);
                            }
                        }
                    }
                }
    			     	
    			$mess = "Nameserver successfully added";
    		
    			CoreUtils::Redirect("ns_view.php");
    			
    		}
    		else
    		{
    			$password = ($post_password != '******') ? "password='".$Crypto->Encrypt($post_password, $_SESSION['cpwd'])."'," : "";
    			
    			$db->Execute("UPDATE nameservers SET port=?, username=?, $password rndc_path=?, named_path=?, namedconf_path=?
    							WHERE id='{$post_id}'",
    							array($post_port, $post_username, $post_rndc_path, $post_named_path, $post_namedconf_path));
    
    							
    			$mess = "Nameserver succesfully updated";
    			CoreUtils::Redirect("ns_view.php");
    		}
	    }
	}
	
	if ($req_id)
	{
		$id = (int)$req_id;
		$display["ns"] = $db->GetRow("SELECT * FROM nameservers WHERE id='{$id}'");
		$display["id"] = $id;
	}
	else
		$display = array_merge($display, $_POST);
	
	require("src/append.inc.php"); 	
?>