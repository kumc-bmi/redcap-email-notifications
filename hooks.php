<?php
/**
 * This is a prototype implementation of the redcap_save_record hook which send
 * email notifications ...
 */


function notifications_save_record($project_id, $record, $instrument, $event_id,
                                   $group_id, $survey_hash, $response_id)
{
    define('PLUGIN_ROOT', realpath(REDCAP_ROOT.'plugins/'));
    
    // Provides access to REDCap helper functions and database connection.
    global $conn; // REDCapism
    require_once(REDCAP_ROOT.'redcap_connect.php');

    // Load configuration...
    // TODO: PluginConfig needs to be localized or generalized.
    require_once(PLUGIN_ROOT.'/repower/utils/PluginConfig.php');
    $CONFIG = new PluginConfig(dirname(__FILE__).'/config.ini');

    // This differs from REDCap's Record in that project records can be query 
    // for fields other than record id.
    require_once('records.php');
    
    // Get notifications associated with the given project.
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
    // Provides Event based helper functions.
    require_once(APP_PATH_DOCROOT.'Classes/Event.php');
    // Provides retrival of field values from record using REDCap's pipe syntax.
    require_once(APP_PATH_DOCROOT.'Classes/Piping.php');

    // Get and format submitted record data.
    $raw_data = Records::getData('array', $record);
    $event_data = $raw_data[$record]; // Should be record
    $record_data = $event_data[$event_id]; // Should be event ... or field_data

    // Iterate over associated notifications.
    foreach($notifications as $notification) {

        // Prepare notification logic.
        $logic = prepare_logic($notification['logic']);

        // Does the given record meet notification logic conditions.
        if(LogicTester::isValid($logic)) {
            if(LogicTester::apply($logic, $event_data)) {
                // Is a trigger field being used?
                if($notification['trigger_field']) {
                    // If the trigger field is blank or 'Yes' send notification
                    if($record_data[$notification['trigger_field']] !== '0') {
                        reset_trigger_field(
                            $record,
                            $notification['trigger_field'],
                            $CONFIG['api_url'],
                            $notification['project_token']
                        );
                        send_notification($notification, $record_data);
                    }
                } else { // No trigger field declared...
                    send_notification($notification, $record_data);
                }
            }
        } else {
            // Log that notification logic is invalid.
            error_log(
                'Invalid notification logic in notification defined by '
                .'(pid:'.$CONFIG['notifications_pid'].'; '
                .'rid:'.$notification['record_id'].') '
                .'originating from action on '
                .'(pid:'.$project_id.'; rid:'.$record.')'
            );
        }
    }
}

/**
 * Helper functions for notifications_save_record
 */
function get_field_value($label, $record, $event_id, $raw_data) {
    // If field name is not flanked by blackets, add them.
    if(substr($label, 0, 1) != '[' or substr($label, -1) != ']') {
        $label = '['.$label.']';
    }

    return Piping::replaceVariablesInLabel(
        $label,
        $record,
        $event_id,
        $raw_data,
        true,
        $project_id,
        false
    );
}

function prepare_logic($logic) {
    if(REDCap::isLongitudinal()) {
        // Returns event eames for the globally specified project
        $event_names = REDCap::getEventNames(true);
        // If longitudinal, prepent event name
        $logic = LogicTester::logicPrependEventName(
            $logic,
            $event_names[$event_id]
        );
    }
    return $logic;
}

function prepare_fields_and_values($record_data) {
    $fields = array_keys($record_data);
    $fields = array_map(
        parameterize,
        $fields
    );
    $values = array_values($record_data);
    return array($fields, $values);
}

function parameterize($fieldname) {
    return '['.$fieldname.']';
}

function reset_trigger_field($record, $trigger_field, $api_url, $api_token) {
    $trigger_reset = array(array(
        'record' => $record,
        'field_name' => $trigger_field,
        'value' => 0
    ));

    list($success, $error_msg) = save_redcap_data(
        $api_url,
        $api_token,
        $trigger_reset
    );

    if(!$success) {
        error_log('Failed to reset sent notification flag.');
        return false;
    }

    return true;
}

function send_notification($notification, $record_data) {
    print_r($notification);
    if($notification['to_address_type'] == 'static') {
        $to = $notification['static_to_address'];
    } else {
        $to = $record_data[$notification['to_address_field']];
    }

    if($notification['from_address_type'] == 'static') {
        $from = $notification['static_from_address'];
    } else {
        $from = $record_data[$notification['from_address_field']];
    }

    // Prepare record fields and values for use...
    list($fields, $values) = prepare_fields_and_values($record_data);

    if(REDCap::email(
        $to,
        $from,
        str_replace($fields, $values, $notification['subject']),
        str_replace($fields, $values, $notification['body'])
    )) {
        return true;
    } else {
        error_log('Failed to send email notification ...');
        return false;
    }
}
?>
