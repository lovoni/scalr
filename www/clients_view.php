<?
	require_once('src/prepend.inc.php');
	$display['load_extjs'] = true;
	
	if ($_SESSION["uid"] != 0)
	   UI::Redirect("index.php");
	
	// Post actions
	if ($_POST && $post_with_selected)
	{
		switch($post_action)
		{
			case "activate":
			case "deactivate":
				
				$flag = ($post_action == "activate") ? '1' : '0';
				
				$i = 0;			
				foreach ((array)$post_id as $clientid)
				{
					$db->Execute("UPDATE clients SET isactive=? WHERE id=?", array($flag, $clientid));
					Scalr::FireInternalEvent("updateClient", Client::Load($clientid));
					$i++;
				}
				
				$okmsg = "{$i} clients updated";
				UI::Redirect("clients_view.php");
				
				break;
			
			case "delete":
				
				$i = 0;			
				foreach ((array)$post_id as $clientid)
				{
					$i++;
					
					$Client = Client::Load($clientid);
					Scalr::FireInternalEvent("beforeDeleteClient", $Client);
					
					$db->Execute("DELETE FROM clients WHERE id='{$clientid}'");
					$db->Execute("DELETE FROM client_settings WHERE clientid='{$clientid}'");
					
					$farms = $db->GetAll("SELECT * FROM farms WHERE clientid='{$clientid}'");
				    foreach ($farms as $farm)
				    {
					    $db->Execute("DELETE FROM farms WHERE id=?", array($farm["id"]));
					    $db->Execute("DELETE FROM farm_roles WHERE farmid=?", array($farm["id"]));
					    $db->Execute("DELETE FROM farm_instances WHERE farmid=?", array($farm["id"]));
					    $db->Execute("DELETE FROM farm_role_options WHERE farmid=?", array($farm["id"]));
                        $db->Execute("DELETE FROM farm_role_scripts WHERE farmid=?", array($farm["id"]));
                        $db->Execute("DELETE FROM farm_event_observers WHERE farmid=?", array($farm["id"]));
                        $db->Execute("DELETE FROM farm_ebs WHERE farmid=?", array($farm["id"]));
                        $db->Execute("DELETE FROM elastic_ips WHERE farmid=?", array($farm["id"]));
				    }
				    
				    $db->Execute("DELETE FROM roles WHERE clientid='{$clientid}'");
				    
				    Scalr::FireInternalEvent("deleteClient", $Client);
				}
				
				$okmsg = sprintf(_("%s clients deleted"), $i);
				UI::Redirect("clients_view.php");
				
				break;
		}
	}

	if ($get_clientid)
	{
		$clientid = (int)$get_clientid;
		$display['grid_query_string'] .= "&clientid={$clientid}";
	}

	if (isset($req_isactive))
	{
		$isactive = (int)$req_isactive;
		$display['grid_query_string'] .= "&isactive={$isactive}";
	}
	
	if (isset($req_overdue))
	{
		$display['grid_query_string'] .= "&overdue=1";
	}
	
	if ($req_cancelled)
	{
		$display['grid_query_string'] .= "&cancelled=1";
	}
	
	$display["title"] = _("Clients > Manage");
	require_once ("src/append.inc.php");
?>