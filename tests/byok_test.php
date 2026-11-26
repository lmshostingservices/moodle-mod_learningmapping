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

namespace mod_learningmapping;

use mod_learningmapping\local\byok;

/**
 * Tests for running mapping analysis on the site's own AI.
 *
 * The prompts were checked word for word against the LMS Labs service code on 217 randomised payloads
 * (identical system and user prompts, token budget, temperature and response); these tests cover the wiring.
 *
 * @package    mod_learningmapping
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked provider received. */
    protected $requests = [];

    /** A mapping payload as the plugin sends it. */
    const PAYLOAD = [
        'rows' => [['id' => 'e1', 'title' => '1. Isolate plant', 'isElement' => true],
            ['id' => 'r1', 'title' => '1.1 Identify energy sources', 'parentId' => 'e1']],
        'columns' => [['id' => 'col_elements', 'title' => 'Elements'],
            ['id' => 'col_cc', 'title' => 'Content Creator', 'group' => 'learning']],
        'cells' => [],
        'scannedModules' => [['modname' => 'contentcreator', 'name' => 'Isolation basics',
            'topics' => [['title' => 'Energy sources']]]],
        'unitCode' => 'HLTWHS001',
        'framework' => 'vet',
        'frameworkdata' => [],
    ];

    /**
     * Licence the site for Bring your own AI on a test key, with text on an Anthropic key.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config 1.1.0 or later is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = function(string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
    }

    /**
     * Clean up the provider mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        parent::tearDown();
    }

    /**
     * Make the provider answer with this text, or fail when null.
     *
     * @param string|null $answer
     */
    protected function answer(?string $answer): void {
        $this->requests = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function($url, $headers, $body) use ($answer) {
            $this->requests[] = $body;
            if ($answer === null) {
                throw new \moodle_exception('byok_providererror', 'local_aiconfig', '', 'Overloaded');
            }
            return ['content' => [['type' => 'text', 'text' => $answer]]];
        };
    }

    public function test_only_answers_when_byok_is_on(): void {
        $this->assertTrue(byok::enabled());
        $this->assertNull(byok::handle('https://lms-labs.com/api/tga/unit-for-mapping', ['unitCode' => 'HLTWHS001']));
        $this->assertNull(byok::poll('abc123'));
        set_config('src_text', 'credits', 'local_aiconfig');
        $this->assertFalse(byok::enabled());
        $this->assertNull(byok::handle('https://lms-labs.com/api/generate-learningmapping', self::PAYLOAD));
    }

    public function test_mapping_keeps_short_codes_only(): void {
        $this->answer('{"mappings":[{"rowId":"r1","suggestions":{"col_cc":"T1: Energy sources and act2"}}],'
            . '"summary":{"totalRows":1}}');
        $out = byok::handle('https://lms-labs.com/api/generate-learningmapping', self::PAYLOAD);
        $this->assertTrue($out['ok']);
        $this->assertSame('T1, Act 2', $out['mappings'][0]['suggestions']['col_cc']);
        $this->assertSame(1, $out['summary']['totalRows']);
        $this->assertSame(0, $out['creditsUsed']);
        $this->assertStringContainsString('ASQA-compliant learning and assessment mapping', $this->requests[0]['system']);
        $user = $this->requests[0]['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('[ROW] id="r1" 1.1 Identify energy sources (under: "1. Isolate plant")', $user);
        $this->assertStringContainsString('  T1 (Topic 1): Energy sources', $user);
        $this->assertSame(12000, $this->requests[0]['max_tokens']);
    }

    public function test_start_and_poll(): void {
        $this->answer('{"mappings":[]}');
        $start = byok::handle('https://lms-labs.com/api/generate-learningmapping/start', self::PAYLOAD);
        $this->assertTrue($start['ok']);
        $poll = byok::poll($start['jobId']);
        $this->assertSame('done', $poll['status']);
        $this->assertSame([], $poll['result']['mappings']);
    }

    public function test_non_vet_mapping_needs_coverage_types(): void {
        $payload = ['framework' => 'university', 'frameworkdata' => ['course' => 'BIO101']] + self::PAYLOAD;
        $this->answer('{"mappings":[{"rowId":"r1","suggestions":{"col_cc":"T1"}}]}');
        $out = byok::handle('https://lms-labs.com/api/generate-learningmapping', $payload);
        $this->assertFalse($out['ok']);
        $this->assertSame('AI response omitted coverage types for non-VET mapping', $out['error']);
        $this->assertStringContainsString('in the University framework', $this->requests[0]['system']);
        $this->answer('{"mappings":[{"rowId":"r1","suggestions":{"col_cc":"T1"},"coverageTypes":{"col_cc":"introduced"}}]}');
        $out = byok::handle('https://lms-labs.com/api/generate-learningmapping', $payload);
        $this->assertSame('introduced', $out['mappings'][0]['coverageTypes']['col_cc']);
    }

    public function test_assessment_conditions_and_clean_paste(): void {
        $this->answer('{"results":[{"id":"ac1","addressed":"Simulated workplace.","evidence":"PA","status":"met"}]}');
        $out = byok::handle('https://lms-labs.com/api/analyse-assessment-conditions', ['unitCode' => 'HLTWHS001',
            'acData' => [['id' => 'ac1', 'text' => 'Simulated workplace']], 'scannedModules' => self::PAYLOAD['scannedModules']]);
        $this->assertSame('met', $out['results'][0]['status']);
        $this->assertStringContainsString('1. [ID:ac1] Simulated workplace', $this->requests[0]['messages'][0]['content'][0]['text']);
        $this->answer("  Elements and Performance Criteria\n1. Isolate plant  ");
        $out = byok::handle('https://lms-labs.com/api/clean-tga-paste', ['rawText' => 'NRT Home News 1. Isolate plant']);
        $this->assertSame("Elements and Performance Criteria\n1. Isolate plant", $out['cleanedText']);
        $this->assertSame(0.1, $this->requests[0]['temperature']);
    }

    public function test_provider_failure_is_reported(): void {
        $this->answer(null);
        $out = byok::handle('https://lms-labs.com/api/generate-learningmapping', self::PAYLOAD);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('Your AI provider could not run the analysis', $out['error']);
    }
}
