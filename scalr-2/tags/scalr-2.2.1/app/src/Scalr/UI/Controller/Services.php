<?php
class Scalr_UI_Controller_Services extends Scalr_UI_Controller
{	
	public function hasAccess()
	{
		return $this->session->getAuthToken()->hasAccess(Scalr_AuthToken::ACCOUNT_USER);
	}
}