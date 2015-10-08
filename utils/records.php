<?php

function get_record_id_by($field_name, $value, $pid, $conn) {

    if(is_integer($value)) {
        $bind_pattern = 'isi';
    } else {
        $bind_pattern = 'iss';
    }

    $query = 'SELECT record '.
             'FROM redcap_data '.
             'WHERE project_id=? '.
             'AND field_name=? '.
             'AND value=?';

    $stmt = $conn->stmt_init();
    $stmt->prepare($query);
    $stmt->bind_param($bind_pattern, $pid, $field_name, $value);
    $stmt->execute();
    $stmt->bind_result($record_id);
    $stmt->fetch();
    $stmt->close();

    return $record_id;
}


/*
 * TODO: get_record_id_by() and get_record_ids_by() not DRY
 */
function get_record_ids_by($field_name, $value, $pid, $conn) {

    if(is_integer($value)) {
        $bind_pattern = 'isi';
    } else {
        $bind_pattern = 'iss';
    }

    $record_ids = array();

    $query = 'SELECT record '.
             'FROM redcap_data '.
             'WHERE project_id=? '.
             'AND field_name=? '.
             'AND value=?';

    $stmt = $conn->stmt_init();
    $stmt->prepare($query);
    $stmt->bind_param($bind_pattern, $pid, $field_name, $value);
    $stmt->execute();
    $stmt->bind_result($record_id);
    while($stmt->fetch()) {
        $record_ids[] = $record_id;
    }
    $stmt->close();

    return $record_ids;
}

function get_record_data($record_id, $pid, $conn) {

    $record_data = array();
    
    $query = 'SELECT field_name, value '.
             'FROM redcap_data '.
             'WHERE project_id=? '.
             'AND record=?';

    $stmt = $conn->stmt_init();
    $stmt->prepare($query);
    $stmt->bind_param('ii', $pid, $record_id);
    $stmt->execute();
    $stmt->bind_result($field_name, $value);
    while($stmt->fetch()) {
        $record_data[$field_name] = $value;
    }
    $stmt->close();

    return $record_data;
}

function get_record_by($field_name, $value, $pid, $conn) {
    if($field_name == 'record') {
        return get_record_data($value, $pid, $conn);
    } else {
        return get_record_data(
            get_record_id_by($field_name, $value, $pid, $conn),
            $pid,
            $conn
        );
    }
}

function get_records_by($field_name, $value, $pid, $conn) {
    $record_ids = get_record_ids_by($field_name, $value, $pid, $conn);
    $records = array();
    foreach($record_ids as $record_id) {
        $records[] = get_record_data($record_id, $pid, $conn);
    }
    return $records;

}

function save_redcap_data($redcap_url, $api_token, $data) {
    require_once(dirname(__FILE__).'/RestCallRequest.php');

    $api_request = new RestCallRequest(
        $redcap_url,
        'POST',
        array(
            'content'   => 'record',
            'type'      => 'eav',
            'format'    => 'json',
            'token'     => $api_token,
            'data'      => json_encode($data)
        )
    );
    $api_request->execute();
    $response_info = $api_request->getResponseInfo();
    $error_msg = '';
    if($response_info['http_code'] == 200) {
        return array(true, $error_msg);
    } else {
        $api_response = json_decode($api_request->getResponseBody(), true);
        $error_msg = (isset($api_response['error']) ? $api_response['error'] : 'No error returned.');
        return array(false, $error_msg);
    }
}
?>
