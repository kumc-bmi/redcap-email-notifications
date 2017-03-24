<?php
/**
 * This is an implementation of the redcap_save_record hook which sends
 * preconfigured email notifications when an associated REDCap record is save,
 * and trigger conditions are meet.
 */
define('RC_YES', 1);
define('RC_NO', 0);
define('RC_FORM_COMPLETE', 2);

function notifications_save_record($project_id, $record, $instrument, $event_id,
                                   $group_id, $survey_hash, $response_id)
{
    // Provides access to REDCap helper functions and database connection.
    print "hello";
    error_log("**********************************ENTERED NOTIFICATION FUNCTION*****************************");

    global $conn; // REDCapism
    require_once(REDCAP_ROOT.'redcap_connect.php');

    // Load configuration plugin configuration.
    //define('FRAMEWORK_ROOT', REDCAP_ROOT.'plugins/framework/');
    define('NOTIFICATIONS_ROOT', REDCAP_ROOT.'plugins/notifications-git/'); 
    //require_once(FRAMEWORK_ROOT.'/PluginConfig.php');
    require_once(NOTIFICATIONS_ROOT.'utils/records.php');
    require_once(NOTIFICATIONS_ROOT.'process_notify.php');

    $CONFIG = new PluginConfig(NOTIFICATIONS_ROOT.'notifications.ini');

    $notifications = get_records_by(
        'project_id',
        $project_id,
        $CONFIG['notifications_pid'],
        $conn
    );

    // Return if no notifications found
    if(empty($notifications)) { return; }

    // Evaluates REDCap branching logic syntax.
    require_once(APP_PATH_DOCROOT.'Classes/LogicTester.php');
    // Provides properly formated REDCap record data for use with LogicTester.
    require_once(APP_PATH_DOCROOT.'Classes/Records.php');

    // Get and format submitted record data.
    $record_data = Records::getData('array', $record);
   
    foreach($notifications as $notification) {

        // Continue only if notification record is marked as "complete".
        if($notification['notifications_complete'] == 2) {

       
           process_notification($notification, $event_id, $record_data, $record);

        }    // Prepare notification logic.

    }
}

?>
