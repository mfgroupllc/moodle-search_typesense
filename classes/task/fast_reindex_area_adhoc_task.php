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
 * Ad-hoc background task to execute a fast bulk NDJSON re-index for a single search area.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense\task;

use search_typesense\engine;

/**
 * Ad-hoc background task to execute a fast bulk NDJSON re-index for a single search area.
 *
 * Runs outside the web server request lifecycle (via Moodle CLI cron), eliminating
 * Apache mod_proxy_fcgi / ALB request timeouts on multi-million-row tables.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fast_reindex_area_adhoc_task extends \core\task\adhoc_task {
    /**
     * Return the task name shown in admin task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_fast_reindex_area', 'search_typesense');
    }

    /**
     * Execute the bulk NDJSON re-index for the target search area.
     *
     * @return void
     */
    public function execute(): void {
        \core_php_time_limit::raise(0);
        raise_memory_limit(MEMORY_HUGE);

        $data = (object)($this->get_custom_data() ?? []);
        $areaid = clean_param((string)($data->areaid ?? ''), PARAM_ALPHANUMEXT);
        $deletefirst = !empty($data->deletefirst);
        $timelimit = isset($data->timelimit) ? (int)$data->timelimit : 0;

        if ($areaid === '') {
            mtrace('search_typesense: fast_reindex_area_adhoc_task skipped (empty areaid).');
            return;
        }

        $searcharea = \core_search\manager::get_search_area($areaid);
        if (!$searcharea) {
            mtrace("search_typesense: unknown search area '{$areaid}'.");
            return;
        }

        mtrace("search_typesense: Starting fast NDJSON re-index for {$areaid} ({$searcharea->get_visible_name()})...");
        $engine = new engine();
        $stats = $engine->fast_reindex_area($searcharea, $deletefirst, $timelimit);
        mtrace(sprintf(
            'search_typesense: Completed %s — %d docs (%d records, %d batches, %d ignored) in %.2fs%s.',
            $areaid,
            $stats['docs'],
            $stats['records'],
            $stats['batches'],
            $stats['ignored'],
            $stats['elapsed'],
            !empty($stats['partial']) ? ' [PARTIAL - re-queueing continuation]' : ''
        ));

        // If a time limit was configured and the area is only partially indexed, re-queue continuation.
        if (!empty($stats['partial'])) {
            engine::enqueue_area_reindex_task($areaid, false, $timelimit);
        }
    }
}
