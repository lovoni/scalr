<?php
	require("src/prepend.inc.php"); 

	if ($_SESSION["uid"] == 0)
	   UI::Redirect("index.php");

	if (!$req_name)
	{
	    $errmsg = "Please select parameter group from list";
		UI::Redirect("aws_rds_parameter_groups.php");
	}
	
	$Client = Client::Load($_SESSION['uid']);
	
	$display["title"] = _("Tools&nbsp;&raquo;&nbsp;Amazon Web Services&nbsp;&raquo;&nbsp;Amazon RDS&nbsp;&raquo;&nbsp;Edit group ({$req_name})");	
	$display["group_name"] = $req_name;	
		
	$AmazonRDSClient = AmazonRDS::GetInstance($Client->AWSAccessKeyID, $Client->AWSAccessKey); 
		
	try
	{			
		// Form DBParameterGroups	
		$groupParam = $AmazonRDSClient->DescribeDBParameters($req_name);	
		$gp = (array)$groupParam->DescribeDBParametersResult->Parameters;	
	 	
		$group = $AmazonRDSClient->DescribeDBParameterGroups($req_name);
		$groupDescription = (string)$group->DescribeDBParameterGroupsResult->DBParameterGroups->DBParameterGroup->Description;		
			 	
		foreach ($gp['Parameter'] as &$paramValue)
		{	
			$paramValue = (array)$paramValue;
			if (stristr($paramValue['AllowedValues'], ",") && $paramValue['DataType'] != 'boolean')
			{
				$paramValue['AllowedValues'] = explode(",", $paramValue['AllowedValues']);
				array_map('trim', $paramValue['AllowedValues']);
			}					
			
			if($paramValue['IsModifiable'] == 'true')			
			{					
				$oldParamValues[$paramValue['ParameterName']] = $paramValue['ParameterValue'];
				$oldParamApplyType[$paramValue['ParameterName']] = $paramValue['ApplyType'];				
			}	
		}			
	}    
	catch(Exception $e)
	{
		$errmsg = $e->getMessage();
		UI::Redirect("aws_rds_parameter_groups.php");
	}		
	
	// if some parameters were changed - from API modify request	
	if($_POST)
	{	
		$modifiedParameters = new ParametersList();	
		$sendCounter = 0;	
		
		try
		{
			foreach($oldParamValues as $paramName => $oldParamValue)
			{					
				// send every MAX_MODIFY_PARAMETERS_NUM parameters (AmazonRDS session limit)
				if($sendCounter == AmazonRDS::MAX_MODIFY_PARAMETERS_NUM)	
				{	
					if($_POST['cbtn_3']) // if reset to default
						$AmazonRDSClient->ResetDBParameterGroup($req_name,$modifiedParameters);
					else
						$AmazonRDSClient->ModifyDBParameterGroup($req_name,$modifiedParameters);
					$modifiedParameters = new  ParametersList();
					$sendCounter = 0;				
				}
							
				$newParamValue = $_POST[$paramName];							
				
				// if reset to default
				if($_POST['cbtn_3'])
				{
					if($oldParamApplyType[$paramName] == 'static')				
						$modifiedParameters->AddParameters($paramName,$newParamValue,"pending-reboot");								
					else									
						$modifiedParameters->AddParameters($paramName,$newParamValue,"immediate");
										
					$sendCounter++;					
				}			
				// if modify
				elseif	(
							(empty($oldParamValue) && !empty($newParamValue)) || 
							(!empty($oldParamValue) && empty($newParamValue)) ||
							($newParamValue !== $oldParamValue && !empty($newParamValue) && !empty($oldParamValue))
						)
				{  	
						// enter to "if" when	
						//  old      new 
						// EMPTY -> VALUE;
						// VALUE -> EMPTY
						// VAL1  -> VAL2
						
						// don't enter to "if" when	
						// EMPTY -> EMPTY 			
						
					// check parameter's  ApplyType to modify ApplyMethod					
					
					if($oldParamApplyType[$paramName] == 'static')				
						$modifiedParameters->AddParameters($paramName,$newParamValue,"pending-reboot");								
					else									
						$modifiedParameters->AddParameters($paramName,$newParamValue,"immediate");
											
					$sendCounter++;
				}						
			}		
			
			// modify request for the last 0...19 records			
			if((int)$sendCounter > 0)			
			{
				
				if($_POST['cbtn_3']) // if reset to default
				{
					$AmazonRDSClient->ResetDBParameterGroup($req_name,$modifiedParameters);
				}
				else
				{
					$AmazonRDSClient->ModifyDBParameterGroup($req_name,$modifiedParameters);				
				}
			}
						
			if($_POST['cbtn_3'])
				$okmsg = "DB parameter group successfully set to default";	
			else 
				$okmsg = "DB parameter group successfully updated";	
			UI::Redirect("aws_rds_param_group_edit.php?name={$req_name}");	
		}
		catch(Exception $e)
		{			
			$err[] =  $e->getMessage();
		}
	
		
	}	
	
	$display['groupDescription'] = trim($groupDescription);
	$display['parameters'] = $gp['Parameter'];
	
	require("src/append.inc.php"); 
?>