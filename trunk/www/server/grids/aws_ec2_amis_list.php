<?php
	$response = array();

	// AJAX_REQUEST;
	$context = 6;
	
	try
	{
		$enable_json = true;
		include("../../src/prepend.inc.php");		
		
		$req_show_all = true;	//
		
		if (isset($req_show_all))
		{
			if ($req_show_all == 'true')
				$_SESSION['sg_show_all'] = true;
			else
				$_SESSION['sg_show_all'] = false;
		}
		
		$Client = Client::Load($_SESSION['uid']);
		
		$AmazonEC2Client = AmazonEC2::GetInstance(AWSRegions::GetAPIURL($_SESSION['aws_region']));		 
		$AmazonEC2Client->SetAuthKeys($Client->AWSPrivateKey, $Client->AWSCertificate);
		
		$aws_response = $AmazonEC2Client->DescribeImages(); // show amis
							
		// Rows	
		$rows = (array)$aws_response->imagesSet;		
		
		if ($rows["item"] instanceof stdClass)
			$rows["item"] = array($rows["item"]); // convert along subnet record to array
		
		$rowz = array();		
		
		foreach ($rows['item'] as $row)	
		{			
			if(strpos((string)$row->imageId,"mi")) // select only amies ("mi" to return 1, not 0 as ami)				
				$rowz[]=(array)$row;
		}							
		
		// diplay list limits
		$start = $req_start ? (int) $req_start : 0;
		$limit = $req_limit ? (int) $req_limit : 20;
		
		$response['total'] = count($rowz);	
		$rowz = (count($rowz) > $limit) ? array_slice($rowz, $start, $limit) : $rowz;
		
		// descending sorting of requested result
		$response["data"] = array();	
		 		
		if ($req_sort)
		{
			$nrowz = array();
			foreach ($rowz as $row)				
				$nrowz[(string)$row['spotPrice']] = $row;			
					
			ksort($nrowz);
			
			if ($req_dir == 'DESC')
				$rowz = array_reverse($nrowz);
			else
				$rowz = $nrowz;	
		}
			
		// Rows. Create final rows array for script
		foreach ($rowz as $row)
		{ 	
			
			$response["data"][] = array(
					"imageId"			=> (string)$row['imageId'], // have to call only like "id" for correct script work in template
					"imageState"		=> (string)$row['imageState'],
					"imageOwnerId"		=> (string)$row['imageOwnerId'],					
					"isPublic"			=> (string)$row['isPublic'],
					"architecture"		=> (string)$row['architecture'],
					"imageType"			=> (string)$row['imageType'],
					"rootDeviceType"	=> (string)$row['rootDeviceType']					
					);				
		} 
		
		$response["types_i386"]   = array(0 => "m1.small", 1 => "c1.medium");
   		$response["types_x86_64"] = array(0 => "m1.large", 1 => "m1.xlarge", 2 => "c1.xlarge",3 => "m2.2xlarge", 4 => "m2.4xlarge"  );
   
	}
	catch(Exception $e)
	{
		$response = array("error" => $e->getMessage(), "data" => array());
	}

	print json_encode($response);
?>