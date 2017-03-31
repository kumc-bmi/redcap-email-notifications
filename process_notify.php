<?php
/**
 * This is an implementation of the process_notification function which is invoked 
 * either by notifications_save_record or process_email_cron for processing the 
 * email request and send out the email notifications.
 */


function process_notification($notification,$event_id, $record_data, 
                              $record,$CONFIG)
{

     // Evaluates REDCap branching logic syntax.
    require_once(APP_PATH_DOCROOT.'Classes/LogicTester.php');
       
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
    
    return Piping::replaceVariablesInLabel(
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

    if($notification['calendar_event'] == RC_YES) {
        $rdata = $record_data[$record][$event_id];
        if($notification['event_all_day'] == RC_YES) {
            $start_datetime = new DateTime(
                $rdata[$notification['event_start_date']]
            );
            $end_datetime = clone $start_datetime;
            $end_datetime->modify("+ 1 day");
        } elseif($notification['event_set_length'] == RC_YES) {
            $start_datetime = new DateTime(
                $rdata[$notification['event_start_datetime']]
            );
            $end_datetime = clone $start_datetime;
            $end_datetime->modify('+ '.$notification['event_length'].' minute');
        } else {
            $start_datetime = new DateTime(
                $rdata[$notification['event_start_datetime']]
            );
            $end_datetime = new DateTime(
                $rdata[$notification['event_end_datetime']]
            );
        }

        send_ical_event(
            $from,
            $to,
            $start_datetime->format('m/d/Y H:i:s'),
            $end_datetime->format('m/d/Y H:i:s'),
            $subject,
            $body,
            $notification['event_location']
        );
    } else {

        if(REDCap::email($to, $from, $subject, $body)) {
            return true;
        } else {
            error_log('Failed to send email notification ...');
            return false;
        }
    }
}

function send_ical_event($from, $to, $start, $end, $subject, $body, $location) {
   $domain = 'kumc.edu';

    //Create Email Headers
    $mime_boundary = "----Meeting Booking----".MD5(TIME());

    $headers = "From: <".$from.">\n";
    $headers .= "Reply-To: <".$from.">\n";
    $headers .= "MIME-Version: 1.0\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$mime_boundary\"\n";
    $headers .= "Content-class: urn:content-classes:calendarmessage\n";

    //Create Email Body (HTML)
    $message = "--$mime_boundary\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\n";
    $message .= "Content-Transfer-Encoding: 8bit\n\n";
    $message .= "<html>\n";
    $message .= "<body>\n";
    $message .= $body;
    $message .= "</body>\n";
    $message .= "</html>\n";
    $message .= "--$mime_boundary\r\n";

    $ical = 'BEGIN:VCALENDAR' . "\r\n" .
    'PRODID:-//Microsoft Corporation//Outlook 10.0 MIMEDIR//EN' . "\r\n" .
    'VERSION:2.0' . "\r\n" .
    'METHOD:REQUEST' . "\r\n" .
    'BEGIN:VTIMEZONE' . "\r\n" .
    'TZID:America/Chicago' . "\r\n" .
    'X-LIC-LOCATION:America/Chicago' . "\r\n" .
    'BEGIN:DAYLIGHT' . "\r\n" .
    'TZOFFSETFROM:-0600' . "\r\n" .
    'TZOFFSETTO:-0500' . "\r\n" .
    'TZNAME:CDT' . "\r\n" .
    'DTSTART:19700308T020000' . "\r\n" .
    'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU' . "\r\n" .
    'END:DAYLIGHT' . "\r\n" .
    'BEGIN:STANDARD' . "\r\n" .
    'TZOFFSETFROM:-0500' . "\r\n" .
    'TZOFFSETTO:-0600' . "\r\n" .
    'TZNAME:CST' . "\r\n" .
    'DTSTART:19701101T020000' . "\r\n" .
    'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU' . "\r\n" .
    'END:STANDARD' . "\r\n" .
    'END:VTIMEZONE' . "\r\n" .
    'BEGIN:VEVENT' . "\r\n" .
    'ORGANIZER;CN="":MAILTO:'.$from. "\r\n" .
    'ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE:MAILTO:'.$to. "\r\n" .
    'LAST-MODIFIED:' . date("Ymd\TGis") . "\r\n" .
    'UID:'.date("Ymd\TGis", strtotime($start)).rand()."@".$domain."\r\n" .
    'DTSTAMP:'.date("Ymd\TGis"). "\r\n" .
    'DTSTART;TZID="America/Chicago":'.date("Ymd\THis", strtotime($start)). "\r\n" .
    'DTEND;TZID="America/Chicago":'.date("Ymd\THis", strtotime($end)). "\r\n" .
    'TRANSP:OPAQUE'. "\r\n" .
    'SEQUENCE:1'. "\r\n" .
    'SUMMARY:' . $subject . "\r\n" .
    'LOCATION:' . $location . "\r\n" .
    'CLASS:PUBLIC'. "\r\n" .
    'PRIORITY:5'. "\r\n" .
    'BEGIN:VALARM' . "\r\n" .
    'TRIGGER:-PT15M' . "\r\n" .
    'ACTION:DISPLAY' . "\r\n" .
    'DESCRIPTION:Reminder' . "\r\n" .
    'END:VALARM' . "\r\n" .
    'END:VEVENT'. "\r\n" .
    'END:VCALENDAR'. "\r\n";
    $message .= 'Content-Type: text/calendar;name="meeting.ics";method=REQUEST'."\n";
    $message .= "Content-Transfer-Encoding: 8bit\n\n";
    $message .= $ical;

    $mailsent = mail($to, $subject, $message, $headers);

    return ($mailsent)?(true):(false); 
}

?>
