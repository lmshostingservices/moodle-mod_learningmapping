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

function xmldb_learningmapping_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026032200100) {
        // v1.0.0: Initial release.
        upgrade_mod_savepoint(true, 2026032200100, 'learningmapping');
    }

    if ($oldversion < 2026032200201) {
        // v1.0.2: FIX button hover text colour contrast. No DB changes.
        upgrade_mod_savepoint(true, 2026032200201, 'learningmapping');
    }

    if ($oldversion < 2026032200202) {
        // v1.0.3: Stats bar shows per-column counts for KE, PE, FS, AC. No DB changes.
        upgrade_mod_savepoint(true, 2026032200202, 'learningmapping');
    }

    if ($oldversion < 2026032200203) {
        // v1.0.4: Removed 4 TGA unit columns (KE, PE, FS, AC) from default layout.
        // Stats bar now dynamically counts all non-element columns. No DB changes.
        upgrade_mod_savepoint(true, 2026032200203, 'learningmapping');
    }

    if ($oldversion < 2026032200204) {
        // v1.0.5: FIX Scan Course button stuck on "Scanning..." — added error recovery,
        // dataType:'json', and errorCallback to reset button on failure. No DB changes.
        upgrade_mod_savepoint(true, 2026032200204, 'learningmapping');
    }

    if ($oldversion < 2026032200205) {
        // v1.0.6: Sticky column headers, robust AJAX error handling with table-exists
        // check for Essay Maker, mobile-friendly responsive layout. No DB changes.
        upgrade_mod_savepoint(true, 2026032200205, 'learningmapping');
    }

    if ($oldversion < 2026032200206) {
        // v1.0.7: Release bump. No DB changes.
        upgrade_mod_savepoint(true, 2026032200206, 'learningmapping');
    }

    if ($oldversion < 2026032200207) {
        // v1.0.8: Allow deleting any column (not just custom). No DB changes.
        upgrade_mod_savepoint(true, 2026032200207, 'learningmapping');
    }

    if ($oldversion < 2026032200208) {
        // v1.0.9: TGA parser extracts KE/PE/FS/AC sections. Scan Course now populates
        // table cells with scanned activity data. Stats bar reads from parsed TGA sections.
        upgrade_mod_savepoint(true, 2026032200208, 'learningmapping');
    }

    if ($oldversion < 2026032200209) {
        // v1.0.10: Release bump. No DB changes.
        upgrade_mod_savepoint(true, 2026032200209, 'learningmapping');
    }

    if ($oldversion < 2026032200210) {
        // v1.0.11: Shade row as heading — toggle any row to display as a shaded heading band.
        upgrade_mod_savepoint(true, 2026032200210, 'learningmapping');
    }

    if ($oldversion < 2026032200211) {
        // v1.0.12: VERSION BUMP — Maintenance release.
        upgrade_mod_savepoint(true, 2026032200211, 'learningmapping');
    }

    if ($oldversion < 2026032200213) {
        // v1.0.13: AI Analyse — OpenAI-powered mapping of course content to TGA elements.
        // Credit confirmation dialog, pre-balance check via getcredits. No DB changes.
        upgrade_mod_savepoint(true, 2026032200213, 'learningmapping');
    }

    if ($oldversion < 2026032200214) {
        // v1.0.14: Release bump — credit confirmation dialog, pre-balance check. No DB changes.
        upgrade_mod_savepoint(true, 2026032200214, 'learningmapping');
    }

    if ($oldversion < 2026032200215) {
        // v1.0.15: Settings page — added Site ID, API Key fields with Central Config
        // integration, credit info panel. No DB changes.
        upgrade_mod_savepoint(true, 2026032200215, 'learningmapping');
    }

    if ($oldversion < 2026032200216) {
        // v1.0.16: TGA parser now populates KE, PE, FS, AC as rows in the mapping table.
        // No DB changes.
        upgrade_mod_savepoint(true, 2026032200216, 'learningmapping');
    }

    if ($oldversion < 2026032200217) {
        // v1.0.17: AI prompt improvements — short codes only, skip element headings,
        // essay quiz questions map to Essay Maker column. Client-side guard on element rows.
        // No DB changes.
        upgrade_mod_savepoint(true, 2026032200217, 'learningmapping');
    }

    if ($oldversion < 2026032200218) {
        // v1.0.18: TGA parser cleanup — filters boilerplate preamble, footer junk,
        // stops at Assessment Conditions. Only KE, PE, FS as rows. No DB changes.
        upgrade_mod_savepoint(true, 2026032200218, 'learningmapping');
    }

    if ($oldversion < 2026032200219) {
        // v1.0.19: Major AI mapping quality overhaul — row hierarchy context in prompt,
        // KC criteria/topic tags used for direct PC matching, Foundation Skills explicit
        // mapping instructions, KE/PE generous mapping, numbered activity codes in scan,
        // parentId in schema validation. No DB changes.
        upgrade_mod_savepoint(true, 2026032200219, 'learningmapping');
    }

    if ($oldversion < 2026032200220) {
        // v1.0.20: Version bump for deployment. No DB changes.
        upgrade_mod_savepoint(true, 2026032200220, 'learningmapping');
    }

    if ($oldversion < 2026032200221) {
        // v1.0.21: Parser overhaul — supports simplified/cleaned TGA format (plain text
        // PCs without X.X codes auto-numbered), "Assessment Requirements" as wrapper
        // section for PE/KE, Assessment Conditions as section-skip not full-stop.
        // No DB changes.
        upgrade_mod_savepoint(true, 2026032200221, 'learningmapping');
    }

    if ($oldversion < 2026032200222) {
        // v1.0.22: Guided workflow UI (5-step stepper), Fetch from TGA button,
        // insert row below any row, custom confirm/toast popups. No DB changes.
        upgrade_mod_savepoint(true, 2026032200222, 'learningmapping');
    }

    if ($oldversion < 2026032300223) {
        // v1.0.23: AI cell sanitisation — short codes only (T1, Q1, Act 1).
        // Auto-parse after TGA fetch. Assessment Conditions section. No DB changes.
        upgrade_mod_savepoint(true, 2026032300223, 'learningmapping');
    }

    if ($oldversion < 2026032300224) {
        // v1.0.24: Renamed to AI Learning and Assessment Mapping. No DB changes.
        upgrade_mod_savepoint(true, 2026032300224, 'learningmapping');
    }

    if ($oldversion < 2026032300225) {
        // v1.0.25: Renamed to AI Mapping. No DB changes.
        upgrade_mod_savepoint(true, 2026032300225, 'learningmapping');
    }

    if ($oldversion < 2026032300226) {
        // v1.0.26: BUMP — clean release. No DB changes.
        upgrade_mod_savepoint(true, 2026032300226, 'learningmapping');
    }

    if ($oldversion < 2026032300227) {
        // v1.0.27: AI prompt nuclear short-code fix. Sanitisation now returns empty on garbage instead of truncated text.
        upgrade_mod_savepoint(true, 2026032300227, 'learningmapping');
    }

    if ($oldversion < 2026032300228) {
        // v1.0.28: Dynamic column creation from scan. Replaced hardcoded colMap with ACTIVITY_COL_DEFS.
        // Scan now auto-adds Assignments, Quizzes, Lessons, Books, Resources, Forums, Workshops columns as needed.
        upgrade_mod_savepoint(true, 2026032300228, 'learningmapping');
    }

    if ($oldversion < 2026032300229) {
        // v1.0.29: BUMP — clean release. No DB changes.
        upgrade_mod_savepoint(true, 2026032300229, 'learningmapping');
    }

    if ($oldversion < 2026032300230) {
        // v1.0.30: FIX — TGA Fetch now correctly populates PCs from table-layout DOCXs. No DB changes.
        upgrade_mod_savepoint(true, 2026032300230, 'learningmapping');
    }

    if ($oldversion < 2026032400201) {
        // v1.0.35: Column sync on Scan and AI Analyse (syncColumnsForModules).
        //          Auto-removes stale empty columns when activities are deleted.
        //          Auto-adds columns when AI Analyse runs without a prior Scan. No DB changes.
        upgrade_mod_savepoint(true, 2026032400201, 'learningmapping');
    }

    if ($oldversion < 2026032400202) {
        // v1.0.36: ACCURACY BUG FIXES:
        //   BUG-LM-SAVEMAPPING: syncColumnsForModules() called saveMapping() (undefined).
        //     Fixed to call save() — the correct save function. Caused silent ReferenceError
        //     on every Scan Course and AI Analyse that changed columns.
        //   BUG-LM-HEADER: Content-Type JSON header now set before auth/DB code.
        //     ajax.php wrapped in try/catch so any init failure returns JSON, not HTML.
        //   BUG-LM-UNITDATA: save case now reads unitData from correct path
        //     ($payload['mappingdata']['unitData']) instead of missing top-level key.
        //   All three AMD build files synced. No DB schema changes.
        upgrade_mod_savepoint(true, 2026032400202, 'learningmapping');
    }

    if ($oldversion < 2026032400203) {
        // v1.0.37: UI BUG FIXES (6 bugs):
        //   BUG-LM-BTNCSS: AI Analyse button invisible (undefined .lm-btn-ai class). Fixed.
        //   BUG-LM-SAVERESP: save() AJAX success handler ignored error responses. Fixed.
        //   BUG-LM-STATSFILL: Completion % used wrong cell value logic. Fixed.
        //   BUG-LM-STATSEC: Stats KE/PE/FS counts always showed 0. Fixed by deriving from rows.
        //   BUG-LM-COLHEADER: Column title overlapped remove button. Fixed padding-right:30px.
        //   BUG-LM-UNITCODE: unitCode never persisted to DB on save. Fixed. No schema changes.
        upgrade_mod_savepoint(true, 2026032400203, 'learningmapping');
    }

    if ($oldversion < 2026032401203) {
        // v1.0.38: COLUMN HEADING BUG FIXES (5 bugs):
        //   BUG-LM-BLANKPRE: defaultColumns pre-populated 4 generic columns before scan. Fixed.
        //   BUG-LM-ACTNAME: Column titles used generic type names instead of mod.name. Fixed.
        //   BUG-LM-CMIDKEY: Multiple activities of same type collapsed to one column. Fixed via
        //     cmid-based column identity (col_cm_{cmid}).
        //   BUG-LM-BCCOMPAT: Legacy column IDs (col_cc etc.) adopted instead of replaced. Fixed.
        //   BUG-LM-QUIZPLURAL: quiz mapped to 'Quizzes' (plural). Corrected. No schema changes.
        upgrade_mod_savepoint(true, 2026032401203, 'learningmapping');
    }

    if ($oldversion < 2026032401204) {
        // v1.0.39: ASSESSMENT CONDITIONS ARCHITECTURE REDESIGN:
        //   AC items no longer placed in state.rows. Stored in state.acData instead.
        //   Dedicated AC Compliance Checklist table rendered below main mapping table.
        //   analyzeWithAI() no longer receives AC rows in its payload.
        //   Backward-compat migration: old saves with AC in state.rows auto-extracted to acData.
        //   Stats bar shows AC Met counter. CSV/PDF exports include AC section.
        //   No DB schema changes (acData persisted inside existing mappingdata JSON column).
        upgrade_mod_savepoint(true, 2026032401204, 'learningmapping');
    }

    if ($oldversion < 2026032401205) {
        // v1.0.40: CLEAN VERSION BUMP. No code changes. No DB schema changes.
        upgrade_mod_savepoint(true, 2026032401205, 'learningmapping');
    }

    if ($oldversion < 2026032401206) {
        // v1.0.41: ASYNC GENERATION — Eliminated Replit proxy 120s timeout failures.
        //   JS calls action=analyzemapping_async → PHP hits Express /api/moodle/learningmapping/start
        //   → returns {jobId} in ~500ms. JS polls action=poll_job every 3s → Express
        //   GET /api/jobs/:jobId. When status=done, mappings array processed identically to
        //   the former sync response. Internal loopback bypasses proxy hard limit. No DB changes.
        upgrade_mod_savepoint(true, 2026032401206, 'learningmapping');
    }

    if ($oldversion < 2026032401242) {
        // v1.0.42: VERSION BUMP — Clean release following master release process.
        //   No code changes beyond v1.0.41. No DB schema changes.
        upgrade_mod_savepoint(true, 2026032401242, 'learningmapping');
    }

    if ($oldversion < 2026032401243) {
        // v1.0.43: DBWRITE AUDIT FIX — All write operations in ajax.php (savemapping,
        //   analyzemapping, analyzemapping_async, poll_job) now enforce require_sesskey()
        //   and capability checks before modifying the DB. No DB schema changes.
        upgrade_mod_savepoint(true, 2026032401243, 'learningmapping');
    }

    if ($oldversion < 2026032401244) {
        // v1.0.44: AI ANALYSE AC — New "AI Analyse" button on Assessment Conditions
        //   checklist. Scans course activities and auto-populates "How Addressed" and
        //   "Evidence/Resources" fields with concise ASQA-compliant evidence descriptions.
        //   New ajax action: analyseac. New server endpoint: /api/analyse-assessment-conditions.
        //   No DB schema changes.
        upgrade_mod_savepoint(true, 2026032401244, 'learningmapping');
    }

    if ($oldversion < 2026041600045) {
        // v1.0.45: BUG FIX — UNKNOWN ACTION on AI Analyse.
        //   Root cause: ajax.php line 8 used PARAM_ALPHA to read the 'action' parameter.
        //   PARAM_ALPHA strips all non-alphabetic characters including underscores, so
        //   'analyzemapping_async' was silently rewritten to 'analyzemappingasync' and
        //   'poll_job' to 'polljob' before reaching the switch statement. Neither
        //   matched any case, falling through to default: "Unknown action". The AC Analyse
        //   button (action=analyseac) worked because it has no underscores.
        //   Fix: PARAM_ALPHA → PARAM_ALPHANUMEXT (allows letters, digits, underscores).
        //   Single-line change. No DB schema changes. version.php → 2026041600045.
        upgrade_mod_savepoint(true, 2026041600045, 'learningmapping');
    }
    // v1.0.47: AMD ENCODING FIX: All non-ASCII characters (em dashes, arrows, box-drawing chars, ellipsis, bullets, emoji, accented Latin) scrubbed from all AMD JS files (amd/src, amd/build, amd/build/*.min.js). Root cause of Moodle primary/secondary navigation menus disappearing site-wide: non-ASCII bytes in any installed plugin's AMD file cause a SyntaxError inside RequireJS's first.js bundle, throwing "No define call for core/first" and aborting the entire AMD module chain. No PHP, DB schema, or functional changes in this release.
    if ($oldversion < 2026042200047) {
        upgrade_mod_savepoint(true, 2026042200047, 'learningmapping');
    }

    if ($oldversion < 2026072300228) {
        // FIX-API-DOMAIN: Updated all API endpoint URLs from lms-labs.com to lms-labs.com.
        // lms-labs.com has no DNS resolution from Moodle server side; lms-labs.com is the
        // correct working domain. All ajax.php, api_client, unlock_verifier, lib.php calls updated.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_mod_savepoint(true, 2026072300228, 'learningmapping');
    }

    if ($oldversion < 2026072300229) {
        // FIX-API-DOMAIN: Reverted API endpoint to lms-labs.com (correct domain).
        // essaygraderai.app was the original single-plugin domain; lms-labs.com is correct.
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_mod_savepoint(true, 2026072300229, 'learningmapping');
    }

    if ($oldversion < 2026072300230) {
        // Domain update: lms-labs.com → lms-labs.com
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['version.php', 'lib.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) { opcache_invalidate($_full, true); }
            }
        } elseif (function_exists('opcache_reset')) { opcache_reset(); }
        upgrade_mod_savepoint(true, 2026072300230, 'learningmapping');
    }

    return true;
}