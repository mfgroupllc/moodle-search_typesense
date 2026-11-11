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

/**
 * Post-install hook for the Typesense search engine plugin.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Populate initial current-course categories when installing on an existing site.
 *
 * @return bool
 */
function xmldb_search_typesense_install(): bool {
    if (get_config('search_typesense', 'userprofilefields') === false) {
        set_config(
            'userprofilefields',
            \search_typesense\user_profile_fields::FOLLOW_SHOWUSERIDENTITY,
            'search_typesense'
        );
    }
    if (get_config('search_typesense', 'enablevideotimetab') === false) {
        set_config('enablevideotimetab', 1, 'search_typesense');
    }
    if (get_config('search_typesense', 'videotimetablabel') === false) {
        set_config('videotimetablabel', get_string('scopevideotime', 'search_typesense'), 'search_typesense');
    }
    try {
        \search_typesense\course_scope::apply_inferred_categories(true);
    } catch (\Throwable $e) {
        debugging('Typesense initial category inference skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    return true;
}
