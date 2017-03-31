
<?php

//error_reporting(0);

// Prevent caching
//header("Expires: 0");
//header("cache-control: no-store, no-cache, must-revalidate");
//header("Pragma: no-cache");

// Provides access to REDCap helper functions and database connection.
run_notification_cron();

function run_notification_cron(){


    define('REDCAP_ROOT', '/srv/www/htdocs-insecure/redcap/');
   
    global $conn; // REDCapism
    require_once(REDCAP_ROOT.'redcap_connect.php');

    //define('FRAMEWORK_ROOT', REDCAP_ROOT.'plugins/framework/');
    //require_once(FRAMEWORK_ROOT.'/PluginConfig.php');



	require_once(REDCAP_ROOT.'plugins/notifications-git/utils/records.php');

   
    require_once(REDCAP_ROOT.'plugins/notifications-git/process_notify.php');
    
    error_log("******after requiring process notify************");

    
    
    // $CONFIG = new PluginConfig(dirname(__FILE__).'/notifications.ini');
    require_once(REDCAP_ROOT.'redcap_v6.11.5/Classes/Records.php');

    $query =    "select distinct b.temporal_projects from ".
                "(select record,value as 'type' from redcap.redcap_data ".
                "where project_id =? and field_name =? and value = ?) a ".
                "join ".
                "(select record,value as 'temporal_projects' from ".
                "redcap.redcap_data where project_id = ? and field_name = 'project_id') b ".
                "on b.record = a.record";
    
    $stmt = $conn->stmt_init();
    $stmt->prepare($query);
    $stmt->bind_param($bind_pattern, '155', 'type', '1','155');
    $stmt->execute();
    $stmt->bind_result($project_ids);
    $stmt->fetch();
    $stmt->close();

    echo("after query");

    $project_ids = array();

    while ($row = mysql_fetch_array($result))
	{    
        // print_r($row);
        foreach ($row as $index => $proj ){
            array_push($project_ids, $proj);
        }
	}

    print_r ($project_ids);
	 
    foreach($project_ids as $key => $proj_id)
    {
		   //print_r($proj_id);
       
    		$notifications = get_records_by(
                'project_id',
                $proj_id,
                155,
                $conn
        	 );
        
        
            print_r($notifications);
            foreach($notifications as $notification) 
            {

        		// checking temporal

                    if($notification['notifications_complete'] == 2) 
                    {
			
					    $record_ids = get_record_ids_by('resident_info_complete', 2, $proj_id, $conn);

            	//$record_ids = get_records_ids($proj_id,$conn); // should implement this function

              		    foreach($records_ids as $record_id )
                        {

                      				$record_data = Records::getData('array', $record_id);

                      				process_notification($notification,$event_id, $record_data, $record_id);

                        }

                    }


                }

   	 	    }   

}


?>

