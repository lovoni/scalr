<?
	/**
	 * Task for EBS mount routine aftre attachment complete
	 *
	 */
	class EBSMountTask extends CheckEBSVolumeStateTask
	{
		public function Run()
		{
			$DB = Core::GetDBInstance();
			
			try
			{
				$DBEBSVolume = DBEBSVolume::Load($this->VolumeID);
			}
			catch (Exception $e)
			{
				
			}
			
			if ($DBEBSVolume)
			{
				try
				{
					// Get farminfo from database
					$farminfo = $DB->GetRow("SELECT * FROM farms WHERE id=?", array($DBEBSVolume->FarmID));
					// Get instance info fro database
					$instanceinfo = $DB->GetRow("SELECT * FROM farm_instances WHERE instance_id=?", array($DBEBSVolume->InstanceID));
					// Get farm role info
					$farm_role_info = $DB->GetRow("SELECT * FROM farm_amis WHERE ami_id=? OR replace_to_ami=? AND farmid=?",
						array($instanceinfo['ami_id'], $instanceinfo['ami_id'], $farminfo['id'])
					);
					
					// Get EC2 Client
					$EC2Client = $this->GetAmazonEC2ClientObject($farminfo['clientid'], $farminfo['region']);
					
					// Check volume status
					$response = $EC2Client->DescribeVolumes($DBEBSVolume->VolumeID);
					$volume = $response->volumeSet->item;
					
					if ($volume->status == AMAZON_EBS_STATE::IN_USE)
					{
						if ($volume->attachmentSet->item->status == 'attached')
						{
							$createfs = ($farm_role_info['ebs_snapid'] || $DBEBSVolume->IsFSExists == 1) ? 0 : 1;
	
							// Nicolas request. Device not avaiable on instance after attached state. need some time.
							sleep(5);
							
							$DBInstance = DBInstance::LoadByID($instanceinfo['id']);
							$DBInstance->SendMessage(new MountPointsReconfigureScalrMessage(
								$DBEBSVolume->Device, 
								$farm_role_info['ebs_mountpoint'], 
								$createfs
							));
							
							$DBEBSVolume->State = FARM_EBS_STATE::MOUNTING;
				            $DBEBSVolume->Save();
				            
				            return true;
						}
						else
							return false;
					}
					elseif ($volume->status == AMAZON_EBS_STATE::ATTACHING)
					{
						return false;
					}
					else
					{
						return true;
					}
				}
				catch(Exception $e)
				{
					LoggerManager::getLogger(__CLASS__)->fatal(sprintf(_("Cannot check EBS status: %s"), $e->getMessage()));
					return false;
				}
			}
			
			return false;
		}
	}
?>