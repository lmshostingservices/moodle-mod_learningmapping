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

function learningmapping_add_instance($data, $mform = null) {
    global $DB;
    learningmapping_pack_framework_fields($data);
    $data->timecreated = time();
    $data->timemodified = time();
    $data->id = $DB->insert_record('learningmapping', $data);
    return $data->id;
}

function learningmapping_update_instance($data, $mform = null) {
    global $DB;
    learningmapping_pack_framework_fields($data);
    $data->timemodified = time();
    $data->id = $data->instance;
    $DB->update_record('learningmapping', $data);
    return true;
}

/**
 * Store mapping state through the module's native compressed persistence format.
 *
 * @param int $mappingid
 * @param array $mappingdata
 * @param string $unitcode
 * @return void
 */
function learningmapping_store_mappingdata(int $mappingid, array $mappingdata, string $unitcode = ''): void {
    global $DB;
    $instance = $DB->get_record('learningmapping', ['id' => $mappingid], 'id', MUST_EXIST);
    if (!isset($mappingdata['rows']) || !is_array($mappingdata['rows'])
            || !isset($mappingdata['columns']) || !is_array($mappingdata['columns'])
            || !isset($mappingdata['cells']) || !is_array($mappingdata['cells'])) {
        throw new \moodle_exception('invalidmappingdata', 'learningmapping');
    }
    $encoded = json_encode($mappingdata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $record = (object)[
        'id' => $instance->id,
        'mappingdata' => \mod_learningmapping\manifest_storage::compress($encoded),
        'timemodified' => time(),
    ];
    if (!empty($mappingdata['unitData'])) {
        $record->unitdata = \mod_learningmapping\manifest_storage::compress(
            json_encode($mappingdata['unitData'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
    if ($unitcode !== '' && strlen($unitcode) <= 20) {
        $record->unitcode = clean_param($unitcode, PARAM_TEXT);
    }
    $DB->update_record('learningmapping', $record);
}

/**
 * Pack framework-specific form controls into the JSON field.
 *
 * @param stdClass $data
 */
function learningmapping_pack_framework_fields($data) {
    $validframeworks = ['vet', 'au_curriculum', 'sg_moe', 'university', 'workplace', 'custom'];
    $framework = isset($data->framework) && in_array($data->framework, $validframeworks, true)
        ? $data->framework : 'vet';
    $fieldsets = [
        'au_curriculum' => ['learningarea', 'strand', 'substrand', 'contentdescriptor', 'yearlevel', 'achievementstandard'],
        'sg_moe' => ['syllabus', 'theme', 'learningoutcome', 'level', 'syllabuscode'],
        'university' => ['course', 'courselearningoutcome', 'creditpoints', 'graduateattributes'],
        'workplace' => ['program', 'requirement', 'role', 'policyreference'],
    ];
    $frameworkdata = [];
    if (isset($fieldsets[$framework])) {
        foreach ($fieldsets[$framework] as $key) {
            $value = isset($data->{'fw_' . $key}) ? clean_param($data->{'fw_' . $key}, PARAM_TEXT) : '';
            if ($value !== '') {
                $frameworkdata[$key] = $value;
            }
        }
    } else if ($framework === 'custom') {
        $levels = [];
        for ($level = 1; $level <= 4; $level++) {
            $label = isset($data->{'fw_customlabel' . $level})
                ? clean_param($data->{'fw_customlabel' . $level}, PARAM_TEXT) : '';
            $value = isset($data->{'fw_customvalue' . $level})
                ? clean_param($data->{'fw_customvalue' . $level}, PARAM_TEXT) : '';
            if ($label !== '' || $value !== '') {
                $levels[] = ['label' => $label, 'value' => $value];
            }
        }
        if ($levels) {
            $frameworkdata['customLevels'] = $levels;
        }
    }
    $data->framework = $framework;
    $data->frameworkdata = json_encode($frameworkdata);
    foreach (['learningarea', 'strand', 'substrand', 'contentdescriptor', 'yearlevel',
        'achievementstandard', 'syllabus', 'theme', 'learningoutcome', 'level', 'syllabuscode',
        'course', 'courselearningoutcome', 'creditpoints', 'graduateattributes', 'program',
        'requirement', 'role', 'policyreference'] as $key) {
        unset($data->{'fw_' . $key});
    }
    for ($level = 1; $level <= 4; $level++) {
        unset($data->{'fw_customlabel' . $level}, $data->{'fw_customvalue' . $level});
    }
}

function learningmapping_delete_instance($id) {
    global $DB;
    if (!$DB->get_record('learningmapping', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('learningmapping', ['id' => $id]);
    return true;
}

function learningmapping_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        default:
            return null;
    }
}
