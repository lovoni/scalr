<? 
	require("src/prepend.inc.php"); 
	$display['load_extjs'] = true;
	
	if ($_SESSION["uid"] == 0)
	{
		$errmsg = _("Requested page cannot be viewed from admin account");
		UI::Redirect("index.php");
	}

	$display["title"] = _("Tasks scheduler");
	
	if (isset($post_cancel))
		UI::Redirect("scheduler.php");

	if ($req_task)
	{
		$Validator = new Validator();
		
		if (!is_array($req_id))
			$req_id = array($req_id);
		
		foreach ($req_id as $task_id)
		{
			if(!$Validator->IsNumeric($task_id))
				continue;
			
			switch($req_action)
			{
				case "delete":
					
					$db->Execute("DELETE FROM scheduler_tasks WHERE id = ? AND client_id = ?",
						array($task_id, $_SESSION['uid'])
					);
					$okmsg = _("Selected task(s) successfully removed");
					
					break;
					
				case "activate":
					
					$info = $db->Execute("UPDATE scheduler_tasks SET `status` = ? WHERE id = ? AND `status` = ? AND client_id = ?",
						array(TASK_STATUS::ACTIVE, $task_id, TASK_STATUS::SUSPENDED, $_SESSION['uid'])
					);
					$okmsg = _("Selected task(s) successfully activated");
					
					break;
					
				case "suspend":
					
					$info = $db->Execute("UPDATE scheduler_tasks SET `status` = ? WHERE id = ? AND `status` = ? AND client_id = ?",
						array(TASK_STATUS::SUSPENDED, $task_id, TASK_STATUS::ACTIVE, $_SESSION['uid'])
					);
					$okmsg = _("Selected task(s) successfully suspended");
					
					break;
			}
		}	
		
		UI::Redirect("scheduler.php");
	}
	
	require("src/append.inc.php");
?>