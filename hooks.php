<?php
/**
 * This is a prototype implementation of the redcap_save_record hook which sends 
 * email notifications ...
 */
function notifications_save_record($project_id, $record, $instrument, $event_id,
                                   $group_id, $survey_hash, $response_id)
{
    // Provides access to REDCap helper functions and database connection.
    global $conn; // REDCapism
    require_once('../../redcap_connect.php');
    // Evaluates REDCap branching logic syntax.
    require_once(APP_PATH_DOCROOT.'Classes/LogicTester.php');
    // Provides properly formated REDCap record data for use with LogicTester.
    require_once(APP_PATH_DOCROOT.'Classes/Records.php');
    // Procodes Event based helper functions.
    require_once(APP_PATH_DOCROOT.'Classes/Event.php');

    // This differs from REDCap's Record in that I can query for records in a 
    // given project by a field other than record id.
    require_once('records.php');

    // This needs to be move to some sort of config.
    define('NOTIFICATION_PID', 155);
    
    // Does the given project have associated notifications.
    $notifications = get_records_by('project_id', $project_id, NOTIFICATION_PID,
                                     $conn);

    // Iterate over notifications associated with the given project.
    foreach($notifications as $notification) {

        // Prepare Notification Logic and requisites.
        $logic = $notification['logic'];
        if(REDCap::isLongitudinal()) {
            $event_names = REDCap::getEventNames(true);
            $logic = LogicTester::logicPrependEventName(
                $logic,
                $event_names[$event_id]
            );
        }

        // Get and format submitted record data.
        $saved_data = Records::getData('array', $record);
        $fields = array_keys($saved_data[$record][$event_id]);
        $fields = array_map(
            parameterize,
            $fields
        );
        $values = array_values($saved_data[$record][$event_id]);

        // Does the given record meet notification logic conditions.
        if(LogicTester::isValid($logic)) {
            if(LogicTester::apply($logic, $saved_data[$record])) {
                if(REDCap::email(
                    $notification['static_to_address'],
                    $notification['from_address'],
                    str_replace($fields, $values, $notification['subject']),
                    str_replace($fields, $values, $notification['body'])
                )) {
                    return;
                } else {
                    error_log('Failed to send email notification ...');
                }
            }
        } else {
            // Log that notification logic is invalid.
            error_log(
                'Invalid notification logic in notification defined by '
                .'(pid:'.NOTIFICATION_PID.'; rid:'.$notification['record_id'].') '
                .'originating from action on '
                .'(pid:'.$project_id.'; rid:'.$record.')'
            );
        }
    }
}

function parameterize($fieldname) {
    return '['.$fieldname.']';
}
?>
