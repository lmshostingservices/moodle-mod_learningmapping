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
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT);
$l  = optional_param('l', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('learningmapping', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $mapping = $DB->get_record('learningmapping', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($l) {
    $mapping = $DB->get_record('learningmapping', ['id' => $l], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $mapping->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('learningmapping', $mapping->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learningmapping:view', $context);

$PAGE->set_url('/mod/learningmapping/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($mapping->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$PAGE->requires->css('/mod/learningmapping/styles/mapping.css');

$canmanage = has_capability('mod/learningmapping:manage', $context);
$courseid = $course->id;
$mappingid = $mapping->id;
$unitcode = $mapping->unitcode ?? '';
$saveddata = \mod_learningmapping\manifest_storage::decompress($mapping->mappingdata ?? '{}');

$PAGE->requires->js_call_amd('mod_learningmapping/mapping', 'init', [
    $mappingid,
    $courseid,
    $unitcode,
    $saveddata,
    $canmanage,
    sesskey()
]);

echo $OUTPUT->header();

echo '<div id="lm-app" class="lm-container">';
echo '  <div id="lm-loading" class="lm-loading-screen">';
echo '    <div class="lm-loading-spinner"></div>';
echo '    <p>Loading AI Mapping...</p>';
echo '  </div>';
echo '</div>';

echo $OUTPUT->footer();
