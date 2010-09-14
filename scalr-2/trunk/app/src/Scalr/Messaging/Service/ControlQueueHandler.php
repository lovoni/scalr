<?php

class Scalr_Messaging_Service_ControlQueueHandler implements Scalr_Messaging_Service_QueueHandler {
	
	private $db;
	
	private $logger;
	
	function __construct() {
		$this->db = Core::GetDBInstance();
		$this->logger = Logger::getLogger(__CLASS__);
	}
	
	function accept($queue) {
		return $queue == "control";
	}
	
	function handle($queue, Scalr_Messaging_Msg $message, $rawMessage) {
		$this->logger->info(sprintf("Received message '%s' from server '%s'", 
				$message->getName(), $message->getServerId()));
		$this->db->Execute("INSERT INTO messages SET
			messageid = ?,
			message = ?,
			server_id = ?,
			type = ?,
			isszr = ?
		", array(
			$message->messageId,
			$rawMessage,
			$message->getServerId(),
			"in",
			1
		));
	}	
}