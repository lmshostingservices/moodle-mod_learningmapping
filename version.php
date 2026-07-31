<?php
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
$plugin->version    = 2026072300230;
$plugin->requires   = 2022041900;
$plugin->maturity   = MATURITY_STABLE;
$plugin->release    = '1.0.50';
