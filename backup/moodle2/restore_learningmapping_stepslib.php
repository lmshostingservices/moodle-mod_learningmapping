<?php
defined('MOODLE_INTERNAL') || die();

class restore_learningmapping_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [];
        $paths[] = new restore_path_element('learningmapping', '/activity/learningmapping');
        return $this->prepare_activity_structure($paths);
    }

    protected function process_learningmapping($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->timecreated = time();
        $data->timemodified = time();

        $newitemid = $DB->insert_record('learningmapping', $data);
        $this->apply_activity_instance($newitemid);
    }

    protected function after_execute() {
        $this->add_related_files('mod_learningmapping', 'intro', null);
    }
}
