
<?php

//error_reporting(0);

// Prevent caching
//header("Expires: 0");
//header("cache-control: no-store, no-cache, must-revalidate");
//header("Pragma: no-cache");

// Provides access to REDCap helper functions and database connection.
  
//function run_notification_cron(){
  
	error_log("**********************************ENTERED RESEVAL CRON FUNCTION *****************************");
	global $conn; // REDCapism
        define('REDCAP_ROOT', '/srv/www/htdocs-insecure/redcap/');

	//require_once(REDCAP_ROOT.'redcap_connect.php');
        // Load configuration plugin configuration.
	define('FRAMEWORK_ROOT', REDCAP_ROOT.'plugins/framework/');
	require_once(FRAMEWORK_ROOT.'/PluginConfig.php');
	require_once(REDCAP_ROOT.'plugins/notifications-git/utils/records.php');
	require_once(REDCAP_ROOT.'plugins/notifications-git/process_notify.php');
	$CONFIG = new PluginConfig(dirname(__FILE__).'/notifications.ini');
	require_once(REDCAP_ROOT.'redcap_v6.11.5/Classes/Records.php');

	// Connect to DB
	$db_conn_file = dirname(__FILE__) . '/database.php';

	include ($db_conn_file);
	if (!isset($hostname) || !isset($db) || !isset($username) || !isset($password))
	{
      	exit("There is not a valid hostname ($hostname) / database ($db) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file].");
	}
	
	$conn = mysql_connect($hostname,$username, $password);
	
	if (!$conn)
	{
        	exit("The hostname ($hostname) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file] could not connect to the server. Please check their values.");		}	
      
        $db_selected  = mysql_select_db($db,$conn);

	if (!$db_selected)
	{
        	exit("The hostname ($hostname) / database ($db) / username ($username) / password (XXXXXX) combination in your database connection file [$db_conn_file] could not connect to the server.");
	}

	// Get all the project_ids which have notifications set up

        // gives all the project ids that have temporal notifications set

	$result = mysql_query("select distinct b.temporal_projects from (select record,value as 'type' from redcap.redcap_data where project_id = 155 and field_name = 'type' and value = '1') a
			       join (select record,value as 'temporal_projects' from redcap.redcap_data where project_id = 155 and field_name = 'project_id') b on b.record = a.record");

//	print_r($result);

        $project_ids = array();

	while ($row = mysql_fetch_array($result))
	{    
               // print_r($row);
                foreach ($row as $index => $proj ){
	        	array_push($project_ids, $proj);
               }
	}
        
        print_r ($project_ids);
	 
        foreach($project_ids as $key => $proj_id){
		
		print_r($proj_id);
         
    		$notifications = get_records_by(
                	            'project_id',
                        	    $proj_id,
                            	    $CONFIG['notifications_pid'],
                            	    $conn
        	         	 );
                print_r($notifications);
    		foreach($notifications as $notification) {

        		// checking temporal
        		if($notification['type'] == 1){

             			if($notification['notifications_complete'] == 2) {
			
					$record_ids = get_record_ids_by('resident_info_complete', 2, $proj_id, $conn);

            		 		//$record_ids = get_records_ids($proj_id,$conn); // should implement this function

             		  		foreach($records_ids as $record_id ){

                      				$record_data = Records::getData('array', $record_id);

                      				process_notification($notification,$event_id, $record_data, $record_id);

                               		 }		
           			 }
       		 	}	
   	 	}

	}	

//}

?>

