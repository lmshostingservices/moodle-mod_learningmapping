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

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);

$PAGE->set_url('/mod/learningmapping/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname) . ': ' . get_string('modulenameplural', 'learningmapping'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'learningmapping'));

$mappings = get_all_instances_in_course('learningmapping', $course);

if (empty($mappings)) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'learningmapping')),
        new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = ['#', get_string('name'), get_string('description')];
$table->align = ['center', 'left', 'left'];

$currentsection = '';
$i = 0;
foreach ($mappings as $mapping) {
    $i++;
    $link = html_writer::link(
        new moodle_url('/mod/learningmapping/view.php', ['id' => $mapping->coursemodule]),
        format_string($mapping->name)
    );
    $description = format_text($mapping->intro, $mapping->introformat);
    $table->data[] = [$i, $link, $description];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
