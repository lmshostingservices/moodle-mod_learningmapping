<?php
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
