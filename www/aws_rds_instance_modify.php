<? 
	require("src/prepend.inc.php"); 
	
	$display["title"] = _("Tools&nbsp;&raquo;&nbsp;Amazon Web Services&nbsp;&raquo;&nbsp;Amazon RDS&nbsp;&raquo;&nbsp;Modify DB Instance");
		
	if ($_SESSION["uid"] == 0)
	{
		$errmsg = _("Requested page cannot be viewed from admin account");
		UI::Redirect("index.php");
	}
	
	$Client = Client::Load($_SESSION['uid']);
	$AmazonRDSClient = AmazonRDS::GetInstance($Client->AWSAccessKeyID, $Client->AWSAccessKey);
	
	if (!$req_name)
	{
		UI::Redirect("aws_rds_instances_view.php");
	}
	
	try
	{
		$instance = $AmazonRDSClient->DescribeDBInstances($req_name);
		$instance = $instance->DescribeDBInstancesResult->DBInstances->DBInstance;
	}
	catch(Exception $e)
	{
		$errmsg = "AWS error: {$e->getMessage()}";
		UI::Redirect("aws_rds_instances_view.php");
	}
	
	$sg = (array)$instance->DBSecurityGroups;
	$sec_groups = array();
	if (is_array($sg['DBSecurityGroup']))
	{
		foreach ($sg['DBSecurityGroup'] as $g)
			$sec_groups[(string)$g->DBSecurityGroupName] = (array)$g;
			
	}
	else
		$sec_groups = array((string)$sg['DBSecurityGroup']->DBSecurityGroupName => (array)$sg['DBSecurityGroup']);
		
	$pg = (array)$instance->DBParameterGroups;
	$param_groups = array();
	if (is_array($pg['DBParameterGroup']))
	{
		foreach ($pg['DBParameterGroup'] as $g)
			$param_groups[(string)$g->DBParameterGroupName] = (array)$g;
			
	}
	else
		$param_groups = array((string)$pg['DBParameterGroup']->DBParameterGroupName => (array)$pg['DBParameterGroup']);
		
	$display['sec_groups'] = array_keys($sec_groups);
		
	if ($_POST)
	{		
		$_POST['PreferredMaintenanceWindow'] = "{$_POST['pmw1']['ddd']}:{$_POST['pmw1']['hh']}:{$_POST['pmw1']['mm']}-{$_POST['pmw2']['ddd']}:{$_POST['pmw2']['hh']}:{$_POST['pmw2']['mm']}";
		$_POST['PreferredBackupWindow'] = "{$_POST['pbw1']['hh']}:{$_POST['pbw1']['mm']}-{$_POST['pbw2']['hh']}:{$_POST['pbw2']['mm']}";

		try
		{		
			$AmazonRDSClient->ModifyDBInstance(
				$req_name,
				$_POST['DBParameterGroupName'],
				$_POST['DBSecurityGroups'],
				$_POST['PreferredMaintenanceWindow'],
				$_POST['MasterUserPassword'] ? $_POST['MasterUserPassword'] : null,
				$_POST['AllocatedStorage'],
				$_POST['DBInstanceClass'],
				$_POST['ApplyImmediately'],
				$_POST['BackupRetentionPeriod'],
				$_POST['PreferredBackupWindow']
			);
		}
		catch(Exception $e)
		{
			$err[] = $e->getMessage();
		}
		
		if (count($err) == 0)
		{
			$okmsg = _("DB instance successfully updated");
			UI::Redirect("aws_rds_instances_view.php");
		}
	}
	
	//
	// Load DB parameter groups
	//
	$DBParameterGroups = $AmazonRDSClient->DescribeDBParameterGroups();
	$groups = (array)$DBParameterGroups->DescribeDBParameterGroupsResult->DBParameterGroups;
	$groups = $groups['DBParameterGroup'];	
	if ($groups)
	{
		if (!is_array($groups))
			$groups = array($groups);
			
		foreach ((array)$groups as $group)
			$display['DBParameterGroups'][] = $group;
	}
	
	//
	// Load DB security groups
	//
	$DescribeDBSecurityGroups = $AmazonRDSClient->DescribeDBSecurityGroups();
	$sgroups = (array)$DescribeDBSecurityGroups->DescribeDBSecurityGroupsResult->DBSecurityGroups;
	$sgroups = $sgroups['DBSecurityGroup'];
	if ($sgroups)
	{
		if (!is_array($sgroups))
			$sgroups = array($sgroups);
			
		foreach ((array)$sgroups as $sgroup)
			$display['DBSecurityGroups'][] = $sgroup;
	}
	
	$display['instance'] = $instance;
	
	require("src/append.inc.php"); 
?>