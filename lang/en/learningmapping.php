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

$string['modulename'] = 'AI Mapping';
$string['modulenameplural'] = 'AI Mappings';
$string['modulename_help'] = 'The AI Mapping activity creates an interactive mapping table linking training package elements and performance criteria to course learning content and assessments. Supports TGA paste import, course activity scanning, custom rows and columns, CSV and PDF export.';
$string['pluginname'] = 'AI Mapping';
$string['invalidmappingdata'] = 'The mapping data is incomplete or invalid.';
$string['pluginadministration'] = 'AI Mapping administration';
$string['learningmapping:addinstance'] = 'Add an AI Mapping';
$string['learningmapping:view'] = 'View AI Mapping';
$string['learningmapping:manage'] = 'Manage AI Mapping';
$string['name'] = 'Mapping name';
$string['unitcode'] = 'Unit code';
$string['unitcode_help'] = 'Enter the training.gov.au unit code (e.g. BSBCRT511). This will be used to fetch unit details for the mapping table.';
$string['framework'] = 'Curriculum framework';
$string['framework_help'] = 'Choose the source framework for the requirements being mapped. VET remains the default and retains the training.gov.au workflow.';
$string['frameworkvet'] = 'VET';
$string['frameworkaucurriculum'] = 'Australian Curriculum';
$string['frameworksgmoe'] = 'Singapore MOE';
$string['frameworkuniversity'] = 'University';
$string['frameworkworkplace'] = 'Workplace';
$string['frameworkcustom'] = 'Custom';
$string['learningarea'] = 'Learning area';
$string['strand'] = 'Strand';
$string['substrand'] = 'Sub-strand';
$string['contentdescriptor'] = 'Content descriptor';
$string['yearlevel'] = 'Year level';
$string['achievementstandard'] = 'Achievement standard';
$string['syllabus'] = 'Syllabus';
$string['theme'] = 'Theme';
$string['learningoutcome'] = 'Learning outcome';
$string['level'] = 'Level';
$string['syllabuscode'] = 'Syllabus code';
$string['course'] = 'Course';
$string['courselearningoutcome'] = 'Course learning outcome';
$string['creditpoints'] = 'Credit points';
$string['graduateattributes'] = 'Graduate attributes';
$string['program'] = 'Program';
$string['requirement'] = 'Requirement';
$string['role'] = 'Role';
$string['policyreference'] = 'Policy reference';
$string['customlevellabel'] = 'Custom level {$a} name';
$string['customlevelvalue'] = 'Custom level {$a} value';
$string['aisettings'] = 'AI Analysis Settings';
$string['aisettingsdesc'] = 'Configure the connection to lms-labs.com for AI-powered mapping analysis. If Central Config is installed, these settings are used as fallbacks.';
$string['siteid'] = 'Site ID';
$string['siteiddesc'] = 'Your lms-labs.com Site ID. Found in your account dashboard.';
$string['apikey'] = 'API Key';
$string['apikeydesc'] = 'Your lms-labs.com API Key. Found in your account dashboard.';
$string['creditinfo'] = 'Credit Information';
$string['privacy:metadata'] = 'The AI Mapping plugin stores mapping configuration data only.';
$string['privacy:metadata:learningmapping:course'] = 'The course ID.';
$string['privacy:metadata:learningmapping:name'] = 'The mapping name.';
$string['privacy:metadata:learningmapping:mappingdata'] = 'The mapping table data stored as JSON.';
