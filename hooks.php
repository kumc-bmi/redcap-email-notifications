<?php
/**
 * This is an implementation of the redcap_save_record hook which sends
 * preconfigured email notifications when an associated REDCap record is save,
 * and trigger conditions are meet.
 */
function notifications_save_record($project_id, $record, $instrument, $event_id,
                                   $group_id, $survey_hash, $response_id)
{
    // Provides access to REDCap helper functions and database connection.
    global $conn; // REDCapism
    require_once(REDCAP_ROOT.'redcap_connect.php');

    // Load configuration plugin configuration.
    require_once(dirname(__FILE__).'/utils/PluginConfig.php');
    $CONFIG = new PluginConfig(dirname(__FILE__).'/notifications.ini');

    // This differs from REDCap's Record class in that project records can be
    // queried for by fields other than record id.
    require_once(dirname(__FILE__).'/utils/records.php');
    
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

    // Get and format submitted record data.
    $record_data = Records::getData('array', $record);

    // Iterate over associated notifications.
    foreach($notifications as $notification) {

        // Continue only if notification record is marked as "complete".
        if($notification['notifications_complete'] == 2) {

            // Prepare notification logic.
            $logic = prepare_logic($notification['logic'], $event_id);

            // Does the given record meet notification logic conditions.
            if(LogicTester::isValid($logic)) {
                if(LogicTester::apply($logic, $record_data[$record])) {
                    // Is a trigger field being used?
                    if($notification['trigger_field']) {
                        $trigger_field = get_field_value(
                            $notification['trigger_field'],
                            $record,
                            $event_id,
                            $record_data
                        );
                        // If the trigger field is blank or Yes send notification
                        if($trigger_field !== 'No') {
                            reset_trigger_field(
                                $record,
                                $event_id,
                                $notification['trigger_field'],
                                $CONFIG['api_url'],
                                $notification['project_token']
                            );
                            send_notification(
                                $notification,
                                $record,
                                $event_id,
                                $record_data
                            );
                        }
                    } else { // No trigger field declared...
                        send_notification(
                            $notification,
                            $record,
                            $event_id,
                            $record_data
                        );
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
}


/**
 * Given a field name or REDCap piping label, get the respective record value.
 *
 * Examples:
 *   field_name
 *   [field_name]
 *   [event_name][field_name]
 */
function get_field_value($label, $record, $event_id, $record_data) {
    // Provides retrival of field values from record using REDCap's pipe syntax.
    require_once(APP_PATH_DOCROOT.'Classes/Piping.php');
    
    // If field name is not flanked by blackets, add them.
    if(substr($label, 0, 1) != '[' or substr($label, -1) != ']') {
        $label = '['.$label.']';
    }

    return  Piping::replaceVariablesInLabel(
        $label,
        $record,
        $event_id,
        $record_data,
        true,
        null,
        false
    );
}


/**
 * Give a string containing REDCap piping syntax, and relevant record data,
 * replace field references with the relavent record value.
 */
function replace_labels_with_values($text, $record, $event_id, $record_data) {
    $pattern = '\[[0-9a-z_]*]\[[0-9a-z_]*]|\[[0-9a-z_]*]';
    preg_match_all('/'.$pattern.'/U', $text, $matches);
    $matches = array_unique($matches);
    foreach($matches[0] as $match) {
        $text = str_replace(
            $match, 
            get_field_value($match, $record, $event_id, $record_data),
            $text
        ); 
    }
    return $text;
}


/**
 * Makes sure that logic contains relevant event prefixes, if project is
 * longitudinal.
 */
function prepare_logic($logic, $event_id) {
    if(REDCap::isLongitudinal()) {
        // Provides Event based helper functions.
        require_once(APP_PATH_DOCROOT.'Classes/Event.php');

        // Returns event eames for the globally specified project :`(
        $event_names = REDCap::getEventNames(true);
        // If longitudinal, prepent event name
        $logic = LogicTester::logicPrependEventName(
            $logic,
            $event_names[$event_id]
        );
    }
    return $logic;
}


/**
 * "Reset" a specified trigger field to "No".
 */
function reset_trigger_field($record, $event_id, $trigger_field, $api_url,
                             $api_token)
{
    $trigger_reset = array(array(
        'record' => $record,
        'field_name' => $trigger_field,
        'value' => 0
    ));

    if(REDCap::isLongitudinal()) {
        // Provides Event based helper functions.
        require_once(APP_PATH_DOCROOT.'Classes/Event.php');

        $event_names = REDCap::getEventNames(true);
        $trigger_reset[0]['redcap_event_name'] = $event_names[$event_id];
    }

    list($success, $error_msg) = save_redcap_data(
        $api_url,
        $api_token,
        $trigger_reset
    );

    if(!$success) {
        error_log('Failed to reset notification trigger field: '.$error_msg);
        return false;
    }

    return true;
}


/**
 * Format and send an email notification.
 */
function send_notification($notification, $record, $event_id, $record_data) {
    if($notification['to_address_type'] == 'static') {
        $to = $notification['static_to_address'];
    } else {
        $to = get_field_value(
            $notification['to_address_field'],
            $record,
            $event_id,
            $record_data
        );
    }

    if($notification['from_address_type'] == 'static') {
        $from = $notification['static_from_address'];
    } else {
        $from = get_field_value(
            $notification['from_address_field'],
            $record,
            $event_id,
            $record_data
        );
    }

    $subject = replace_labels_with_values(
        $notification['subject'],
        $record,
        $event_id,
        $record_data
    );

    $body = replace_labels_with_values(
        $notification['body'],
        $record,
        $event_id,
        $record_data
    );

    if(REDCap::email($to, $from, $subject, $body)) {
        return true;
    } else {
        error_log('Failed to send email notification ...');
        return false;
    }
}
?>
