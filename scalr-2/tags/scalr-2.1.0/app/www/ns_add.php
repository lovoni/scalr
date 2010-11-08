<? 
	require("src/prepend.inc.php"); 
	
	if (!Scalr_Session::getInstance()->getAuthToken()->hasAccess(Scalr_AuthToken::SCALR_ADMIN))
	{
		$errmsg = _("You have no permissions for viewing requested page");
		UI::Redirect("index.php");
	}
	   
	$display["title"] = "Nameservers&nbsp;&raquo;&nbsp;Add";
	
	if ($_POST) 
	{
		$Validator = new Validator();
		
		if (!$post_isproxy)
		{
			// Check FTP login
			if (!$Validator->IsAlpha($post_username))
				$err[] = "Username is invalid";
			
			// Check FTP Upload Bandwidth
			if (!$Validator->IsNumeric($post_port))
				$err[] = "Invalid Server port";
		}
		
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
    			$db->Execute("INSERT INTO nameservers 
    				(host, port, username, password, rndc_path, named_path, namedconf_path, isproxy, isbackup, ipaddress) 
    				values (?,?,?,?,?,?,?,?,?,?)",
                    array(   $post_host, 
                             $post_port, 
                             $post_username, 
                             $Crypto->Encrypt($post_password, $_SESSION['cpwd']), 
                             $post_rndc_path, 
                             $post_named_path, 
                             $post_namedconf_path,
                             $post_isproxy ? 1 : 0,
                             $post_isbackup ? 1 : 0,
                             $post_ipaddress
                         )
    			);
    			      	
    			$okmsg = "Nameserver successfully added";
    		
    			UI::Redirect("ns_view.php");
    			
    		}
    		else
    		{
    			$password = ($post_password != '******') ? "password='".$Crypto->Encrypt($post_password, $_SESSION['cpwd'])."'," : "";
    			
    			$db->Execute("UPDATE nameservers SET port=?, username=?, $password rndc_path=?, 
    				named_path=?, namedconf_path=?, isproxy=?, isbackup=?, ipaddress=? 
    				WHERE id=?",
    				array($post_port, $post_username, $post_rndc_path, $post_named_path, 
    				$post_namedconf_path, ($post_isproxy ? 1 : 0), $post_isbackup ? 1 : 0,
                    $post_ipaddress, $post_id)
    			);
    
    							
    			$okmsg = "Nameserver successfully updated";
    			UI::Redirect("ns_view.php");
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