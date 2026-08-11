<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * mod_learningmapping file.
 *
 * @package    mod_learningmapping
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

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
