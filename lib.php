<?php
defined('MOODLE_INTERNAL') || die();

function learningmapping_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = time();
    $data->id = $DB->insert_record('learningmapping', $data);
    return $data->id;
}

function learningmapping_update_instance($data, $mform = null) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;
    $DB->update_record('learningmapping', $data);
    return true;
}

function learningmapping_delete_instance($id) {
    global $DB;
    if (!$DB->get_record('learningmapping', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('learningmapping', ['id' => $id]);
    return true;
}

function learningmapping_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        default:
            return null;
    }
}
