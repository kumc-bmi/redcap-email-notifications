<?php
/**
 * This is an implementation of the redcap_save_record hook which sends
 * preconfigured email notifications when an associated REDCap record is save,
 * and trigger conditions are meet.This notification_save_record function
 * further invokes process_notification to send out email notifications
 */
define('RC_YES', 1);
define('RC_NO', 0);
define('RC_FORM_COMPLETE', 2);

function notifications_save_record($project_id, $record, $instrument, $event_id,
                                   $group_id, $survey_hash, $response_id)
{
    // Provides access to REDCap helper functions and database connection.
    global $conn; // REDCapism
    require_once(REDCAP_ROOT.'redcap_connect.php');

    // Load configuration plugin configuration.
    define('FRAMEWORK_ROOT', REDCAP_ROOT.'plugins/framework/');
    require_once(FRAMEWORK_ROOT.'/PluginConfig.php');
  
    $CONFIG = new PluginConfig(dirname(__FILE__).'/notifications.ini');

    // This differs from REDCap's Record class in that project records can be
    // queried for by fields other than record id.
    require_once(dirname(__FILE__).'/utils/records.php');
    
    // Inorder to call the process_notification method.
    require_once(dirname(__FILE__).'/process_notify.php');

    // Get notifications associated with the given project.
    $notifications = get_records_by(
        'project_id',
        $project_id,
        $CONFIG['notifications_pid'],
        $conn
    );

    // Return if no notifications found
    if(empty($notifications)) { return; }

    // Provides properly formated REDCap record data for use with LogicTester
    // in process_notification function.
    require_once(APP_PATH_DOCROOT.'Classes/Records.php');

    // Get and format submitted record data.
    $record_data = Records::getData('array', $record);
   
    foreach($notifications as $notification) {

        // Continue only if notification record is marked as "complete".
        if($notification['notifications_complete'] == 2) {

           //Evaluates the logic and sends out the notifications
           process_notification($notification, $event_id, $record_data, $record);

        }    

    }
}

?>
