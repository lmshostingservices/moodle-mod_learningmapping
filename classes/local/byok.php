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

namespace mod_learningmapping\local;

/**
 * Bring your own AI: run mapping analysis on this site's own AI instead of LMS Labs credits.
 *
 * A port of the LMS Labs /api/generate-learningmapping (and its /start + /api/jobs pair),
 * /api/analyse-assessment-conditions and /api/clean-tga-paste services: the same prompts for every
 * framework, the same clean-up of the AI's answer and the same responses, run through the shared engine
 * in AI Grader Central Config (local_aiconfig 1.1.0+). Course data goes from this site straight to the
 * site's AI provider; nothing is sent to LMS Labs and no credits are spent. The prompts are generated
 * from the service source and checked word for word against it.
 *
 * @package    mod_learningmapping
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class byok {
    /** This plugin, as named in the licence's products list. */
    const COMPONENT = 'mod_learningmapping';

    /** Job ids this class issues start with this. */
    const JOBPREFIX = 'byok';

    /** Course frameworks the service knows. */
    const FRAMEWORKS = ['vet', 'au_curriculum', 'sg_moe', 'university', 'workplace', 'custom'];

    /** Services answered here. */
    const ROUTES = [
        '/api/generate-learningmapping' => 'mapping',
        '/api/generate-learningmapping/start' => 'mapping',
        '/api/analyse-assessment-conditions' => 'conditions',
        '/api/clean-tga-paste' => 'cleanpaste',
    ];

    /** @var callable|null Test hook: replaces the AI call with fn(system, user, maxtokens, temperature, json): string. */
    public static $aicall = null;

    /**
     * Should this site run mapping analysis on its own AI?
     *
     * @return bool
     */
    public static function enabled(): bool {
        return class_exists('\local_aiconfig\byok\ai')
            && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, 'text');
    }

    /**
     * Answer a service call locally when bring your own AI is on.
     *
     * @param string $url The LMS Labs URL the plugin was about to call.
     * @param array $data Its JSON payload.
     * @return array|null The service's reply, or null to call LMS Labs as usual.
     */
    public static function handle(string $url, array $data): ?array {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!isset(self::ROUTES[$path]) || !self::enabled()) {
            return null;
        }
        \core_php_time_limit::raise(600);
        $data = json_decode(json_encode($data), true);
        $method = self::ROUTES[$path];
        $result = self::$method($data);
        if (substr($path, -6) !== '/start') {
            return $result;
        }
        $jobid = self::JOBPREFIX . bin2hex(random_bytes(10));
        self::jobs()->set($jobid, $result);
        return ['ok' => true, 'jobId' => $jobid, 'byok' => true];
    }

    /**
     * Poll a job this class started.
     *
     * @param string $jobid Job id.
     * @return array|null The /api/jobs reply, or null when the job is not ours.
     */
    public static function poll(string $jobid): ?array {
        if (strpos($jobid, self::JOBPREFIX) !== 0) {
            return null;
        }
        $result = self::jobs()->get($jobid);
        if ($result === false) {
            return ['ok' => false, 'error' => 'Job not found or expired'];
        }
        if (empty($result['ok'])) {
            return ['ok' => true, 'status' => 'error', 'error' => $result['error'] ?? 'Generation failed'];
        }
        return ['ok' => true, 'status' => 'done', 'result' => $result];
    }

    /**
     * Finished jobs, kept for an hour.
     *
     * @return \cache
     */
    private static function jobs(): \cache {
        return \cache::make_from_params(
            \cache_store::MODE_APPLICATION,
            'mod_learningmapping',
            'byokjobs',
            [],
            ['simplekeys' => true, 'simpledata' => false, 'ttl' => HOURSECS]
        );
    }

    /**
     * Port of POST /api/clean-tga-paste.
     *
     * @param array $p Payload.
     * @return array
     */
    public static function cleanpaste(array $p): array {
        if (!is_string($p['rawText'] ?? null) || self::js_len($p['rawText']) < 10) {
            return ['ok' => false, 'error' => 'Invalid request'];
        }
        $systemprompt = ('You are an expert at parsing Australian VET training package units of competency from ' .
            'training.gov.au. Your job is to take raw pasted text (which may include website navigation, headers, footers, ' .
            'Power BI notices, and other junk) and extract ONLY the structured unit content in a clean, simple format.

OUTPUT FORMAT — follow this EXACTLY:
Elements and Performance Criteria
1. [Element title - simplified/shortened]
[PC description - simplified, no numbering needed]
[PC description]
...
2. [Element title]
[PC description]
...
Foundation Skills
[Skill name]
[Description of what the skill involves]
[Skill name]
[Description]
...
Assessment Requirements
Performance Evidence
[Each PE requirement on its own line]
...
Knowledge Evidence
[Each KE requirement on its own line]
...
Assessment Conditions
[Each condition on its own line]
...

RULES:
1. Strip ALL website junk: navigation, Power BI notices, search bars, "NRT Home News", release history, dates, ' .
            '"arrow_upward", "Back to top", copyright, privacy, acknowledgement, site version, etc.
2. Simplify element titles to be concise (e.g. "Plan financial management approaches" → "Plan Financial Management")
3. Simplify PC text to be concise but preserve meaning (e.g. "Access budget and financial plans for work team" → "Access ' .
            'budgets and financial plans")
4. Keep Foundation Skills grouped by skill name with descriptions
5. Remove boilerplate preamble text like "The candidate must be able to demonstrate..." and "This section describes..."
6. Remove "Element Performance criteria" table headers
7. Remove release/modification history
8. Keep the content accurate — don\'t invent or add requirements that aren\'t in the source
9. Output ONLY the structured text, no markdown formatting, no code blocks, no explanations');
        $userprompt = "Clean and structure this raw TGA unit paste into the simplified format:\n\n"
            . self::js_slice($p['rawText'], 0, 15000);
        try {
            $responsetext = self::ai($systemprompt, $userprompt, 4000, 0.1, false);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::message($e)];
        }
        return ['ok' => true, 'cleanedText' => self::js_trim($responsetext)];
    }

    /**
     * Port of POST /api/generate-learningmapping.
     *
     * @param array $p Payload.
     * @return array
     */
    public static function mapping(array $p): array {
        $invalid = self::validate_mapping($p);
        if ($invalid !== null) {
            return ['ok' => false, 'error' => 'Validation error: ' . $invalid];
        }
        try {
            [$systemprompt, $userprompt, $isvetframework] = self::mapping_prompts($p);
            $responsetext = self::ai($systemprompt, $userprompt, 12000, 0.3, true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::message($e)];
        }
        // Decoded as objects so {} and [] in the AI's answer come back exactly as the service returns them.
        $parsed = json_decode($responsetext);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'error' => 'Failed to parse AI response'];
        }
        if (!is_object($parsed) || !isset($parsed->mappings) || !is_array($parsed->mappings)) {
            return ['ok' => false, 'error' => 'AI response missing mappings array'];
        }
        $isobj = function ($v) {
            return is_object($v) || is_array($v);
        };
        if (!$isvetframework) {
            $coveragevalues = ['introduced', 'practised', 'assessed'];
            foreach ($parsed->mappings as $mapping) {
                if (!is_object($mapping) || !isset($mapping->suggestions) || !$isobj($mapping->suggestions)) {
                    return ['ok' => false, 'error' => 'AI response contains an invalid outcome mapping'];
                }
                $coveragetypes = $mapping->coverageTypes ?? null;
                if (!is_object($coveragetypes)) {
                    return ['ok' => false, 'error' => 'AI response omitted coverage types for non-VET mapping'];
                }
                $cleancoverage = new \stdClass();
                foreach ((array) $mapping->suggestions as $columnid => $suggestion) {
                    if (!is_string($suggestion) || self::js_trim($suggestion) === '') {
                        continue;
                    }
                    $coverage = ((array) $coveragetypes)[$columnid] ?? null;
                    if (!is_string($coverage) || !in_array($coverage, $coveragevalues, true)) {
                        return ['ok' => false, 'error' => 'AI response contains an invalid outcome coverage type'];
                    }
                    $cleancoverage->{$columnid} = $coverage;
                }
                $mapping->coverageTypes = $cleancoverage;
            }
        }
        foreach ($parsed->mappings as $m) {
            if (is_object($m) && isset($m->suggestions) && is_object($m->suggestions)) {
                foreach ($m->suggestions as $colid => $val) {
                    if (self::js_truthy($val)) {
                        $m->suggestions->{$colid} = self::sanitise_cell($val);
                    }
                }
            } else if (is_object($m) && isset($m->suggestions) && is_array($m->suggestions)) {
                foreach ($m->suggestions as $colid => $val) {
                    if (self::js_truthy($val)) {
                        $m->suggestions[$colid] = self::sanitise_cell($val);
                    }
                }
            }
        }
        $result = ['ok' => true, 'mappings' => $parsed->mappings];
        if (property_exists($parsed, 'summary')) {
            $result['summary'] = $parsed->summary;
        }
        // Back to arrays for the plugin, keeping empty objects as objects.
        $result = json_decode(json_encode($result), true);
        foreach ($result['mappings'] as $i => $m) {
            foreach (['suggestions', 'coverageTypes'] as $k) {
                if (is_array($m) && isset($m[$k]) && $m[$k] === [] && is_object($parsed->mappings[$i]->{$k} ?? null)) {
                    $result['mappings'][$i][$k] = new \stdClass();
                }
            }
        }
        return $result + ['creditsUsed' => 0, 'remainingCredits' => 'unlimited', 'byok' => true];
    }

    /**
     * The service's sanitiseCellVal(): keep only short activity codes.
     *
     * @param mixed $val Cell value.
     * @return string
     */
    public static function sanitise_cell($val): string {
        if (!self::js_truthy($val) || !is_string($val)) {
            return '';
        }
        $val = self::js_trim($val);
        if (preg_match_all('/\b(T\d+|Q\d+|Act\s*\d+|Ch\d+|Sub-Ch\d+|P\d+|A\d+)\b/i', $val, $mm) && count($mm[0]) > 0) {
            $seen = [];
            $unique = [];
            foreach ($mm[0] as $m) {
                $norm = preg_replace('/^act\s*/i', 'Act ', self::js_trim(preg_replace('/\s+/', ' ', $m)));
                $key = strtoupper($norm);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $unique[] = $norm;
                }
            }
            return implode(', ', $unique);
        }
        return self::js_len($val) <= 10 ? $val : '';
    }

    /**
     * The mapping prompts.
     *
     * @param array $p Payload.
     * @return array [system, user, isVet]
     */
    public static function mapping_prompts(array $p): array {
        $rows = $p['rows'];
        $columns = $p['columns'];
        $scannedmodules = $p['scannedModules'] ?? null;
        $unitcode = $p['unitCode'] ?? null;
        $framework = array_key_exists('framework', $p) ? $p['framework'] : 'vet';
        $selectedframework = in_array($framework, self::FRAMEWORKS, true) ? $framework : 'vet';
        $isvetframework = $selectedframework === 'vet';
        $selectedframeworkdata = self::parse_framework_data(array_key_exists('frameworkdata', $p) ? $p['frameworkdata'] : []);
        $cells = (isset($p['cells']) && is_array($p['cells']) && !(self::is_list($p['cells']) && $p['cells'] !== []))
            ? $p['cells'] : [];
        $cell = function ($key) use ($cells) {
            return array_key_exists($key, $cells) ? $cells[$key] : null;
        };
        $col0 = isset($columns[0]) ? self::js_str($columns[0]['id']) : 'undefined';

        $parentmap = [];
        foreach ($rows as $r) {
            if (self::js_truthy($r['isElement'] ?? null) || self::js_truthy($r['isShaded'] ?? null)) {
                $parentmap[$r['id']] = self::js_or($r['title'], '');
            }
        }
        $rowsummary = '';
        foreach ($rows as $r) {
            if (self::js_truthy($r['isElement'] ?? null) || self::js_truthy($r['isShaded'] ?? null)) {
                continue;
            }
            $firstcolval = self::js_or(self::js_or($cell($r['id'] . '::' . $col0), $r['title']), '');
            $parentinfo = '';
            $pid = $r['parentId'] ?? null;
            if (self::js_truthy($pid) && self::js_truthy($parentmap[$pid] ?? null)) {
                $parentinfo = ' (under: "' . self::js_str($parentmap[$pid]) . '")';
            }
            $rowsummary .= '[ROW] id="' . $r['id'] . '" ' . self::js_str($firstcolval) . $parentinfo . "\n";
        }

        $activitysummary = '';
        if (is_array($scannedmodules) && count($scannedmodules) > 0) {
            foreach ($scannedmodules as $mod) {
                $activitysummary .= self::mapping_module($mod, $isvetframework);
            }
        }

        $mappablecolumns = array_values(array_filter($columns, function ($c) {
            return $c['id'] !== 'col_elements';
        }));
        $existingmappings = '';
        foreach ($rows as $r) {
            foreach ($mappablecolumns as $col) {
                $val = $cell($r['id'] . '::' . $col['id']);
                if (self::js_truthy($val) && self::js_trim($val) !== '') {
                    $rowlabel = self::js_or(self::js_or($cell($r['id'] . '::' . $col0), $r['title']), $r['id']);
                    $existingmappings .= '  Row "' . self::js_str($rowlabel) . '" → Column "' . $col['title'] . '": '
                        . self::js_slice($val, 0, 200) . "\n";
                }
            }
        }

        $systemprompt = ('You are an expert VET/RTO training package analyst specialising in ASQA-compliant learning and ' .
            'assessment mapping for Australian Registered Training Organisations.

⚠️ ABSOLUTE NON-NEGOTIABLE RULE — READ THIS FIRST AND OBEY AT ALL TIMES:
Every cell value in your suggestions MUST be a SHORT CODE ONLY.
CORRECT: "T1"  "Q1, Q3"  "Act 1"  "T2, T4"  "Q1"
WRONG:   "Learning Element 1: Plan financial..."  "Quiz Priya\'s team is proposing..."  "(cardselect) Budget..."  Any ' .
            'sentence. Any description. Any question text. Any title.
If you put anything other than short codes in a cell, the mapping is broken. Short codes only. No exceptions.

Your task: Given the unit elements/performance criteria (rows) and ALL course activities found by scanning the Moodle course ' .
            '(Content Creator slideshows, Learning Activities, Knowledge Check questions, Essay Maker questions, Moodle ' .
            'Assignments, Quizzes, Lessons, Books, Pages, Forums, Workshops, and any other activities), produce an ' .
            'intelligent mapping that shows which activities address which performance criteria.

ACTIVITY TYPE GUIDE:
- contentcreator: AI-generated learning slideshows with topics and sections — maps to Content Creator column (LEARNING ' .
            'group). Topics are numbered T1, T2, T3 etc. in order. When mapping, use the topic NUMBER to indicate which PC a ' .
            'topic covers. For example, if T1\'s title relates to "accessing budgets", map T1 to the PC row about accessing ' .
            'budgets.
- aiactivities: Interactive learning activities — maps to Learning Activities column (LEARNING group). Activities are ' .
            'numbered Act 1, Act 2, Act 3 etc. in order. Map each activity to the PC it addresses based on its title/type.
- aiknowledgecheck: Knowledge check questions — maps to Knowledge Check column (ASSESSMENT group). Questions are numbered ' .
            'Q1, Q2, Q3 etc. in order. CRITICAL: Each question may have a [maps-to-criteria] tag showing which PC it was ' .
            'designed for, and/or a [maps-to-topic] tag. Use these tags as the PRIMARY basis for mapping KC questions to ' .
            'rows. If Q5 has [maps-to-criteria: 1.1], it MUST map to the row for PC 1.1. If no criteria tag exists, match by ' .
            'question content.
- essaymaker: Written assessment questions — maps to Essay Maker column (ASSESSMENT group). Questions are numbered Q1, Q2, ' .
            'Q3 etc. in order.
- practicalassessment: AI-generated workplace practical assessment — maps to Practical Assessment column (ASSESSMENT ' .
            'group). This is the richest source of mapping data. It contains:
  • OBSERVABLE SKILLS: each is a directly observable performance behaviour mapped to specific elements. These are the ' .
            'strongest evidence for Performance Evidence rows and Performance Criteria.
  • WORKPLACE FORMS: documentary evidence required (e.g. Hazard Report, Risk Assessment Form, Work Record). These satisfy ' .
            'Assessment Conditions requirements and Performance Evidence rows requiring documentation.
  • PRE-MAPPED CRITERIA: pre-existing mappings between criteria and evidence. Treat these as authoritative: if criterion ' .
            '"1.1 - Access budget plans" is pre-mapped to a skill, that skill maps to the PC 1.1 row.
  • SCENARIO: the workplace context — use this to confirm which industry/job role the skills apply to.
  No numbered short codes apply here. For Practical Assessment column cells, use "PA" (just "PA" — the practical ' .
            'assessment activity as a whole is the evidence).
- assign: Moodle assignment (practical/written submission) — maps to Assignments column (ASSESSMENT group). The ' .
            'description tells you what the assignment covers. Use "AS" as the short code.
- quiz: Moodle quiz with questions. IMPORTANT: Quiz questions have a "type" field. Questions with type "essay" are ' .
            'written/short-answer assessment questions and MUST map to the Essay Maker column. Questions with other types ' .
            '(multichoice, truefalse, match, shortanswer, etc.) map to Knowledge Check column. A single quiz may have ' .
            'questions that map to BOTH columns.
- lesson: Moodle lesson with sequential pages — maps to Learning Activities column (LEARNING group)
- book: Moodle book with chapters — maps to Content Creator column (LEARNING group)
- page: Single content page — maps to Content Creator column (LEARNING group)
- forum: Discussion forum — maps to Essay Maker column (ASSESSMENT group) if used for written responses, or Learning ' .
            'Activities column if used for discussion-based learning
- workshop: Peer assessment workshop — maps to Essay Maker column (ASSESSMENT group)
- resource/url/scorm/lti/h5pactivity: Other learning resources — maps to Learning Activities column (LEARNING group)

COLUMN MAPPING — The exact column IDs are provided in the user prompt under "COLUMNS AVAILABLE FOR MAPPING". Use those ' .
            'exact IDs in your suggestions object. The column title tells you what type of activity maps there:
- Columns titled "Content Creator" or similar: learning slideshows, books, pages. Cell value = T1, T2, T3 etc.
- Columns titled "Learning Activities" or similar: interactive activities, lessons. Cell value = Act 1, Act 2, Act 3 etc.
- Columns titled "Knowledge Check" or similar: multichoice/knowledge questions. Cell value = Q1, Q2, Q3 etc.
- Columns titled "Essay Maker" or similar: written/essay questions. Cell value = Q1, Q2, Q3 etc.
- Columns titled "Practical Assessment" or similar: workplace practical assessment activities. Cell value = "PA".
- Columns titled "Assignments" or similar: Moodle assignments. Cell value = "AS".
- Any other custom column: use the appropriate short code for activities in that column.
ALWAYS use the col_id from the "COLUMNS AVAILABLE FOR MAPPING" list — never invent column IDs.

ROW TYPES IN THE MAPPING TABLE:
The rows are organised into sections. Each section has a heading row (marked [ELEMENT-HEADING] or [SECTION-HEADING]) ' .
            'followed by child rows (marked [ROW]). The child rows have "(under: ...)" to show which heading they belong to. ' .
            'Row types:

1. PERFORMANCE CRITERIA rows (under Element headings): These are specific performance criteria like "1.1 Access budget and ' .
            'financial plans". Map Content Creator topics, Learning Activities, Knowledge Check questions, and Essay Maker ' .
            'questions that address this specific criterion. Use the Content Creator topic that teaches this criterion, the ' .
            'KC question that tests it, etc.

2. KNOWLEDGE EVIDENCE rows (under "Knowledge Evidence" heading): These list knowledge requirements like "basic accounting ' .
            'principles". Map KC questions AND Essay Maker questions that test this knowledge. Also map Content Creator ' .
            'topics that teach this knowledge, and Learning Activities that cover it. KE rows should have MORE mappings than ' .
            'PC rows because multiple activities often address the same knowledge area.

3. PERFORMANCE EVIDENCE rows (under "Performance Evidence" heading): These list practical competency requirements. The ' .
            'OBSERVABLE SKILLS in a Practical Assessment activity are the STRONGEST evidence for Performance Evidence rows ' .
            '— match each skill to the PE requirement it satisfies. Also map Essay Maker questions, assignments, and ' .
            'Learning Activities where students demonstrate these practical skills. KC questions may also map here if they ' .
            'assess related applied knowledge.

4. FOUNDATION SKILLS rows (under "Foundation Skills" heading): These are cross-cutting skills like Numeracy, Oral ' .
            'Communication, Reading, Writing, Teamwork, etc. THESE MUST NOT BE LEFT BLANK. Map them as follows:
   - Numeracy: Any activity involving calculations, budgets, financial data, spreadsheets, numerical analysis
   - Oral communication: Any forum discussions, presentation activities, group activities requiring verbal interaction
   - Reading: Any activity requiring students to read and interpret documents, policies, financial statements
   - Writing: Any essay questions, written assignments, report writing activities, forum posts
   - Teamwork: Any group activities, collaborative tasks, forums, workshops, peer activities
   - Initiative and enterprise: Any activities requiring independent work, applying organisational procedures
   - Planning and organising: Any activities involving planning, scheduling, task management, project work
   - Technology: Any activities using digital tools, spreadsheets, online platforms, digital systems
   For each FS row, list ALL activities across ALL columns that develop or demonstrate that skill.

RULES:
1. Return valid JSON only. No commentary outside JSON.
2. ONLY produce mappings for [ROW] rows. NEVER produce mappings for [ELEMENT-HEADING] or [SECTION-HEADING] rows — these ' .
            'are group headers and must NOT have cell content. Skip them entirely in the mappings array.
3. CRITICAL — Cell values must be SHORT CODES ONLY, not full text. Examples of CORRECT cell values:
   - "T1" or "T1, T3" (Content Creator topic numbers)
   - "Q1" or "Q1, Q3, Q5" (quiz/knowledge check/essay question numbers)
   - "Act 1" or "Act 1, Act 3" (learning activity numbers)
   - "Ch1" or "Ch1, Ch3" (book chapter numbers)
   - "P1" or "P1, P2" (lesson page numbers)
   Examples of WRONG cell values (NEVER do this):
   - "Learning Element 1: Plan financial management approaches - 1.1 Access budget..."
   - "Budget Management Concepts (flashcards) Budget Terms and Definitions (matching)..."
   - Any text longer than 20 characters
   NEVER put full activity names, descriptions, question text, or long text in cells. Maximum ~20 characters per cell.
4. Each [ROW] represents ONE specific requirement. Map activities that specifically address THAT requirement. Be thorough ' .
            '— if a topic covers budgets and a PC row is about budgets, map it. If a KC question tests budget knowledge ' .
            'and a KE row lists budget knowledge, map it.
5. If a row has no matching activity, omit it from mappings or leave its suggestions empty. Do not put "N/A" or "None".
6. Identify gaps where no course activity addresses a requirement.
7. Map EVERY scanned module type — not just AI plugins. Standard Moodle activities (assignments, quizzes, lessons, books, ' .
            'forums, etc.) are equally important.
8. Use the column group to determine where to place suggestions: learning-group columns for learning resources, ' .
            'assessment-group columns for assessment activities.
9. NUMBERING: Activities in the scanned data are already labelled with their short codes (T1, Q1, Act 1 etc.). Use those ' .
            'exact codes in your cell values. The number corresponds to the order they appear in the scan.
10. KNOWLEDGE CHECK MAPPING: When a KC question has [maps-to-criteria: X.X], that question MUST appear in the Knowledge ' .
            'Check column for the PC row with code X.X. This is the most reliable mapping signal available — treat it as ' .
            'authoritative. Also map the same question to any KE row whose knowledge requirement the question tests.
11. FOUNDATION SKILLS: Every FS child row MUST have at least one mapping. These are cross-cutting skills — multiple ' .
            'activities across all columns typically address each one. Be generous with FS mappings.
12. KNOWLEDGE/PERFORMANCE EVIDENCE: KE and PE rows typically have MANY mappings because they summarise broad knowledge/skill ' .
            'areas. Map generously — any activity that touches the knowledge area or skill should be included.
13. Output format must be a compact JSON object with:
   {
     "mappings": [
       {
         "rowId": "exact_id_from_ROW_line",
         "suggestions": {
           "col_id": "T1, T3"
         },
         "gaps": "only include this field when there IS a gap — omit if fully covered"
       }
     ],
     "summary": {
       "totalRows": number,
       "fullyCovered": number,
       "partiallyCovered": number,
       "notCovered": number,
       "recommendations": ["max 3 key recommendations"]
     }
   }
   IMPORTANT FOR COMPACT OUTPUT: omit rowTitle and confidence from every mapping entry. Only include "gaps" when there is a ' .
            'real gap. This keeps the response small so it completes without timeout.

⚠️ FINAL REMINDER — CELL VALUES ARE SHORT CODES ONLY:
"T1" ✓   "Q1, Q3" ✓   "Act 1" ✓   "T2, T4" ✓
"Budget management approaches" ✗   "Q1: What should Priya do?" ✗   "(cardselect) Budget..." ✗
The col_id in suggestions MUST exactly match one of the IDs listed in "COLUMNS AVAILABLE FOR MAPPING".
');
        $userprompt = 'Analyse this learning and assessment mapping' . (self::js_truthy($unitcode) ? ' for unit ' . $unitcode :
            '')
            . ".\n\nROWS (Elements and Performance Criteria):\n" . $rowsummary . "\n\nCOLUMNS AVAILABLE FOR MAPPING:\n"
            . implode("\n", array_map(function ($c) {
                return '- ' . $c['id'] . ': "' . $c['title'] . '" (group: ' . self::js_str(self::js_or(
                    $c['group'] ?? null,
                    'custom'
                )) . ')';
            }, $mappablecolumns)) . "\n";
        if ($activitysummary !== '') {
            $userprompt .= "\nSCANNED COURSE ACTIVITIES (the text after each code is CONTEXT ONLY — do NOT copy it into " .
                "cells. "
                . "Only use the code e.g. T1, Q1, Act 1):\n" . $activitysummary;
        }
        if ($existingmappings !== '') {
            $userprompt .= "\nEXISTING MAPPINGS (already filled in by teacher):\n" . $existingmappings;
        }
        $userprompt .= ('
Analyse ALL rows against ALL available activities. For EVERY [ROW], suggest which column cells should contain SHORT CODES ' .
            'ONLY (e.g. "T2", "Q1, Q3", "Act 1"). REMINDER: every suggestions value must be a short code — never a ' .
            'description, title, or question text. Be thorough — Knowledge Evidence rows need KC and EM question mappings, ' .
            'Performance Evidence rows need activity and assessment mappings, Foundation Skills rows MUST have cross-cutting ' .
            'activity mappings (never leave them blank). Use [maps-to-criteria] tags on KC questions as authoritative PC ' .
            'mappings. Identify gaps where no activity covers a requirement. Return JSON only.');

        if (!$isvetframework) {
            [$systemprompt, $userprompt] = self::nonvet_prompts($selectedframework, $selectedframeworkdata, [
                'rows' => $rowsummary,
                'columns' => implode("\n", array_map(function ($c) {
                    return '- ' . $c['id'] . ': "' . $c['title'] . '" (' . self::js_str(self::js_or(
                        $c['group'] ?? null,
                        'custom'
                    )) . ')';
                }, $mappablecolumns)),
                'activities' => $activitysummary,
                'existingMappings' => $existingmappings,
            ]);
        }
        if (strpos($activitysummary, 'Built in AI Course Builder to teach these curriculum outcomes:') !== false) {
            $userprompt .= ('

AI COURSE BUILDER PLAN (authoritative): some activities say "Built in AI Course Builder to teach these curriculum outcomes: ' .
                '...". Each of those activities MUST be mapped to the [ROW] for every outcome it names (match the row text ' .
                'exactly, ignoring a trailing full stop). Use coverage "introduced" for Visual Learning, Content Creator and ' .
                'Slide Flow; "practised" for Learning Activities, Soft Skills, Branched Scenario, Safety Hazards and ' .
                'Anatomy; "assessed" for Knowledge Check. You may add other genuine links, but never omit a planned one, and ' .
                'never map an activity to an outcome it does not teach.');
        }
        return [$systemprompt, $userprompt, $isvetframework];
    }

    /**
     * One scanned module in the mapping prompt.
     *
     * @param array $mod Module.
     * @param bool $isVetFramework VET framework?
     * @return string
     */
    private static function mapping_module(array $mod, bool $isvetframework): string {
        $g = function ($k) use ($mod) {
            return $mod[$k] ?? null;
        };
        $s = '';
        $sectionlabel = self::js_truthy($g('sectionName')) ? ' [Section: ' . $mod['sectionName'] . ']' : '';
        $s .= "\n--- " . $mod['name'] . ' (' . $mod['modname'] . ')' . $sectionlabel . " ---\n";

        if ($mod['modname'] === 'practicalassessment') {
            if (!$isvetframework) {
                if (self::js_truthy($g('description'))) {
                    $s .= '  Activity context: ' . self::js_slice($mod['description'], 0, 800) . "\n";
                }
                if (self::js_truthy($g('scenario'))) {
                    $s .= '  Practice scenario: ' . self::js_slice($mod['scenario'], 0, 500) . "\n";
                }
                if (is_array($g('skills'))) {
                    foreach (array_values($mod['skills']) as $idx => $skill) {
                        $detail = is_string($skill) ? $skill
                            : self::js_or(self::js_or(self::js_get($skill, 'description'), self::js_get($skill, 'text')), '');
                        if (self::js_truthy($detail)) {
                            $s .= '  Practice task ' . ($idx + 1) . ': ' . self::js_str($detail) . "\n";
                        }
                    }
                }
                if (is_array($g('workplaceForms'))) {
                    foreach ($mod['workplaceForms'] as $form) {
                        $title = is_string($form) ? $form
                            : self::js_or(self::js_or(self::js_get($form, 'title'), self::js_get($form, 'name')), '');
                        if (self::js_truthy($title)) {
                            $s .= '  Reference material: ' . self::js_str($title) . "\n";
                        }
                    }
                }
                return $s;
            }
            if (self::js_truthy($g('unitcode'))) {
                $s .= '  Unit: ' . $mod['unitcode'] . (self::js_truthy($g('unitname')) ? ' - ' . $mod['unitname'] : '') . "\n";
            }
            if (self::js_truthy($g('industry'))) {
                $s .= '  Industry: ' . $mod['industry'] . (self::js_truthy($g('jobrole')) ? ', Job Role: ' . $mod['jobrole'] :
                    '') . "\n";
            }
            if (self::js_truthy($g('occasions'))) {
                $s .= '  Assessment Occasions: ' . self::js_str($mod['occasions']) . "\n";
            }
            if (self::js_truthy($g('scenario'))) {
                $s .= '  Scenario: ' . self::js_slice($mod['scenario'], 0, 800) . "\n";
            }
            if (self::js_truthy($g('description'))) {
                $s .= '  Description: ' . self::js_slice($mod['description'], 0, 500) . "\n";
            }
            if (self::js_truthy($g('skills')) && is_array($mod['skills'])) {
                $s .= "  OBSERVABLE SKILLS (Performance Evidence):\n";
                foreach (array_values($mod['skills']) as $idx => $sk) {
                    $desc = is_string($sk) ? $sk
                        : self::js_or(self::js_or(self::js_get($sk, 'description'), self::js_get($sk, 'text')), '');
                    $crit = self::js_get($sk, 'criteria');
                    $criteria = (self::js_truthy($crit) && self::js_truthy(self::js_len($crit)))
                        ? ' [element: ' . implode(', ', array_map([self::class, 'join_item'], (array) $crit)) . ']' : '';
                    $s .= '    Skill ' . ($idx + 1) . $criteria . ': ' . self::js_str($desc) . "\n";
                }
            }
            if (self::js_truthy($g('workplaceForms')) && is_array($mod['workplaceForms'])) {
                $s .= "  WORKPLACE FORMS (Documentary Evidence):\n";
                foreach (array_values($mod['workplaceForms']) as $idx => $f) {
                    $title = is_string($f) ? $f : self::js_or(
                        self::js_or(self::js_get($f, 'title'), self::js_get($f, 'name')),
                        ''
                    );
                    $purpose = self::js_truthy(self::js_get($f, 'purpose')) ? ' — ' . self::js_str(self::js_get(
                        $f,
                        'purpose'
                    )) : '';
                    $s .= '    Form ' . ($idx + 1) . ': ' . self::js_str($title) . $purpose . "\n";
                }
            }
            if (self::js_truthy($g('mappingHints')) && is_array($mod['mappingHints'])) {
                $s .= "  PRE-MAPPED CRITERIA:\n";
                foreach (array_slice(array_values($mod['mappingHints']), 0, 30) as $m) {
                    if (self::js_truthy(self::js_get($m, 'criterion')) && self::js_truthy(self::js_get($m, 'evidence'))) {
                        $s .= '    ' . self::js_str($m['criterion']) . ' ← ' . self::js_str($m['evidence']) . ' ('
                            . self::js_str(self::js_or(self::js_get($m, 'source'), 'Observation')) . ")\n";
                    }
                }
            }
            return $s;
        }

        if (self::js_truthy($g('description'))) {
            $s .= '  Description: ' . self::js_slice($mod['description'], 0, 1500) . "\n";
        }
        if (self::js_truthy($g('submissionInstructions'))) {
            $s .= '  Submission Instructions: ' . self::js_slice($mod['submissionInstructions'], 0, 500) . "\n";
        }
        if (self::js_truthy($g('submissionPlugins')) && count($mod['submissionPlugins'])) {
            $s .= '  Submission Plugins: ' . implode(', ', $mod['submissionPlugins']) . "\n";
        }
        if (self::js_truthy($g('topics'))) {
            foreach (array_values($mod['topics']) as $idx => $t) {
                $s .= '  T' . ($idx + 1) . ' (Topic ' . ($idx + 1) . '): ' . self::prop($t, 'title') . "\n";
                if (self::js_truthy(self::js_get($t, 'sections'))) {
                    foreach ((array) $t['sections'] as $sec) {
                        $s .= '    Section: ' . self::js_str($sec) . "\n";
                    }
                }
            }
        }
        if (self::js_truthy($g('questions'))) {
            foreach (array_values($mod['questions']) as $idx => $q) {
                $qtype = self::js_truthy(self::js_get($q, 'type')) ? ' (' . self::js_str($q['type']) . ')' : '';
                $criteriahint = self::js_truthy(self::js_get($q, 'criteria')) ? ' [maps-to-criteria: ' . self::js_str($q['cri' .
                    'teria']) . ']' : '';
                $topichint = self::js_truthy(self::js_get($q, 'topic')) ? ' [maps-to-topic: ' . self::js_str($q['topic']) .
                    ']' : '';
                $s .= '  Q' . ($idx + 1) . $qtype . $criteriahint . $topichint . ': '
                    . self::js_str(self::js_or(self::js_or(self::js_get($q, 'question'), self::js_get($q, 'text')), '')) . "\n";
            }
        }
        if (self::js_truthy($g('activities'))) {
            foreach (array_values($mod['activities']) as $idx => $a) {
                $s .= '  Act ' . ($idx + 1) . ' (Activity ' . ($idx + 1) . '): ' . self::prop($a, 'title') . ' (' .
                    self::prop($a, 'type') . ")\n";
            }
        }
        if (self::js_truthy($g('pages'))) {
            foreach (array_values($mod['pages']) as $idx => $pg) {
                $s .= '  P' . ($idx + 1) . ' (Page ' . ($idx + 1) . '): ' . self::prop($pg, 'title') . "\n";
            }
        }
        if (self::js_truthy($g('chapters'))) {
            foreach (array_values($mod['chapters']) as $idx => $ch) {
                $prefix = self::js_truthy(self::js_get($ch, 'subchapter')) ? '    Sub-Ch' . ($idx + 1) : '  Ch' . ($idx + 1) .
                    ' (Chapter ' . ($idx + 1) . ')';
                $s .= $prefix . ': ' . self::prop($ch, 'title') . "\n";
            }
        }
        return $s;
    }

    /**
     * Port of POST /api/analyse-assessment-conditions.
     *
     * @param array $p Payload.
     * @return array
     */
    public static function conditions(array $p): array {
        $invalid = self::validate_conditions($p);
        if ($invalid !== null) {
            return ['ok' => false, 'error' => 'Validation error: ' . $invalid];
        }
        try {
            [$systemprompt, $userprompt] = self::conditions_prompts($p);
            $responsetext = self::ai($systemprompt, $userprompt, 2000, 0.3, true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::message($e)];
        }
        $parsedresponse = json_decode($responsetext, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'error' => 'AI returned invalid response format'];
        }
        $results = is_array($parsedresponse) ? ($parsedresponse['results'] ?? null) : null;
        return ['ok' => true, 'results' => self::js_truthy($results) ? $results : [], 'creditsUsed' => 0, 'byok' => true];
    }

    /**
     * The assessment conditions prompts.
     *
     * @param array $p Payload.
     * @return string[] [system, user]
     */
    public static function conditions_prompts(array $p): array {
        $activitysummary = '';
        foreach ((array) ($p['scannedModules'] ?? []) as $mod) {
            $g = function ($k) use ($mod) {
                return $mod[$k] ?? null;
            };
            $activitysummary .= "\n[" . strtoupper($mod['modname']) . '] "' . $mod['name'] . '"';
            if (self::js_truthy($g('sectionName'))) {
                $activitysummary .= ' (Section: ' . $mod['sectionName'] . ')';
            }
            $activitysummary .= "\n";
            if (self::js_truthy($g('questions')) && count($mod['questions']) > 0) {
                $n = count($mod['questions']);
                $activitysummary .= "  {$n} questions:\n";
                foreach (array_slice(array_values($mod['questions']), 0, 15) as $idx => $q) {
                    $qtext = self::js_slice(self::js_or(
                        self::js_or(self::js_get($q, 'question'), self::js_get($q, 'text')),
                        ''
                    ), 0, 80);
                    $qtype = self::js_truthy(self::js_get($q, 'type')) ? ' [' . self::js_str($q['type']) . ']' : '';
                    $activitysummary .= '  Q' . ($idx + 1) . $qtype . ': ' . $qtext . "\n";
                }
                if ($n > 15) {
                    $activitysummary .= '  ... (' . ($n - 15) . " more questions)\n";
                }
            }
            $titles = function ($list, $fmt) {
                return implode(', ', array_map(function ($x) use ($fmt) {
                    $v = $fmt($x);
                    return $v === null ? '' : self::js_str($v);
                }, array_slice(array_values($list), 0, 6)));
            };
            $or = function ($x, $k) {
                return self::js_or(self::js_get($x, $k), $x);
            };
            if (self::js_truthy($g('topics')) && count($mod['topics']) > 0) {
                $activitysummary .= '  Topics (' . count($mod['topics']) . '): ' . $titles(
                    $mod['topics'],
                    function ($t) use ($or) {
                        return $or($t, 'title');
                    }
                ) . "\n";
            }
            if (self::js_truthy($g('activities')) && count($mod['activities']) > 0) {
                $activitysummary .= '  Activities: ' . $titles($mod['activities'], function ($a) {
                    return self::prop($a, 'title') . ' (' . self::prop($a, 'type') . ')';
                }) . "\n";
            }
            if (self::js_truthy($g('skills')) && count($mod['skills']) > 0) {
                $activitysummary .= '  Observable Skills: ' . $titles($mod['skills'], function ($x) use ($or) {
                    return $or($x, 'title');
                }) . "\n";
            }
            if (self::js_truthy($g('workplaceForms')) && count($mod['workplaceForms']) > 0) {
                $activitysummary .= '  Workplace Forms: ' . $titles($mod['workplaceForms'], function ($x) use ($or) {
                    return $or($x, 'title');
                }) . "\n";
            }
            if (self::js_truthy($g('scenario'))) {
                $activitysummary .= '  Scenario: ' . self::js_slice($mod['scenario'], 0, 100) . "\n";
            }
            if (self::js_truthy($g('submissionPlugins')) && count($mod['submissionPlugins']) > 0) {
                $activitysummary .= '  Submission type: ' . implode(', ', $mod['submissionPlugins']) . "\n";
            }
        }
        $conditionslist = implode("\n", array_map(function ($ac, $idx) {
            return ($idx + 1) . '. [ID:' . $ac['id'] . '] ' . $ac['text'];
        }, array_values($p['acData']), array_keys(array_values($p['acData']))));
        $unitcode = $p['unitCode'] ?? null;
        $systemprompt = ('You are an expert in Australian VET compliance under ASQA 2025 Standards for RTOs. Analyse the ' .
            'provided course activities and generate concise evidence for an Assessment Conditions compliance checklist.

STRICT OUTPUT RULES:
- "addressed": EXACTLY one sentence, maximum 20 words. No lists. No padding.
- "evidence": List format ONLY — "Activity Name: Q1, Q3, Q5" or "Activity Name: Form 1, Form 2". Separate multiple ' .
            'activities with semicolons. NO sentences, NO prose.
- Use "Activity Name (all questions)" ONLY when every single listed question for that activity clearly and directly relates ' .
            'to the condition.
- "status":
  - "met" = multiple strong, direct pieces of evidence across activities
  - "partial" = minimal, indirect, or single-activity evidence only
  - "" = no clear evidence in the provided data

GROUNDING RULES — CRITICAL:
- ONLY reference activities, questions, forms, or skills that appear explicitly in the COURSE ACTIVITIES data below.
- Do NOT infer, extrapolate, or generalise beyond what is visible in the data.
- Match each condition ONLY to questions or activities whose content directly demonstrates that specific requirement. Ignore ' .
            'loosely related or generic content.
- If no clear evidence exists in the provided data, return: addressed: "", evidence: "", status: ""

GOOD EXAMPLES:
addressed: "Scenario-based questions replicate realistic workplace situations across assessments."
evidence: "Short Answer Questions: Q1, Q2, Q5, Q8"

addressed: "Assessment uses varied written and practical methods with consistent marking criteria."
evidence: "Short Answer Questions (written submission); Practical Assessment (observation checklist)"

addressed: "Simulated workplace conditions provided through structured scenarios and workplace forms."
evidence: "Practical Assessment: Scenarios 1-3; Workplace Forms: Form 1, Form 2"

BAD (never do this):
evidence: "All assessments"  ← too vague, not grounded
evidence: "The course includes questions about workplace safety..." ← prose, not list format
addressed: "This condition is addressed through scenario-based methods as required by ASQA 2025." ← too long, repeats ' .
            'standard name');
        $userprompt = ('Unit: ' . self::js_str(self::js_or($unitcode, 'Not specified')) . '

COURSE ACTIVITIES:
' . self::js_str(self::js_or($activitysummary, 'No activities scanned.')) . '

ASSESSMENT CONDITIONS TO ANALYSE (analyse each condition independently against the data above):
' . self::js_str($conditionslist) . '

Return a JSON object with a result for every condition ID listed above:
{
  "results": [
    {
      "id": "<exact condition ID from input>",
      "addressed": "<one sentence max 20 words, or empty string if no evidence>",
      "evidence": "<Activity Name: Q1, Q3; Activity Name: Form 1 — or empty string if no evidence>",
      "status": "met" | "partial" | ""
    }
  ]
}');
        return [$systemprompt, $userprompt];
    }

    /**
     * The service's request checks for a mapping.
     *
     * @param array $p Payload.
     * @return string|null The first problem, or null.
     */
    private static function validate_mapping(array $p): ?string {
        if (!isset($p['rows']) || !is_array($p['rows']) || !self::is_list($p['rows'])) {
            return 'rows: Required';
        }
        foreach ($p['rows'] as $i => $r) {
            if (
                !is_array($r) || !is_string($r['id'] ?? null) || !is_string($r['title'] ?? null)
                    || (array_key_exists('isElement', $r) && !is_bool($r['isElement']))
                    || (array_key_exists('isShaded', $r) && !is_bool($r['isShaded']))
                    || (array_key_exists('parentId', $r) && $r['parentId'] !== null && !is_string($r['parentId']))
            ) {
                return "rows.{$i}: Invalid";
            }
        }
        if (!isset($p['columns']) || !is_array($p['columns']) || !self::is_list($p['columns'])) {
            return 'columns: Required';
        }
        foreach ($p['columns'] as $i => $c) {
            if (
                !is_array($c) || !is_string($c['id'] ?? null) || !is_string($c['title'] ?? null)
                    || (array_key_exists('group', $c) && !is_string($c['group']))
            ) {
                return "columns.{$i}: Invalid";
            }
        }
        if (array_key_exists('cells', $p) && !is_array($p['cells'])) {
            return 'cells: Invalid';
        }
        if (is_array($p['cells'] ?? null) && !self::is_list($p['cells'])) {
            foreach ($p['cells'] as $v) {
                if (!is_string($v)) {
                    return 'cells: Invalid';
                }
            }
        }
        if (array_key_exists('unitCode', $p) && !is_string($p['unitCode'])) {
            return 'unitCode: Invalid';
        }
        if (array_key_exists('framework', $p) && !in_array($p['framework'], self::FRAMEWORKS, true)) {
            return 'framework: Invalid';
        }
        return self::validate_modules(
            $p,
            ['unitcode', 'unitname', 'industry', 'jobrole', 'scenario', 'submissionInstructions'],
            ['mappingHints'],
            true
        );
    }

    /**
     * The service's request checks for assessment conditions.
     *
     * @param array $p Payload.
     * @return string|null The first problem, or null.
     */
    private static function validate_conditions(array $p): ?string {
        if (array_key_exists('unitCode', $p) && !is_string($p['unitCode'])) {
            return 'unitCode: Invalid';
        }
        if (!isset($p['acData']) || !is_array($p['acData']) || !self::is_list($p['acData'])) {
            return 'acData: Required';
        }
        foreach ($p['acData'] as $i => $ac) {
            if (!is_array($ac) || !is_string($ac['id'] ?? null) || !is_string($ac['text'] ?? null)) {
                return "acData.{$i}: Invalid";
            }
        }
        return self::validate_modules($p, ['unitcode', 'unitname', 'scenario', 'submissionInstructions'], [], false);
    }

    /**
     * Check scannedModules against the service schema.
     *
     * @param array $p Payload.
     * @param string[] $strings Extra optional string fields.
     * @param string[] $arrays Extra optional array fields.
     * @param bool $occasions Whether occasions (number) is allowed.
     * @return string|null
     */
    private static function validate_modules(array $p, array $strings, array $arrays, bool $occasions): ?string {
        if (!array_key_exists('scannedModules', $p)) {
            return null;
        }
        if (!is_array($p['scannedModules']) || !self::is_list($p['scannedModules'])) {
            return 'scannedModules: Invalid';
        }
        $strings = array_merge(['description', 'sectionName'], $strings);
        $arrays = array_merge(['topics', 'questions', 'activities', 'pages', 'chapters', 'skills', 'workplaceForms'], $arrays);
        foreach ($p['scannedModules'] as $i => $m) {
            if (!is_array($m) || !is_string($m['modname'] ?? null) || !is_string($m['name'] ?? null)) {
                return "scannedModules.{$i}: Invalid";
            }
            foreach ($strings as $k) {
                if (array_key_exists($k, $m) && !is_string($m[$k])) {
                    return "scannedModules.{$i}.{$k}: Invalid";
                }
            }
            foreach ($arrays as $k) {
                if (array_key_exists($k, $m) && (!is_array($m[$k]) || !self::is_list($m[$k]))) {
                    return "scannedModules.{$i}.{$k}: Invalid";
                }
            }
            if (
                array_key_exists('submissionPlugins', $m) && (!is_array($m['submissionPlugins'])
                    || !self::is_list($m['submissionPlugins']) || array_filter($m['submissionPlugins'], function ($x) {
                        return !is_string($x);
                    }))
            ) {
                return "scannedModules.{$i}.submissionPlugins: Invalid";
            }
            if ($occasions && array_key_exists('occasions', $m) && !is_int($m['occasions']) && !is_float($m['occasions'])) {
                return "scannedModules.{$i}.occasions: Invalid";
            }
        }
        return null;
    }

    /**
     * Port of buildNonVetMappingPrompts().
     *
     * @param string $id Framework.
     * @param array $data Framework data.
     * @param array $input rows, columns, activities, existingMappings.
     * @return string[] [system, user]
     */
    private static function nonvet_prompts(string $id, array $data, array $input): array {
        $framework = self::describe_framework($id, $data);
        $details = implode("\n", array_merge(
            ['Framework: ' . $framework['label']],
            $framework['levels'],
            $framework['metadata']
        ));
        $system = ('You map learning outcomes in the ' . self::js_str(self::js_get($framework, 'label')) . ' framework to ' .
            'the activities that introduce, practise, or assess them. Treat each [ROW] as an outcome or curriculum ' .
            'requirement, not as a regulated competency. Use the framework terms supplied below and do not introduce ' .
            'terminology or compliance claims from another framework. Return valid JSON only. Cell suggestions must contain ' .
            'only short activity identifiers (T1, Q1, Act 1, P1, Ch1, AS). For every suggested activity column, add a ' .
            'coverageTypes entry keyed by the exact same column id with one value: "introduced", "practised", or "assessed". ' .
            'Use "introduced" for first teaching/exposure, "practised" for rehearsal or formative work, and "assessed" only ' .
            'when the activity evaluates learner achievement. Do not classify an activity as assessed merely because it ' .
            'contains a question. Do not suggest existing cells again. Identify gaps honestly. Required shape: ' .
            '{"mappings":[{"rowId":"exact row ' .
            'id","suggestions":{"exact_col_id":"T1"},"coverageTypes":{"exact_col_id":"introduced"},"gaps":"optional"}],"summa' .
            'ry":{"totalRows":0,"fullyCovered":0,"partiallyCovered":0,"notCovered":0,"recommendations":[]}}');
        $user = ('Framework details:
' . self::js_str($details) . '

[ROW] OUTCOMES:
' . self::js_str(self::js_get($input, 'rows')) . '

ACTIVITY COLUMNS:
' . self::js_str(self::js_get($input, 'columns')) . '

COURSE ACTIVITIES:
' . self::js_str(self::js_get($input, 'activities')) . '

EXISTING MAPPINGS:
' . self::js_str(self::js_or(self::js_get($input, 'existingMappings'), '(none)')) . '

Map every outcome to relevant teaching and assessment activities. For each suggested column, return a coverage type. Return ' .
        'JSON only.');
        return [$system, $user];
    }

    /**
     * Port of describeCourseFramework().
     *
     * @param string $id Framework id.
     * @param array $data Framework data.
     * @return array label, levels, metadata
     */
    private static function describe_framework(string $id, array $data): array {
        $defs = [
            'vet' => ['VET', [['unit', 'Unit'], ['element', 'Element'], ['performanceCriterion', 'Performance criterion']],
                [['unitCode', 'Unit code'], ['aqfLevel', 'AQF level'], ['nominalHours', 'Nominal hours'],
                 ['performanceEvidence', 'Performance evidence'], ['knowledgeEvidence', 'Knowledge evidence']]],
            'au_curriculum' => ['Australian Curriculum', [['learningArea', 'Learning area'], ['strand', 'Strand'],
                ['subStrand', 'Sub-strand'], ['contentDescriptor', 'Content descriptor']],
                [['yearLevel', 'Year level'], ['achievementStandard', 'Achievement standard']]],
            'sg_moe' => ['Singapore MOE', [['syllabus', 'Syllabus'], ['theme', 'Theme'], ['learningOutcome', 'Learning ' .
                'outcome']],
                [['level', 'Level'], ['syllabusCode', 'Syllabus code']]],
            'university' => ['University', [['course', 'Course'], ['courseLearningOutcome', 'Course learning outcome']],
                [['creditPoints', 'Credit points'], ['graduateAttributes', 'Graduate attributes']]],
            'workplace' => ['Workplace', [['program', 'Program'], ['requirement', 'Requirement']],
                [['role', 'Role'], ['policyReference', 'Policy reference']]],
            'custom' => ['Custom', [], []],
        ];
        [$label, $levelkeys, $metakeys] = $defs[$id];
        $levels = [];
        if ($id === 'custom') {
            $custom = isset($data['customLevels']) && is_array($data['customLevels']) && self::is_list($data['customLevels'])
                ? array_slice($data['customLevels'], 0, 4) : [];
            foreach ($custom as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $l = self::clean_text($item['label'] ?? null, 80);
                $v = self::clean_text($item['value'] ?? null);
                if ($l !== '' && $v !== '') {
                    $levels[] = "{$l}: {$v}";
                }
            }
        } else {
            foreach ($levelkeys as [$k, $l]) {
                $v = self::clean_text($data[$k] ?? null);
                if ($v !== '') {
                    $levels[] = "{$l}: {$v}";
                }
            }
        }
        $metadata = [];
        foreach ($metakeys as [$k, $l]) {
            $v = self::clean_text($data[$k] ?? null);
            if ($v !== '') {
                $metadata[] = "{$l}: {$v}";
            }
        }
        return ['label' => $label, 'levels' => $levels, 'metadata' => $metadata];
    }

    /**
     * Port of parseCourseFrameworkData().
     *
     * @param mixed $value Framework data or its JSON.
     * @return array
     */
    private static function parse_framework_data($value): array {
        if (is_string($value)) {
            $parsed = json_decode($value, true);
            return (is_array($parsed) && !self::is_list($parsed)) ? $parsed : [];
        }
        return (is_array($value) && !self::is_list($value)) ? $value : [];
    }

    /**
     * Port of cleanText().
     *
     * @param mixed $value Value.
     * @param int $limit UTF-16 length limit.
     * @return string
     */
    private static function clean_text($value, int $limit = 1000): string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', self::js_str($value));
        return self::js_slice(self::js_trim($s), 0, $limit);
    }

    /**
     * Array.join() prints null and undefined as empty.
     *
     * @param mixed $v Item.
     * @return string
     */
    private static function join_item($v): string {
        return $v === null ? '' : self::js_str($v);
    }

    /**
     * JavaScript String.trim(): also trims Unicode spaces.
     *
     * @param mixed $s Text.
     * @return string
     */
    private static function js_trim($s): string {
        return preg_replace(
            '/^[\s\x{00A0}\x{FEFF}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+'
            . '|[\s\x{00A0}\x{FEFF}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/u',
            '',
            self::js_str($s)
        );
    }

    /**
     * Template value of a property as JavaScript prints it: "undefined" when it is missing.
     *
     * @param mixed $o Object.
     * @param string $k Key.
     * @return string
     */
    private static function prop($o, string $k): string {
        return (is_array($o) && array_key_exists($k, $o)) ? self::js_str($o[$k]) : 'undefined';
    }

    /**
     * Object.entries() of a decoded JSON object.
     *
     * @param array $o Object.
     * @return array [key, value] pairs.
     */
    private static function entries(array $o): array {
        $out = [];
        foreach ($o as $k => $v) {
            $out[] = [(string) $k, $v];
        }
        return $out;
    }

    /**
     * Call the site's own AI.
     *
     * @param string $system System prompt.
     * @param string $user User prompt.
     * @param int $maxtokens Output budget.
     * @param float $temperature Temperature.
     * @param bool $json Ask for JSON.
     * @return string
     */
    private static function ai(string $system, string $user, int $maxtokens, float $temperature, bool $json): string {
        if (self::$aicall) {
            return (string) call_user_func(self::$aicall, $system, $user, $maxtokens, $temperature, $json);
        }
        return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, [
            'json' => $json, 'maxtokens' => $maxtokens, 'temperature' => $temperature,
        ]);
    }

    /**
     * A teacher-readable message for a failed AI call.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    private static function message(\Throwable $e): string {
        if ($e instanceof \moodle_exception && $e->errorcode === 'byok_notavailable') {
            return get_string('byok_notavailable', 'learningmapping');
        }
        return get_string('byok_failed', 'learningmapping', s($e->getMessage()));
    }

    /**
     * array_is_list() for PHP before 8.1.
     *
     * @param array $a Array.
     * @return bool
     */
    private static function is_list(array $a): bool {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    // JavaScript semantics used by the ported prompt templates (generated with the port).

    /**
     * Js truthy.
     *
     * @param mixed $v
     * @return bool JavaScript truthiness.
     */
    private static function js_truthy($v): bool {
        if (is_array($v) || is_object($v)) {
            return true;
        }
        if (is_float($v) && is_nan($v)) {
            return false;
        }
        return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0;
    }

    /**
     * Js or.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed a || b
     */
    private static function js_or($a, $b) {
        return self::js_truthy($a) ? $a : $b;
    }

    /**
     * Js and.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed a && b
     */
    private static function js_and($a, $b) {
        return self::js_truthy($a) ? $b : $a;
    }

    /**
     * Js add.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed a + b
     */
    private static function js_add($a, $b) {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a + $b;
        }
        return self::js_str($a) . self::js_str($b);
    }

    /**
     * Js str.
     *
     * @param mixed $v
     * @return string String(v)
     */
    private static function js_str($v): string {
        if ($v === null) {
            return 'null';
        }
        if ($v === true) {
            return 'true';
        }
        if ($v === false) {
            return 'false';
        }
        if (is_float($v)) {
            if (floor($v) == $v && abs($v) < 1e15) {
                return (string) (int) $v;
            }
            return rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
        }
        if (is_array($v)) {
            if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
                return implode(',', array_map(function ($x) {
                    return $x === null ? '' : self::js_str($x);
                }, $v));
            }
            return '[object Object]';
        }
        return (string) $v;
    }

    /**
     * Js num.
     *
     * @param mixed $v
     * @return float Number(v)
     */
    private static function js_num($v) {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if ($v === null || $v === false || (is_string($v) && trim($v) === '')) {
            return 0;
        }
        if ($v === true) {
            return 1;
        }
        return is_numeric(trim((string) $v)) ? trim((string) $v) + 0 : NAN;
    }

    /**
     * Js get.
     *
     * @param mixed $o
     * @param mixed $k
     * @return mixed o[k] or null
     */
    private static function js_get($o, $k) {
        if (is_array($o)) {
            return $o[$k] ?? null;
        }
        if (is_string($o) && is_int($k)) {
            return mb_substr($o, $k, 1);
        }
        return null;
    }

    /**
     * Js len.
     *
     * @param mixed $v
     * @return int .length
     */
    private static function js_len($v): int {
        if (is_array($v)) {
            return count($v);
        }
        return strlen(mb_convert_encoding(self::js_str($v), 'UTF-16LE', 'UTF-8')) / 2;
    }

    /**
     * Js includes.
     *
     * @param mixed $h
     * @param mixed $n
     * @return bool .includes()
     */
    private static function js_includes($h, $n): bool {
        if (is_array($h)) {
            return in_array($n, $h, true);
        }
        return strpos(self::js_str($h), self::js_str($n)) !== false;
    }

    /**
     * Js slice.
     *
     * @param mixed $v
     * @param int $a
     * @param int|null $b
     * @return mixed .slice() on strings (UTF-16) and arrays
     */
    private static function js_slice($v, $a = 0, $b = null) {
        if (is_array($v)) {
            $n = count($v);
            $a = $a < 0 ? max(0, $n + $a) : min($a, $n);
            $b = $b === null ? $n : ($b < 0 ? max(0, $n + $b) : min($b, $n));
            return array_slice($v, $a, max(0, $b - $a));
        }
        $u = mb_convert_encoding(self::js_str($v), 'UTF-16LE', 'UTF-8');
        $n = strlen($u) / 2;
        $a = $a < 0 ? max(0, $n + $a) : min($a, $n);
        $b = $b === null ? $n : ($b < 0 ? max(0, $n + $b) : min($b, $n));
        return mb_convert_encoding(substr($u, $a * 2, max(0, $b - $a) * 2), 'UTF-8', 'UTF-16LE');
    }

    /**
     * Js map.
     *
     * @param mixed $v
     * @param callable $f
     * @return array .map()
     */
    private static function js_map($v, callable $f): array {
        $out = [];
        foreach (array_values((array) $v) as $i => $x) {
            $out[] = $f($x, $i);
        }
        return $out;
    }

    /**
     * Js filter.
     *
     * @param mixed $v
     * @param callable $f
     * @return array .filter()
     */
    private static function js_filter($v, callable $f): array {
        $out = [];
        foreach (array_values((array) $v) as $i => $x) {
            if (self::js_truthy($f($x, $i))) {
                $out[] = $x;
            }
        }
        return $out;
    }

    /**
     * Js typeof.
     *
     * @param mixed $v
     * @return string typeof
     */
    private static function js_typeof($v): string {
        if (is_string($v)) {
            return 'string';
        }
        if (is_int($v) || is_float($v)) {
            return 'number';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        return $v === null ? 'undefined' : 'object';
    }

    /**
     * Js replace first.
     *
     * @param mixed $s
     * @param string $a
     * @param string $b
     * @return string .replace(string, string)
     */
    private static function js_replace_first($s, $a, $b): string {
        $s = self::js_str($s);
        $p = strpos($s, $a);
        return $p === false ? $s : substr_replace($s, $b, $p, strlen($a));
    }
}
