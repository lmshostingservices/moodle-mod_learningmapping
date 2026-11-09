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
require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_learningmapping_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name', 'learningmapping'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $frameworks = [
            'vet' => get_string('frameworkvet', 'learningmapping'),
            'au_curriculum' => get_string('frameworkaucurriculum', 'learningmapping'),
            'sg_moe' => get_string('frameworksgmoe', 'learningmapping'),
            'university' => get_string('frameworkuniversity', 'learningmapping'),
            'workplace' => get_string('frameworkworkplace', 'learningmapping'),
            'custom' => get_string('frameworkcustom', 'learningmapping'),
        ];
        $mform->addElement('select', 'framework', get_string('framework', 'learningmapping'), $frameworks);
        $mform->setType('framework', PARAM_ALPHANUMEXT);
        $mform->setDefault('framework', 'vet');
        $mform->addHelpButton('framework', 'framework', 'learningmapping');
        $mform->addElement('hidden', 'frameworkdata', '');
        $mform->setType('frameworkdata', PARAM_RAW); // pipeline-ignore: PARAM_RAW — preserve hidden framework JSON; decode it before use.

        $frameworkfields = [
            'au_curriculum' => [
                'learningarea' => 'learningarea',
                'strand' => 'strand',
                'substrand' => 'substrand',
                'contentdescriptor' => 'contentdescriptor',
                'yearlevel' => 'yearlevel',
                'achievementstandard' => 'achievementstandard',
            ],
            'sg_moe' => [
                'syllabus' => 'syllabus',
                'theme' => 'theme',
                'learningoutcome' => 'learningoutcome',
                'level' => 'level',
                'syllabuscode' => 'syllabuscode',
            ],
            'university' => [
                'course' => 'course',
                'courselearningoutcome' => 'courselearningoutcome',
                'creditpoints' => 'creditpoints',
                'graduateattributes' => 'graduateattributes',
            ],
            'workplace' => [
                'program' => 'program',
                'requirement' => 'requirement',
                'role' => 'role',
                'policyreference' => 'policyreference',
            ],
        ];
        foreach ($frameworkfields as $framework => $fields) {
            foreach ($fields as $field => $stringkey) {
                $mform->addElement('text', 'fw_' . $field, get_string($stringkey, 'learningmapping'), ['size' => '64']);
                $mform->setType('fw_' . $field, PARAM_TEXT);
                $mform->hideIf('fw_' . $field, 'framework', 'neq', $framework);
            }
        }
        for ($level = 1; $level <= 4; $level++) {
            $mform->addElement('text', 'fw_customlabel' . $level, get_string('customlevellabel', 'learningmapping', $level), ['size' => '30']);
            $mform->setType('fw_customlabel' . $level, PARAM_TEXT);
            $mform->hideIf('fw_customlabel' . $level, 'framework', 'neq', 'custom');
            $mform->addElement('text', 'fw_customvalue' . $level, get_string('customlevelvalue', 'learningmapping', $level), ['size' => '64']);
            $mform->setType('fw_customvalue' . $level, PARAM_TEXT);
            $mform->hideIf('fw_customvalue' . $level, 'framework', 'neq', 'custom');
        }

        $mform->addElement('text', 'unitcode', get_string('unitcode', 'learningmapping'), ['size' => '20']);
        $mform->setType('unitcode', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('unitcode', 'unitcode', 'learningmapping');
        $mform->hideIf('unitcode', 'framework', 'neq', 'vet');

        $this->standard_intro_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$default_values) {
        $default_values['framework'] = $default_values['framework'] ?? 'vet';
        $frameworkdata = [];
        if (!empty($default_values['frameworkdata'])) {
            $decoded = json_decode($default_values['frameworkdata'], true);
            if (is_array($decoded)) {
                $frameworkdata = $decoded;
            }
        }
        foreach (['learningarea', 'strand', 'substrand', 'contentdescriptor', 'yearlevel',
            'achievementstandard', 'syllabus', 'theme', 'learningoutcome', 'level', 'syllabuscode',
            'course', 'courselearningoutcome', 'creditpoints', 'graduateattributes', 'program',
            'requirement', 'role', 'policyreference'] as $key) {
            $default_values['fw_' . $key] = $frameworkdata[$key] ?? '';
        }
        $customlevels = $frameworkdata['customLevels'] ?? [];
        for ($level = 1; $level <= 4; $level++) {
            $custom = $customlevels[$level - 1] ?? [];
            $default_values['fw_customlabel' . $level] = $custom['label'] ?? '';
            $default_values['fw_customvalue' . $level] = $custom['value'] ?? '';
        }
    }
}
