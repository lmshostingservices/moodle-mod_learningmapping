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

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $action    = required_param('action', PARAM_ALPHANUMEXT);
    $mappingid = required_param('mappingid', PARAM_INT);
    $sesskey   = required_param('sesskey', PARAM_RAW);

    if (!confirm_sesskey($sesskey)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid session key']);
        die();
    }

    $mapping = $DB->get_record('learningmapping', ['id' => $mappingid], '*', MUST_EXIST);
    $course  = $DB->get_record('course', ['id' => $mapping->course], '*', MUST_EXIST);
    $cm      = get_coursemodule_from_instance('learningmapping', $mapping->id, $course->id, false, MUST_EXIST);

    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    die();
}

$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}

$siteid = '';
$apikey = '';
if (function_exists('local_aiconfig_get_siteid')) {
    $siteid = trim(local_aiconfig_get_siteid() ?? '');
}
if (function_exists('local_aiconfig_get_apikey')) {
    $apikey = trim(local_aiconfig_get_apikey() ?? '');
}
if (empty($siteid)) {
    $siteid = trim(get_config('mod_learningmapping', 'siteid') ?? '');
}
if (empty($apikey)) {
    $apikey = trim(get_config('mod_learningmapping', 'apikey') ?? '');
}

$apibase = 'https://lms-labs.com';

switch ($action) {

    case 'getcredits':
        require_capability('mod/learningmapping:manage', $context);
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode([
                'ok' => false,
                'error' => 'Plugin not configured: Missing Site ID or API Key. Go to Site admin > Plugins > AI Grader Central Config.',
            ]);
            break;
        }
        $url = $apibase . '/api/credits?' . http_build_query([
            'siteId' => $siteid,
            'apiKey' => $apikey,
        ], '', '&');
        $curl = new curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15]);
        $response = $curl->get($url);
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        if ($httpcode >= 200 && $httpcode < 300) {
            $result = json_decode($response, true);
            if (isset($result['credits'])) {
                echo json_encode(['ok' => true, 'credits' => $result['credits']]);
            } elseif (isset($result['creditsRaw'])) {
                echo json_encode(['ok' => true, 'credits' => $result['creditsRaw']]);
            } elseif (isset($result['balance'])) {
                echo json_encode(['ok' => true, 'credits' => $result['balance']]);
            } else {
                echo json_encode(['ok' => true, 'credits' => $result['creditsRemaining'] ?? 0]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to fetch credits (HTTP ' . $httpcode . ')']);
        }
        break;

    case 'analyzemapping':
        require_capability('mod/learningmapping:manage', $context);
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not configured: Missing Site ID or API Key.']);
            break;
        }
        $rawdata = file_get_contents('php://input');
        $payload = json_decode($rawdata, true);
        if (!$payload) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request payload']);
            break;
        }
        $payload['siteId'] = $siteid;
        $payload['apiKey'] = $apikey;

        \core\session\manager::write_close();

        $url = $apibase . '/api/generate-learningmapping';
        $curl = new curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 150,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $response = $curl->post($url, json_encode($payload));
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        if ($httpcode >= 200 && $httpcode < 300) {
            $result = json_decode($response, true);
            if ($result && isset($result['ok']) && $result['ok']) {
                echo json_encode($result);
            } else {
                echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Unknown API error']);
            }
        } else {
            $errResult = json_decode($response, true);
            $errMsg = $errResult['error'] ?? ('API error: HTTP ' . $httpcode);
            echo json_encode(['ok' => false, 'error' => $errMsg]);
        }
        break;

    // Analyse Assessment Conditions against scanned course activities.
    case 'analyseac':
        require_capability('mod/learningmapping:manage', $context);
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not configured: Missing Site ID or API Key.']);
            break;
        }
        $rawdata = file_get_contents('php://input');
        $payload = json_decode($rawdata, true);
        if (!$payload) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request payload']);
            break;
        }
        $payload['siteId'] = $siteid;
        $payload['apiKey'] = $apikey;

        \core\session\manager::write_close();

        $url = $apibase . '/api/analyse-assessment-conditions';
        $curl = new curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 90,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $response = $curl->post($url, json_encode($payload));
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        if ($httpcode >= 200 && $httpcode < 300) {
            $result = json_decode($response, true);
            if ($result && isset($result['ok']) && $result['ok']) {
                echo json_encode($result);
            } else {
                echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Unknown API error']);
            }
        } else {
            $errResult = json_decode($response, true);
            $errMsg = $errResult['error'] ?? ('API error: HTTP ' . $httpcode);
            echo json_encode(['ok' => false, 'error' => $errMsg]);
        }
        break;

    // ASYNC: Start mapping analysis job — returns jobId immediately.
    // JS polls case 'poll_job' every 3-4s until status=done.
    case 'analyzemapping_async':
        require_capability('mod/learningmapping:manage', $context);
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not configured: Missing Site ID or API Key.']);
            break;
        }
        $rawdata = file_get_contents('php://input');
        $payload = json_decode($rawdata, true);
        if (!$payload) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request payload']);
            break;
        }
        $payload['siteId'] = $siteid;
        $payload['apiKey'] = $apikey;

        \core\session\manager::write_close();

        $startUrl = $apibase . '/api/generate-learningmapping/start';
        $curl = new curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15, 'CURLOPT_HTTPHEADER' => ['Content-Type: application/json']]);
        $resp = json_decode($curl->post($startUrl, json_encode($payload)), true);

        if (empty($resp['ok']) || empty($resp['jobId'])) {
            echo json_encode(['ok' => false, 'error' => $resp['error'] ?? 'Failed to start mapping analysis']);
            break;
        }
        echo json_encode(['ok' => true, 'jobId' => $resp['jobId'], 'async' => true]);
        break;

    // ASYNC POLL: Check background job status.
    case 'poll_job':
        $jobId = required_param('jobId', PARAM_ALPHANUMEXT);
        \core\session\manager::write_close();
        $curl = new curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 10]);
        $resp = json_decode($curl->get($apibase . '/api/jobs/' . urlencode($jobId)), true);
        echo json_encode($resp ?: ['ok' => false, 'status' => 'error', 'error' => 'Could not reach job status endpoint']);
        break;

    case 'fetchtga':
        require_capability('mod/learningmapping:manage', $context);
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not configured: Missing Site ID or API Key.']);
            break;
        }
        $rawdata = file_get_contents('php://input');
        $payload = json_decode($rawdata, true);
        if (!$payload || empty($payload['unitCode'])) {
            echo json_encode(['ok' => false, 'error' => 'No unit code provided']);
            break;
        }
        $fetchPayload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'unitCode' => $payload['unitCode'],
        ];

        \core\session\manager::write_close();

        $url = $apibase . '/api/tga/unit-for-mapping';
        $curl = new curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $response = $curl->post($url, json_encode($fetchPayload));
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        if ($httpcode >= 200 && $httpcode < 300) {
            $result = json_decode($response, true);
            if ($result && isset($result['ok']) && $result['ok']) {
                echo json_encode($result);
            } else {
                echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Unknown API error']);
            }
        } else {
            $errResult = json_decode($response, true);
            $errMsg = $errResult['error'] ?? ('API error: HTTP ' . $httpcode);
            echo json_encode(['ok' => false, 'error' => $errMsg]);
        }
        break;

    case 'cleanpaste':
        require_capability('mod/learningmapping:manage', $context);
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode(['ok' => false, 'error' => 'Plugin not configured: Missing Site ID or API Key.']);
            break;
        }
        $rawdata = file_get_contents('php://input');
        $payload = json_decode($rawdata, true);
        if (!$payload || empty($payload['rawText'])) {
            echo json_encode(['ok' => false, 'error' => 'No text provided']);
            break;
        }
        $cleanPayload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'rawText' => $payload['rawText'],
        ];

        \core\session\manager::write_close();

        $url = $apibase . '/api/clean-tga-paste';
        $curl = new curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);
        $response = $curl->post($url, json_encode($cleanPayload));
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        if ($httpcode >= 200 && $httpcode < 300) {
            $result = json_decode($response, true);
            if ($result && isset($result['ok']) && $result['ok']) {
                echo json_encode($result);
            } else {
                echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Unknown API error']);
            }
        } else {
            $errResult = json_decode($response, true);
            $errMsg = $errResult['error'] ?? ('API error: HTTP ' . $httpcode);
            echo json_encode(['ok' => false, 'error' => $errMsg]);
        }
        break;

    case 'scancourse':
        require_capability('mod/learningmapping:manage', $context);
        $result = [];
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();

        $aiPluginTypes = ['contentcreator', 'aiactivities', 'aiknowledgecheck', 'practicalassessment'];
        $standardTypes = ['assign', 'quiz', 'lesson', 'book', 'page', 'resource', 'url', 'forum', 'workshop', 'glossary', 'wiki', 'h5pactivity', 'scorm', 'lti'];

        foreach ($cms as $cmod) {
            $modname = $cmod->modname;
            if ($modname === 'learningmapping') continue;

            $isAiPlugin = in_array($modname, $aiPluginTypes);
            $isStandard = in_array($modname, $standardTypes);
            if (!$isAiPlugin && !$isStandard) continue;

            try {
                $instance = $DB->get_record($modname, ['id' => $cmod->instance]);
            } catch (Exception $e) {
                continue;
            }
            if (!$instance) continue;

            $entry = [
                'cmid' => $cmod->id,
                'modname' => $modname,
                'name' => format_string($cmod->name),
                'topics' => [],
            ];

            if ($modname === 'contentcreator' && !empty($instance->manifestjson)) {
                $manifest = json_decode($instance->manifestjson, true);
                if ($manifest && isset($manifest['topics'])) {
                    foreach ($manifest['topics'] as $topic) {
                        $topicEntry = ['title' => $topic['title'] ?? 'Untitled'];
                        $topicEntry['sections'] = [];
                        if (isset($topic['sections'])) {
                            foreach ($topic['sections'] as $sec) {
                                $topicEntry['sections'][] = $sec['title'] ?? 'Untitled Section';
                            }
                        }
                        $entry['topics'][] = $topicEntry;
                    }
                }
            }

            if ($modname === 'aiknowledgecheck') {
                try {
                    $questions = $DB->get_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $instance->id], 'id ASC');
                    $qs = [];
                    foreach ($questions as $q) {
                        $qs[] = [
                            'id' => $q->id,
                            'question' => $q->questiontext ?? '',
                            'topic' => $q->mappingtopic ?? '',
                            'criteria' => $q->mappingcriteria ?? '',
                        ];
                    }
                    $entry['questions'] = $qs;
                } catch (Exception $e) {
                    $entry['questions'] = [];
                }
            }

            if ($modname === 'aiactivities') {
                if (!empty($instance->activitiesjson)) {
                    $acts = json_decode($instance->activitiesjson, true);
                    if (is_array($acts)) {
                        $actList = [];
                        foreach ($acts as $a) {
                            $actList[] = [
                                'type' => $a['type'] ?? 'unknown',
                                'title' => $a['title'] ?? $a['topic'] ?? 'Untitled',
                            ];
                        }
                        $entry['activities'] = $actList;
                    }
                }
            }

            if ($modname === 'assign') {
                $rawIntro = $instance->intro ?? '';
                // Extended description — 2000 chars gives enough context for full assessment descriptions.
                $entry['description'] = !empty($rawIntro) ? trim(strip_tags(substr($rawIntro, 0, 2000))) : '';

                // Detect iframes embedded in the intro (e.g. practicalassessment or external smartphone forms).
                if (!empty($rawIntro)) {
                    preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $rawIntro, $iframeMatches);
                    if (!empty($iframeMatches[1])) {
                        $entry['embeddedUrls'] = array_values(array_unique($iframeMatches[1]));
                    }
                }

                // Submission statement (declaration students must agree to).
                if (!empty($instance->submissionstatement)) {
                    $entry['submissionInstructions'] = trim(strip_tags(substr($instance->submissionstatement, 0, 1000)));
                }

                // Read assign_plugin_config to detect submission plugin types.
                try {
                    $pluginConfigs = $DB->get_records('assign_plugin_config', ['assignment' => $instance->id]);
                    $submissionPlugins = [];
                    foreach ($pluginConfigs as $cfg) {
                        if ($cfg->name === 'enabled' && $cfg->value == 1) {
                            $submissionPlugins[] = $cfg->plugin;
                        }
                    }
                    if (!empty($submissionPlugins)) {
                        $entry['submissionPlugins'] = $submissionPlugins;
                    }
                } catch (Exception $e) {
                    // Skip if assign_plugin_config not accessible.
                }
            }

            if ($modname === 'practicalassessment') {
                // Unit identity.
                if (!empty($instance->unitcode)) {
                    $entry['unitcode'] = $instance->unitcode;
                }
                if (!empty($instance->unitname)) {
                    $entry['unitname'] = $instance->unitname;
                }
                if (!empty($instance->industry)) {
                    $entry['industry'] = $instance->industry;
                }
                if (!empty($instance->jobrole)) {
                    $entry['jobrole'] = $instance->jobrole;
                }

                // Introduction text.
                if (!empty($instance->intro)) {
                    $entry['description'] = trim(strip_tags(substr($instance->intro, 0, 800)));
                }

                // Workplace scenario (assessor-facing context for what students must do).
                if (!empty($instance->scenario_text)) {
                    $entry['scenario'] = trim(substr($instance->scenario_text, 0, 1500));
                }

                // Skills checklist — each skill is an observable practical behaviour.
                // Structure: [{id, description, criteria:[elementCode, ...]}, ...]
                if (!empty($instance->skills_json)) {
                    $skills = json_decode($instance->skills_json, true);
                    if (is_array($skills)) {
                        $skillList = [];
                        foreach ($skills as $skill) {
                            $desc = is_string($skill) ? $skill : ($skill['description'] ?? $skill['text'] ?? '');
                            if ($desc) {
                                $skillEntry = ['description' => $desc];
                                if (!empty($skill['criteria']) && is_array($skill['criteria'])) {
                                    $skillEntry['criteria'] = $skill['criteria'];
                                }
                                $skillList[] = $skillEntry;
                            }
                        }
                        if (!empty($skillList)) {
                            $entry['skills'] = $skillList;
                        }
                    }
                }

                // Workplace forms — documentary evidence required for the assessment.
                // Structure: [{id, title, purpose, fields:[...]}, ...]
                if (!empty($instance->forms_json)) {
                    $forms = json_decode($instance->forms_json, true);
                    if (is_array($forms)) {
                        $formList = [];
                        foreach ($forms as $form) {
                            $title = is_string($form) ? $form : ($form['title'] ?? $form['name'] ?? '');
                            if ($title) {
                                $formEntry = ['title' => $title];
                                if (!empty($form['purpose'])) {
                                    $formEntry['purpose'] = $form['purpose'];
                                }
                                $formList[] = $formEntry;
                            }
                        }
                        if (!empty($formList)) {
                            $entry['workplaceForms'] = $formList;
                        }
                    }
                }

                // Mapping matrix — pre-mapped performance criteria to evidence.
                // Structure: [{criterion, evidence, source}, ...]
                if (!empty($instance->mapping_json)) {
                    $mappings = json_decode($instance->mapping_json, true);
                    if (is_array($mappings)) {
                        $mappingHints = [];
                        foreach ($mappings as $m) {
                            if (!empty($m['criterion']) && !empty($m['evidence'])) {
                                $mappingHints[] = [
                                    'criterion' => $m['criterion'],
                                    'evidence' => $m['evidence'],
                                    'source' => $m['source'] ?? 'Observation',
                                ];
                            }
                        }
                        if (!empty($mappingHints)) {
                            $entry['mappingHints'] = $mappingHints;
                        }
                    }
                }

                // Occasions (how many times the student must demonstrate competency).
                if (!empty($instance->occasions) && $instance->occasions > 1) {
                    $entry['occasions'] = (int)$instance->occasions;
                }
            }

            if ($modname === 'quiz') {
                $entry['description'] = !empty($instance->intro) ? strip_tags(substr($instance->intro, 0, 500)) : '';
                try {
                    $quizQuestions = $DB->get_records_sql(
                        "SELECT q.id, q.questiontext, q.name, q.qtype
                         FROM {quiz_slots} qs
                         JOIN {question_references} qr ON qr.component = 'mod_quiz' AND qr.questionarea = 'slot' AND qr.itemid = qs.id
                         JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                         JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                         JOIN {question} q ON q.id = qv.questionid
                         WHERE qs.quizid = ?
                         ORDER BY qs.slot ASC",
                        [$instance->id]
                    );
                    if ($quizQuestions) {
                        $qs = [];
                        foreach ($quizQuestions as $q) {
                            $qs[] = [
                                'id' => $q->id,
                                'question' => strip_tags($q->questiontext ?? $q->name ?? ''),
                                'type' => $q->qtype ?? '',
                            ];
                        }
                        $entry['questions'] = $qs;
                    }
                } catch (Exception $e) {
                    try {
                        $quizQuestions = $DB->get_records_sql(
                            "SELECT q.id, q.questiontext, q.name, q.qtype
                             FROM {quiz_slots} qs
                             JOIN {question} q ON q.id = qs.questionid
                             WHERE qs.quizid = ?
                             ORDER BY qs.slot ASC",
                            [$instance->id]
                        );
                        if ($quizQuestions) {
                            $qs = [];
                            foreach ($quizQuestions as $q) {
                                $qs[] = [
                                    'id' => $q->id,
                                    'question' => strip_tags($q->questiontext ?? $q->name ?? ''),
                                    'type' => $q->qtype ?? '',
                                ];
                            }
                            $entry['questions'] = $qs;
                        }
                    } catch (Exception $e2) {
                        // Skip quiz questions if query fails.
                    }
                }
            }

            if ($modname === 'lesson') {
                $entry['description'] = !empty($instance->intro) ? strip_tags(substr($instance->intro, 0, 500)) : '';
                try {
                    $pages = $DB->get_records('lesson_pages', ['lessonid' => $instance->id], 'ordering ASC', 'id, title, qtype');
                    if ($pages) {
                        $pageList = [];
                        foreach ($pages as $p) {
                            $pageList[] = ['title' => $p->title ?? '', 'type' => $p->qtype ?? ''];
                        }
                        $entry['pages'] = $pageList;
                    }
                } catch (Exception $e) {
                    // Skip if lesson_pages not available.
                }
            }

            if ($modname === 'book') {
                $entry['description'] = !empty($instance->intro) ? strip_tags(substr($instance->intro, 0, 500)) : '';
                try {
                    $chapters = $DB->get_records('book_chapters', ['bookid' => $instance->id], 'pagenum ASC', 'id, title, subchapter');
                    if ($chapters) {
                        $chapterList = [];
                        foreach ($chapters as $ch) {
                            $chapterList[] = ['title' => $ch->title ?? '', 'subchapter' => !empty($ch->subchapter)];
                        }
                        $entry['chapters'] = $chapterList;
                    }
                } catch (Exception $e) {
                    // Skip if book_chapters not available.
                }
            }

            if (in_array($modname, ['page', 'resource', 'url', 'forum', 'workshop', 'glossary', 'wiki', 'h5pactivity', 'scorm', 'lti'])) {
                $entry['description'] = !empty($instance->intro) ? strip_tags(substr($instance->intro, 0, 500)) : '';
            }

            $sectionNum = $cmod->sectionnum ?? null;
            if ($sectionNum !== null) {
                try {
                    $sectionRecord = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionNum], 'name');
                    if ($sectionRecord && !empty($sectionRecord->name)) {
                        $entry['sectionName'] = format_string($sectionRecord->name);
                    }
                } catch (Exception $e) {
                    // Skip if section name unavailable.
                }
            }

            $result[] = $entry;
        }

        try {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_essaymaker')) {
                $essaymaker = $DB->get_records('local_essaymaker', ['course' => $course->id]);
                foreach ($essaymaker as $em) {
                    $entry = [
                        'cmid' => 0,
                        'modname' => 'essaymaker',
                        'name' => $em->name ?? 'AI Quiz',
                        'questions' => [],
                    ];
                    if (!empty($em->questionsjson)) {
                        $emqs = json_decode($em->questionsjson, true);
                        if (is_array($emqs)) {
                            foreach ($emqs as $eq) {
                                $entry['questions'][] = [
                                    'id' => $eq['id'] ?? 0,
                                    'question' => $eq['question'] ?? $eq['text'] ?? '',
                                ];
                            }
                        }
                    }
                    $result[] = $entry;
                }
            }
        } catch (Exception $e) {
            // Essay Maker not installed — skip silently.
        }

        echo json_encode(['success' => true, 'modules' => $result]);
        break;

    case 'save':
        require_capability('mod/learningmapping:manage', $context);
        $rawdata = file_get_contents('php://input');
        $payload = json_decode($rawdata, true);
        if (!$payload || !isset($payload['mappingdata'])) {
            echo json_encode(['error' => 'Invalid payload']);
            die();
        }
        $DB->set_field('learningmapping', 'mappingdata', \mod_learningmapping\manifest_storage::compress(json_encode($payload['mappingdata'])), ['id' => $mappingid]);
        $DB->set_field('learningmapping', 'timemodified', time(), ['id' => $mappingid]);
        if (isset($payload['mappingdata']['unitData'])) {
            $DB->set_field('learningmapping', 'unitdata', \mod_learningmapping\manifest_storage::compress(json_encode($payload['mappingdata']['unitData'])), ['id' => $mappingid]);
        }
        if (!empty($payload['unitCode'])) {
            $unitcodeClean = clean_param($payload['unitCode'], PARAM_TEXT);
            if (strlen($unitcodeClean) <= 20) {
                $DB->set_field('learningmapping', 'unitcode', $unitcodeClean, ['id' => $mappingid]);
            }
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
