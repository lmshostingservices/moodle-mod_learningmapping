<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/learningmapping/backup/moodle2/backup_learningmapping_stepslib.php');

class backup_learningmapping_activity_task extends backup_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new backup_learningmapping_activity_structure_step('learningmapping_structure', 'learningmapping.xml'));
    }

    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search = '/(' . $base . '\/mod\/learningmapping\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@LEARNINGMAPPINGINDEX*$2@$', $content);

        $search = '/(' . $base . '\/mod\/learningmapping\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@LEARNINGMAPPINGVIEWBYID*$2@$', $content);

        return $content;
    }
}
