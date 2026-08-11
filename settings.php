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
 * AI Mapping - Admin settings
 *
 * Note: Site ID and API Key are managed via AI Grader Central Config (local_aiconfig).
 * These fallback settings are only used if Central Config is not installed.
 *
 * @package    mod_learningmapping
 * @copyright  2025 Essay Grader AI
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $centralconfigurl = new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']);
    $centralconfiginstalled = file_exists($CFG->dirroot . '/local/aiconfig/version.php');

    if ($centralconfiginstalled) {
        $settings->add(new admin_setting_heading(
            'mod_learningmapping/centralconfig_notice',
            '',
            '<div style="padding: 12px; background: #ecfdf5; border: 1px solid #10b981; border-radius: 8px; margin-bottom: 16px;">' .
            '<strong style="color: #047857;">AI Grader Central Config is installed.</strong><br>' .
            'Site ID and API Key are managed centrally. ' .
            '<a href="' . $centralconfigurl->out() . '">Configure Central Settings</a>' .
            '</div>'
        ));
    } else {
        $settings->add(new admin_setting_heading(
            'mod_learningmapping/centralconfig_notice',
            '',
            '<div style="padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; margin-bottom: 16px;">' .
            '<strong style="color: #b45309;">Recommended: Install AI Grader Central Config</strong><br>' .
            'Configure Site ID and API Key once for all AI Grader plugins. ' .
            '<a href="https://lms-labs.com/docs/ai-central-config" target="_blank">Learn more</a>' .
            '</div>'
        ));
    }

    $settings->add(new admin_setting_heading(
        'mod_learningmapping/aiheading',
        get_string('aisettings', 'learningmapping'),
        get_string('aisettingsdesc', 'learningmapping')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_learningmapping/siteid',
        get_string('siteid', 'learningmapping'),
        get_string('siteiddesc', 'learningmapping') . ($centralconfiginstalled ? ' (Fallback - Central Config takes priority)' : ''),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_learningmapping/apikey',
        get_string('apikey', 'learningmapping'),
        get_string('apikeydesc', 'learningmapping') . ($centralconfiginstalled ? ' (Fallback - Central Config takes priority)' : ''),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'mod_learningmapping/creditinfo',
        get_string('creditinfo', 'learningmapping'),
        '<div style="padding: 12px; background: #eff6ff; border: 1px solid #3b82f6; border-radius: 8px;">' .
        '<strong style="color: #1d4ed8;">AI Analysis Credit Cost</strong><br>' .
        'Each AI Analyse click costs <strong>100 credits ($10.00 AUD)</strong>. ' .
        'AI only fills empty cells — existing entries are preserved. ' .
        '<a href="https://lms-labs.com/pricing" target="_blank">Buy Credits</a>' .
        '</div>'
    ));
}
