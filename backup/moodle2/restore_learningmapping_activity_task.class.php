<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/learningmapping/backup/moodle2/restore_learningmapping_stepslib.php');

class restore_learningmapping_activity_task extends restore_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_learningmapping_activity_structure_step('learningmapping_structure', 'learningmapping.xml'));
    }

    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('learningmapping', ['intro'], 'learningmapping');
        return $contents;
    }

    public static function define_decode_rules() {
        $rules = [];
        $rules[] = new restore_decode_rule('LEARNINGMAPPINGVIEWBYID', '/mod/learningmapping/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('LEARNINGMAPPINGINDEX', '/mod/learningmapping/index.php?id=$1', 'course');
        return $rules;
    }

    public static function define_restore_log_rules() {
        return [];
    }

    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
