<?php

require_once(LOG4PHP_DIR . '/LoggerAppenderSkeleton.php');
require_once(LOG4PHP_DIR . '/helpers/LoggerOptionConverter.php');
require_once(LOG4PHP_DIR . '/LoggerLog.php');

class LoggerAppenderScalr extends LoggerAppenderSkeleton {

    /**
     * Create the log table if it does not exists (optional).
     * @var boolean
     */
    var $createTable = true;
    
    /**
     * The type of database to connect to
     * @var string
     */
    var $type;
    
    /**
     * Database user name
     * @var string
     */
    var $user;
    
    /**
     * Database password
     * @var string
     */
    var $password;
    
    /**
     * Database host to connect to
     * @var string
     */
    var $host;
    
    /**
     * Name of the database to connect to
     * @var string
     */
    var $database;
    
    /**
     * A {@link LoggerPatternLayout} string used to format a valid insert query (mandatory).
     * @var string
     */
    var $sql;
    
    /**
     * Table name to write events. Used only if {@link $createTable} is true.
     * @var string
     */    
    var $table;
    
    /**
     * @var object Adodb instance
     * @access private
     */
    var $db = null;
    
    /**
     * @var boolean used to check if all conditions to append are true
     * @access private
     */
    var $canAppend = true;
    
    /**    
     * @access private
     */
    var $requiresLayout = false;
    
    /**
     * Constructor.
     *
     * @param string $name appender name
     */
    function LoggerAppenderDb($name)
    {
        $this->LoggerAppenderSkeleton($name);
    }

    /**
     * Setup db connection.
     * Based on defined options, this method connects to db defined in {@link $dsn}
     * and creates a {@link $table} table if {@link $createTable} is true.
     * @return boolean true if all ok.
     */
    function activateOptions()
    {        
        $this->db = &ADONewConnection($this->type);
        if (! $this->db->NConnect($this->host, $this->user, $this->password, $this->database)) {
          LoggerLog::debug("LoggerAppenderAdodb::activateOptions() DB Connect Error [".$this->db->ErrorMsg()."]");            
          $this->db = null;
          $this->closed = true;
          $this->canAppend = false;
          return;
        }
        
        $this->layout = LoggerLayout::factory('LoggerPatternLayoutScalr');
        $this->layout->setConversionPattern($this->getSql());
    
        $this->canAppend = true;
    }
    
    function append($event)
    {
        if ($this->canAppend) 
        {
        	try
            {
	        	// Reopen new mysql connection (need for php threads)
	        	$this->activateOptions();
	        	
	        	// Redeclare threadName
	            $event->threadName = TRANSACTION_ID; 
	
	            $event->subThreadName = defined("SUB_TRANSACTIONID") ? SUB_TRANSACTIONID : TRANSACTION_ID;
	            
	            $event->farmID = defined("LOGGER_FARMID") ? LOGGER_FARMID : "";
	            
	        	$event->message = $this->db->qstr($event->message);
	            
	        	$level = $event->getLevel()->toString();
	        	if ($level == "FATAL" || $level == "ERROR")
	        		$event->backtrace = $this->db->qstr(Debug::Backtrace());
	        	else
	        		$event->backtrace = "''";
	        		
	            $query = $this->layout->format($event);
	            
	            //LoggerLog::debug("LoggerAppenderAdodb::append() query='$query'");
            
            	$this->db->Execute($query);
            }
            catch(Exception $e)
            {
            	var_dump($e);
            }
            
            // close connection
            if ($this->db !== null)
            	$this->db->Close();
        }
    }
    
    function close()
    {
        if ($this->db !== null)
            $this->db->Close();
        $this->closed = true;
    }
    
    /**
     * @return boolean
     */
    function getCreateTable()
    {
        return $this->createTable;
    }
    
    /**
     * @return string the sql pattern string
     */
    function getSql()
    {
        return $this->sql;
    }
    
    /**
     * @return string the table name to create
     */
    function getTable()
    {
        return $this->table;
    }
    
    /**
     * @return string the database to connect to
     */
    function getDatabase() {
        return $this->database;
    }
    
    /**
     * @return string the database to connect to
     */
    function getHost() {
        return $this->host;
    }
    
    /**
     * @return string the user to connect with
     */
    function getUser() {
        return $this->user;
    }
    
    /**
     * @return string the password to connect with
     */
    function getPassword() {
        return $this->password;
    }
    
    /**
     * @return string the type of database to connect to
     */
    function getType() {
        return $this->type;
    }
    
    function setCreateTable($flag)
    {
        $this->createTable = LoggerOptionConverter::toBoolean($flag, true);
    }
    
    function setType($newType)
    {
        $this->type = $newType;
    }
    
    function setDatabase($newDatabase)
    {
        $this->database = $newDatabase;
    }
    
    function setHost($newHost)
    {
        $this->host = $newHost;
    }
    
    function setUser($newUser)
    {
        $this->user = $newUser;
    }
    
    function setPassword($newPassword)
    {
        $this->password = $newPassword;
    }
    
    function setSql($sql)
    {
        $this->sql = $sql;    
    }
    
    function setTable($table)
    {
        $this->table = $table;
    }
    
}
?>