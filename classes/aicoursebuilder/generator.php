<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_learningmapping\aicoursebuilder;

defined('MOODLE_INTERNAL') || die();

/**
 * AI Course Builder adapter for AI Mapping.
 *
 * @package    mod_learningmapping
 * @copyright  2026 LMS Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    private const COMPONENT = 'mod_learningmapping';
    private const PRICE_PER_ANALYSIS = 100;

    public static function native_estimate(array $brief): int {
        return self::PRICE_PER_ANALYSIS;
    }

    public static function native_generate(int $cmid, array $brief): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/learningmapping/lib.php');
        require_once($CFG->dirroot . '/mod/learningmapping/classes/manifest_storage.php');
        $cm = get_coursemodule_from_id('learningmapping', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('learningmapping', ['id' => $cm->instance], '*', MUST_EXIST);
        $unit = $DB->get_record('local_aicoursebuilder_unit', ['courseid' => $cm->course], 'programid', MUST_EXIST);
        $outcomes = self::outcome_rows((int)$unit->programid, (int)$cm->course);
        $modules = self::course_modules((int)$cm->course, $cmid);
        if (!$outcomes || !$modules['modules']) {
            throw new \moodle_exception(
                'generationfailed',
                'local_aicoursebuilder',
                '',
                '',
                'AI Mapping needs curriculum outcomes and generated lesson activities.'
            );
        }
        $frameworkdata = json_decode((string)($instance->frameworkdata ?? ''), true);
        $payload = [
            'rows' => $outcomes,
            'columns' => $modules['columns'],
            'scannedModules' => $modules['modules'],
            'unitCode' => (string)($instance->unitcode ?? ''),
            'framework' => (string)($instance->framework ?? 'vet'),
            'frameworkdata' => is_array($frameworkdata) ? $frameworkdata : [],
        ];
        $started = \local_aicoursebuilder\local\api_client::request_json(
            'POST',
            '/api/generate-learningmapping/start',
            $payload,
            20
        );
        if (empty($started['ok']) || empty($started['jobId'])) {
            throw new \moodle_exception('generationfailed', 'local_aicoursebuilder', '', '', 'AI Mapping did not return a job id.');
        }
        return clean_param((string)$started['jobId'], PARAM_ALPHANUMEXT);
    }

    public static function native_status(int $cmid, string $jobid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/learningmapping/lib.php');
        require_once($CFG->dirroot . '/mod/learningmapping/classes/manifest_storage.php');
        $cm = get_coursemodule_from_id('learningmapping', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('learningmapping', ['id' => $cm->instance], '*', MUST_EXIST);
        $poll = \local_aicoursebuilder\local\api_client::request_json(
            'GET',
            '/api/jobs/' . rawurlencode($jobid),
            null,
            20
        );
        if (in_array(($poll['status'] ?? ''), ['pending', 'processing'], true)) {
            return ['status' => 'running', 'retryable' => false];
        }
        if (($poll['status'] ?? '') === 'error') {
            return ['status' => 'failed', 'retryable' => false, 'error' => clean_param((string)($poll['error'] ?? 'AI Mapping generation failed.'), PARAM_TEXT)];
        }
        $result = $poll['result'] ?? null;
        if (($poll['status'] ?? '') !== 'done' || !is_array($result) || empty($result['ok']) || !is_array($result['mappings'] ?? null)) {
            return ['status' => 'failed', 'retryable' => false, 'error' => clean_param((string)($result['error'] ?? 'AI Mapping returned an invalid result.'), PARAM_TEXT)];
        }
        $unit = $DB->get_record('local_aicoursebuilder_unit', ['courseid' => $cm->course], 'programid', MUST_EXIST);
        $rows = self::outcome_rows((int)$unit->programid, (int)$cm->course);
        $modules = self::course_modules((int)$cm->course, $cmid);
        $cells = [];
        $coverage = [];
        foreach ($result['mappings'] as $mapping) {
            $rowid = (string)($mapping['rowId'] ?? '');
            if ($rowid === '' || empty($mapping['suggestions']) || !is_array($mapping['suggestions'])) {
                continue;
            }
            foreach ($mapping['suggestions'] as $columnid => $suggestion) {
                if (!is_string($suggestion) || trim($suggestion) === '') {
                    continue;
                }
                $cells[$rowid . '::' . (string)$columnid] = $suggestion;
                $type = $mapping['coverageTypes'][$columnid] ?? null;
                if (in_array($type, ['introduced', 'practised', 'assessed'], true)) {
                    $coverage[$rowid . '::' . (string)$columnid] = $type;
                }
            }
        }
        self::add_planned_links((int)$cm->course, $rows, $cells, $coverage);
        $mappingdata = [
            'rows' => $rows,
            'columns' => $modules['columns'],
            'cells' => $cells,
            'unitData' => null,
            'acData' => [],
            'coverage' => $coverage,
            'courseModules' => $modules['modules'],
            'dirty' => false,
            'courseBuilderGenerated' => true,
        ];
        learningmapping_store_mappingdata((int)$instance->id, $mappingdata, (string)($instance->unitcode ?? ''));
        return [
            'status' => 'complete',
            'retryable' => false,
            'result' => ['rowcount' => count($rows), 'columncount' => count($modules['columns']), 'cellcount' => count($cells)],
        ];
    }

    public static function native_save_result(int $cmid, array $result): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/learningmapping/classes/manifest_storage.php');
        $cm = get_coursemodule_from_id('learningmapping', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('learningmapping', ['id' => $cm->instance], '*', MUST_EXIST);
        $saved = json_decode(\mod_learningmapping\manifest_storage::decompress($instance->mappingdata ?? '') ?? '', true);
        if (!is_array($saved) || empty($saved['courseBuilderGenerated'])
                || count($saved['rows'] ?? []) !== (int)($result['rowcount'] ?? -1)
                || count($saved['columns'] ?? []) !== (int)($result['columncount'] ?? -1)) {
            throw new \moodle_exception('emptygeneratorresult', 'local_aicoursebuilder');
        }
    }

    /**
     * @param int $programid
     * @param int $courseid limit to the outcomes of the unit that owns this course (0 for all)
     * @return array
     */
    private static function outcome_rows(int $programid, int $courseid = 0): array {
        global $DB;
        if ($programid < 1) {
            return [];
        }
        $records = $DB->get_records('local_aicoursebuilder_outcome', ['programid' => $programid], 'sortorder ASC');
        // Map each course against the outcomes its own unit teaches, not the whole program.
        if ($courseid > 0 && method_exists('\\local_aicoursebuilder\\local\\course_builder', 'unit_outcomes')) {
            $keep = \local_aicoursebuilder\local\course_builder::unit_outcomes($courseid);
            if ($keep) {
                $records = array_intersect_key($records, $keep);
            }
        }
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => 'outcome_' . (int)$record->id,
                'title' => clean_param((string)$record->outcometext, PARAM_TEXT),
                'isElement' => false,
                'isShaded' => false,
                'parentId' => null,
            ];
        }
        return $rows;
    }

    /**
     * @param int $courseid
     * @param int $mappingcmid
     * @return array
     */
    private static function course_modules(int $courseid, int $mappingcmid): array {
        global $DB;
        $modinfo = get_fast_modinfo($courseid);
        $planned = self::planned_outcomes_by_section($courseid);
        $columns = [];
        $modules = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress) || (int)$cm->id === $mappingcmid
                    || in_array($cm->modname, ['courseinfo', 'learningmapping'], true)) {
                continue;
            }
            // The course announcements forum teaches nothing, so it is not a mapping column.
            if ($cm->modname === 'forum' && $DB->get_field('forum', 'type', ['id' => $cm->instance]) === 'news') {
                continue;
            }
            $columnid = 'cm_' . (int)$cm->id;
            $section = $modinfo->get_section_info($cm->sectionnum);
            $sectionname = $section ? format_string($section->name ?: get_string('section') . ' ' . $cm->sectionnum) : '';
            $columns[] = ['id' => $columnid, 'title' => format_string($cm->name), 'group' => 'learning'];
            $entry = [
                'modname' => clean_param((string)$cm->modname, PARAM_ALPHANUMEXT),
                'name' => format_string($cm->name),
                'sectionName' => $sectionname,
            ];
            $description = trim(strip_tags((string)$cm->content));
            $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
            if ($instance) {
                $entry += self::module_content($cm->modname, $instance);
                if ($description === '' && !empty($instance->intro)) {
                    $description = trim(strip_tags((string)$instance->intro));
                }
            }
            $targets = $planned[(int)$cm->sectionnum] ?? [];
            if ($targets) {
                $description = trim('Built in AI Course Builder to teach these curriculum outcomes: '
                    . implode('; ', $targets) . '. ' . $description);
            }
            $entry['description'] = \core_text::substr($description, 0, 1500);
            $modules[] = $entry;
        }
        return ['columns' => $columns, 'modules' => $modules];
    }

    /**
     * What an activity actually contains, in the shape /api/generate-learningmapping reads.
     *
     * @param string $modname
     * @param \stdClass $instance
     * @return array
     */
    private static function module_content(string $modname, \stdClass $instance): array {
        global $DB;
        $out = [];
        try {
            if ($modname === 'visuallearning') {
                $pages = $DB->get_records('visuallearning_pages', ['visuallearningid' => $instance->id], 'pagenum ASC',
                    'id, title, concepttext, outcometext');
                $out['pages'] = array_values(array_map(static function ($p) {
                    return ['title' => trim((string)$p->title . ((string)$p->outcometext !== '' ? ' (' . $p->outcometext . ')' : ''))];
                }, $pages));
            } else if ($modname === 'aiknowledgecheck') {
                $qs = $DB->get_records('aiknowledgecheck_questions', ['aiknowledgecheckid' => $instance->id], 'questionnumber ASC, id ASC',
                    'id, questiontext, mappingtopic, mappingcriteria');
                $out['questions'] = array_values(array_map(static function ($q) {
                    return ['question' => trim(strip_tags((string)$q->questiontext)), 'topic' => (string)($q->mappingtopic ?? ''),
                        'criteria' => (string)($q->mappingcriteria ?? '')];
                }, $qs));
            } else if ($modname === 'aiactivities' && !empty($instance->activitiesjson)) {
                $acts = json_decode((string)$instance->activitiesjson, true);
                if (is_array($acts)) {
                    $out['activities'] = array_values(array_map(static function ($a) {
                        return ['type' => (string)($a['type'] ?? 'activity'), 'title' => (string)($a['title'] ?? $a['topic'] ?? 'Untitled')];
                    }, $acts));
                }
            } else if ($modname === 'contentcreator' && !empty($instance->manifestjson)) {
                $manifest = json_decode((string)$instance->manifestjson, true);
                foreach (($manifest['topics'] ?? []) as $topic) {
                    $out['topics'][] = ['title' => (string)($topic['title'] ?? 'Untitled'),
                        'sections' => array_map(static fn($sec) => (string)($sec['title'] ?? ''), $topic['sections'] ?? [])];
                }
            }
        } catch (\Throwable $e) {
            // Content is extra context only; the mapping still runs on names and planned outcomes.
            debugging('AI Mapping scan skipped ' . $modname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $out;
    }

    /**
     * The outcomes each lesson section was planned to teach, keyed by section number.
     *
     * @param int $courseid
     * @return array<int, string[]>
     */
    private static function planned_outcomes_by_section(int $courseid): array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_aicoursebuilder_lesson') || !$dbman->table_exists('local_aicoursebuilder_unit')) {
            return [];
        }
        $unit = $DB->get_record('local_aicoursebuilder_unit', ['courseid' => $courseid], 'id');
        if (!$unit) {
            return [];
        }
        $out = [];
        foreach ($DB->get_records('local_aicoursebuilder_lesson', ['unitid' => $unit->id], 'lessonnumber ASC',
                'id, lessonnumber, sectionid, outcomesjson') as $lesson) {
            $sectionnum = $lesson->sectionid ? (int)$DB->get_field('course_sections', 'section', ['id' => $lesson->sectionid]) : (int)$lesson->lessonnumber;
            foreach (json_decode((string)$lesson->outcomesjson, true) ?: [] as $outcome) {
                $text = trim(is_array($outcome) ? (string)($outcome['text'] ?? '') : (string)$outcome);
                if ($text !== '') {
                    $out[$sectionnum][] = $text;
                }
            }
        }
        return $out;
    }

    /**
     * The short mapping code the matrix uses for a whole activity (P1-P4, Q1-Q5, Act 1-5, T1).
     *
     * @param string $modname
     * @param \stdClass|false $instance
     * @return string
     */
    private static function short_code(string $modname, $instance): string {
        $content = $instance ? self::module_content($modname, $instance) : [];
        $range = static function (string $prefix, int $n, string $sep = ''): string {
            return $n <= 1 ? $prefix . $sep . '1' : $prefix . $sep . '1-' . ($sep === '' ? $prefix : '') . $n;
        };
        if (!empty($content['pages'])) {
            return $range('P', count($content['pages']));
        }
        if (!empty($content['questions'])) {
            return $range('Q', count($content['questions']));
        }
        if (!empty($content['activities'])) {
            return $range('Act', count($content['activities']), ' ');
        }
        if (!empty($content['topics'])) {
            return $range('T', count($content['topics']));
        }
        return 'T1';
    }

    /**
     * Make sure every planned outcome-to-activity link is in the matrix, with the coverage
     * its activity type gives (teach = introduced, practise = practised, check = assessed).
     * AI suggestions are kept; planned links the AI missed are added.
     *
     * @param int $courseid
     * @param array $rows
     * @param array $cells
     * @param array $coverage
     * @return void
     */
    private static function add_planned_links(int $courseid, array $rows, array &$cells, array &$coverage): void {
        $planned = self::planned_outcomes_by_section($courseid);
        if (!$planned) {
            return;
        }
        $norm = static fn(string $t): string => \core_text::strtolower(rtrim(trim($t), '. '));
        $rowbytext = [];
        foreach ($rows as $row) {
            $rowbytext[$norm((string)$row['title'])] = (string)$row['id'];
        }
        $types = ['visuallearning' => 'introduced', 'contentcreator' => 'introduced', 'productexplainer' => 'introduced',
            'aiactivities' => 'practised', 'aisoftskills' => 'practised', 'aibranchedscenario' => 'practised',
            'aisafetyhazards' => 'practised', 'aianatomy' => 'practised', 'aiknowledgecheck' => 'assessed'];
        global $DB;
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_cms() as $cm) {
            $type = $types[$cm->modname] ?? null;
            if (!$type || empty($planned[(int)$cm->sectionnum])) {
                continue;
            }
            foreach ($planned[(int)$cm->sectionnum] as $text) {
                $rowid = $rowbytext[$norm($text)] ?? null;
                if ($rowid === null) {
                    continue;
                }
                $key = $rowid . '::cm_' . (int)$cm->id;
                if (!isset($cells[$key]) || trim((string)$cells[$key]) === '') {
                    $cells[$key] = self::short_code($cm->modname, $DB->get_record($cm->modname, ['id' => $cm->instance]));
                }
                if (!isset($coverage[$key])) {
                    $coverage[$key] = $type;
                }
            }
        }
    }

    public static function is_available(): bool {
        return class_exists('\local_aicoursebuilder\local\generator_runtime')
            && \local_aicoursebuilder\local\generator_runtime::component_available(self::COMPONENT);
    }

    public static function estimate(array $brief): int {
        return \local_aicoursebuilder\local\generator_runtime::estimate(self::COMPONENT, $brief);
    }

    public static function generate(int $cmid, array $brief): string {
        return \local_aicoursebuilder\local\generator_runtime::generate(self::COMPONENT, $cmid, $brief);
    }

    public static function status(int $cmid, string $jobid): array {
        return \local_aicoursebuilder\local\generator_runtime::status(self::COMPONENT, $cmid, $jobid);
    }
}
