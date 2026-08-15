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

// v1.0.46: BUG FIX — FIX-LM-SYNC: analyzeWithAI() was calling action=analyzemapping_async which
//   POSTs to /api/generate-learningmapping/start — an endpoint that does not exist on the production
//   server, causing "Unknown action" errors. Reverted to the direct synchronous analyzemapping action
//   (calls /api/generate-learningmapping with 150s timeout) which works in production.
//   FIX-LM-SELECT: showScanResults() now renders a checkbox on each activity card and adds
//   "Select All / Deselect All" controls. doAnalyse() only sends checked activities to the AI,
//   allowing teachers to filter the scan before running analysis.
//   AMD: mapping.js (src=build=min). No DB schema changes. version.php → 2026042200047.
defined('MOODLE_INTERNAL') || die();

$plugin->component  = 'mod_learningmapping';
$plugin->version    = 2026081500;
$plugin->requires   = 2022041900;
$plugin->maturity   = MATURITY_STABLE;
$plugin->release    = '1.0.52'; // FIX-13DIGIT-SAVEPOINT-REBASE: release rebuilt from repo db/upgrade.php with all gates/savepoints on 10-digit values <= $plugin->version; the previously served ZIP still carried legacy 13-digit savepoints that would silently re-strand rebased sites on the next upgrade. No schema/PHP-logic/JS changes. 
