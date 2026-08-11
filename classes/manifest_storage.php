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
 * Manifest storage helper — gzip compression for large DB columns.
 *
 * Prevents MySQL max_allowed_packet errors when mappingdata or unitdata
 * exceeds 512 KB (VET full mapping matrices can reach several MB).
 *
 * Format: raw JSON stored as-is (backward-compatible).
 *         Compressed: "gz:" + base64(gzencode(data, 6))
 *
 * @package    mod_learningmapping
 * @copyright  2025 AI Grader <support@lmshostingservices.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learningmapping;

defined('MOODLE_INTERNAL') || die();

class manifest_storage {
    const THRESHOLD = 524288;
    const PREFIX    = 'gz:';

    public static function compress(?string $data): ?string {
        if ($data === null || $data === '') {
            return $data;
        }
        if (strlen($data) < self::THRESHOLD) {
            return $data;
        }
        $compressed = gzencode($data, 6);
        if ($compressed === false) {
            return $data;
        }
        $result = self::PREFIX . base64_encode($compressed);
        error_log('[LM SAVE] Compressed ' . strlen($data) . ' B → ' . strlen($result) . ' B');
        return $result;
    }

    public static function decompress(?string $data): ?string {
        if ($data === null || $data === '') {
            return $data;
        }
        if (strncmp($data, self::PREFIX, 3) !== 0) {
            return $data;
        }
        $decoded = base64_decode(substr($data, 3), true);
        if ($decoded === false) {
            return $data;
        }
        $decompressed = gzdecode($decoded);
        if ($decompressed === false) {
            return $data;
        }
        return $decompressed;
    }
}
