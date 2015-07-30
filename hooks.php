<?php
/**
 * This is a prototype implementation of the redcap_save_record hook which sends email
 * notifications ...
 */
function notifications_save_record($project_id, $record, $instrument, $event_id,
                                   $group_id, $survey_hash, $response_id)
{
    global $conn;
    require_once('../../redcap_connect.php');

    require_once('records.php');
    define('NOTIFICATION_PID', 155);
    
    // 1. Does the project have associated notifications.
    $notifications = get_records_by('project_id', $project_id, NOTIFICATION_PID, $conn);

    // 2. Iterate over associated notifications.
    foreach($notifications as $notification) {

        // 2a. Get record data
        $record_data = get_record_by('record', $record, $project_id, $conn);

        // 2b. Parse notification logic
        $fields = array_keys($record_data);
        $values = array_values($record_data);
        $fields = array_map(
            parameterize,
            $fields
        );
        $fields = array_merge($fields, array(
            '=',
            '<>'
        ));
        $values = array_merge($values, array(
            '==',
            '!=',
        ));
        $logic = str_replace($fields, $values, $notification['logic']);
        // 2b. Does the given record match the User Action notification logic.
        if(eval("return $logic;")) {
            // 2c. If so, generate email.
            $to = $notification['static_to_address'];
            $subject = str_replace($fields, $values, $notification['subject']);
            $body = str_replace($fields, $values, $notification['body']);

            // 2d. Send email.
            REDCap::email(
                $to,
                $notification['from_address'],
                $subject,
                $body
            );
        }
    }
}

function parameterize($fieldname) {
    return '['.$fieldname.']';
}
?>
