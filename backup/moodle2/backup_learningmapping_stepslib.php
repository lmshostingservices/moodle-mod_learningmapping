<?php
defined('MOODLE_INTERNAL') || die();

class backup_learningmapping_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $learningmapping = new backup_nested_element('learningmapping', ['id'], [
            'name', 'intro', 'introformat', 'unitcode',
            'unitdata', 'mappingdata', 'timecreated', 'timemodified',
        ]);

        $learningmapping->set_source_table('learningmapping', ['id' => backup::VAR_ACTIVITYID]);

        return $this->prepare_activity_structure($learningmapping);
    }
}
