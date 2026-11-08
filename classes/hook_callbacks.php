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
 * Hook callbacks for routing browser global search to the Typesense search page.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Routes browser searches to the optional plugin controller.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Return the active query engine name from Moodle configuration.
     *
     * @return string
     */
    public static function active_engine_name(): string {
        global $CFG;
        return !empty($CFG->searchenginequeryonly) ? (string)$CFG->searchenginequeryonly : (string)($CFG->searchengine ?? '');
    }

    /**
     * Whether the alternate configuration of Typesense is selected for querying.
     *
     * @return bool
     */
    public static function is_alternate_engine(): bool {
        return self::active_engine_name() === 'typesense-alternate';
    }

    /**
     * Whether the selected query engine uses the optional search page.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $engine = self::active_engine_name();
        return in_array($engine, ['typesense', 'typesense-alternate'], true)
            && (bool)get_config('search_typesense', 'candidatefirst');
    }

    /**
     * Redirect standard browser GET searches before core builds all access contexts.
     *
     * POST requests stay with core so an existing core form submission is never lost.
     * The plugin's form posts directly to query.php on subsequent searches.
     *
     * @param \core\hook\after_config $hook Hook instance.
     * @return void
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $CFG, $SCRIPT;

        if ((defined('CLI_SCRIPT') && CLI_SCRIPT) || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)
                || during_initial_install() || isset($CFG->upgraderunning)) {
            return;
        }
        if ($SCRIPT !== '/search/index.php' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
                || !self::is_enabled()) {
            return;
        }

        // The destination is fixed on this Moodle site. Encode parameters instead of
        // appending an untrusted request URI, preserving array filters and query text.
        $target = (new \moodle_url('/search/engine/typesense/query.php'))->out(false);
        $query = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
        if ($query !== '') {
            $target .= '?' . $query;
        }
        redirect($target);
    }
}
