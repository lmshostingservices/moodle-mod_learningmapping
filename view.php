<?php
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
