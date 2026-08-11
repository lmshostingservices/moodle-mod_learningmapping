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

namespace mod_learningmapping\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('learningmapping', [
            'course' => 'privacy:metadata:learningmapping:course',
            'name' => 'privacy:metadata:learningmapping:name',
            'mappingdata' => 'privacy:metadata:learningmapping:mappingdata',
        ], 'privacy:metadata');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        return new \core_privacy\local\request\contextlist();
    }

    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {
    }
}
