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
 * External service for polling Typesense background re-index progress.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use html_writer;
use search_typesense\engine;

/**
 * Returns live background re-index progress and per-area document counts for the admin page.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_reindex_status extends external_api {
    /**
     * Define parameters for get_reindex_status.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Execute get_reindex_status.
     *
     * @return array Status payload for the Fast Area Re-index admin page.
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);
        require_capability('moodle/site:config', $systemcontext);

        \core\session\manager::write_close();

        $tsengine = new engine();
        $queuedtasks = engine::get_queued_reindex_tasks();
        $queuedbyarea = [];
        foreach ($queuedtasks as $tinfo) {
            if (!empty($tinfo['areaid'])) {
                $queuedbyarea[$tinfo['areaid']] = $tinfo;
            }
        }
        $activearea = (string)get_config('search_typesense', 'active_reindex_area');
        $activeprogressraw = (string)get_config('search_typesense', 'active_reindex_progress');
        $activeprogress = $activeprogressraw !== '' ? json_decode($activeprogressraw, true) : null;
        if (empty($queuedtasks) && $activearea !== '') {
            $lastupdated = is_array($activeprogress) ? (int)($activeprogress['updated'] ?? 0) : 0;
            if ($lastupdated > 0 && (time() - $lastupdated) > 120) {
                unset_config('active_reindex_area', 'search_typesense');
                unset_config('active_reindex_progress', 'search_typesense');
                $activearea = '';
                $activeprogress = null;
            }
        }

        $areacounts = $tsengine->get_area_document_counts();
        $searchareas = \core_search\manager::get_search_areas_list(true);
        $searchmanager = null;
        try {
            $searchmanager = \core_search\manager::instance();
        } catch (\Throwable $e) {
            debugging('Search manager init skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $areasconfig = $searchmanager ? $searchmanager->get_areas_config($searchareas) : [];

        $progresstext = '';
        $pct = 0;
        if (is_array($activeprogress) && !empty($activeprogress['areaid'])) {
            $pinfo = (object)[
                'areaid' => s((string)$activeprogress['areaid']),
                'docs' => number_format((int)($activeprogress['docs'] ?? 0)),
                'records' => number_format((int)($activeprogress['records'] ?? 0)),
                'batches' => number_format((int)($activeprogress['batches'] ?? 0)),
                'rate' => number_format((int)($activeprogress['rate'] ?? 0)),
            ];
            $progresstext = get_string('reindexareas_bg_active_progress', 'search_typesense', $pinfo);
            $cursor = (int)($activeprogress['cursor'] ?? 0);
            $maxid = (int)($activeprogress['maxid'] ?? 0);
            if ($maxid > 0 && $cursor > 0) {
                $pct = min(100, max(2, (int)round(($cursor / $maxid) * 100)));
            }
        }

        $rows = [];
        foreach ($searchareas as $id => $area) {
            $tsdoccount = $areacounts[$id] ?? 0;
            $countbadgeclass = $tsdoccount > 0
                ? 'badge badge-success bg-success text-white'
                : 'badge badge-secondary bg-secondary text-white';
            $counthtml = html_writer::tag('span', number_format($tsdoccount), ['class' => $countbadgeclass]);

            $cfg = $areasconfig[$id] ?? null;
            $newestdoc = '-';
            if ($cfg && !empty($cfg->lastindexrun)) {
                $newestdoc = is_numeric($cfg->lastindexrun)
                    ? userdate((int)$cfg->lastindexrun)
                    : (string)$cfg->lastindexrun;
            }

            if (isset($queuedbyarea[$id]) || $activearea === $id) {
                $isrunning = ($activearea === $id) || !empty($queuedbyarea[$id]['timestarted']);
                $statusstr = $isrunning
                    ? get_string('reindexareas_status_running', 'search_typesense')
                    : get_string('reindexareas_status_bgqueued', 'search_typesense');
                $lastrun = html_writer::tag('span', $statusstr, ['class' => 'badge badge-primary bg-primary text-white']);
                if ($cfg && !empty($cfg->docsprocessed)) {
                    $doccounttxt = '(' . number_format((int)$cfg->docsprocessed) . ' docs)';
                    $lastrun .= ' ' . html_writer::tag('span', $doccounttxt, ['class' => 'small']);
                }
            } else if ($cfg && !empty($cfg->indexingstart)) {
                $duration = max(0, (int)$cfg->indexingend - (int)$cfg->indexingstart);
                $lastrun = $duration . 's (' . number_format((int)$cfg->docsprocessed) . ' docs)';
                if (!empty($cfg->partial)) {
                    $lastrun .= ' ' . html_writer::tag(
                        'span',
                        get_string('searchpartial', 'admin'),
                        ['class' => 'badge badge-warning bg-warning text-dark']
                    );
                }
            } else {
                $lastrun = html_writer::tag(
                    'span',
                    get_string('reindexareas_status_pending', 'search_typesense'),
                    ['class' => 'badge badge-info bg-info text-white']
                );
            }

            $rows[] = [
                'areaid' => (string)$id,
                'counthtml' => $counthtml,
                'newestdoc' => s($newestdoc),
                'lastrunhtml' => $lastrun,
            ];
        }

        return [
            'active' => (!empty($queuedtasks) || $activearea !== ''),
            'title' => get_string('reindexareas_bg_active_title', 'search_typesense', count($queuedtasks)),
            'progresstext' => $progresstext,
            'pct' => $pct,
            'rows' => $rows,
        ];
    }

    /**
     * Define return structure for get_reindex_status.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'active' => new external_value(PARAM_BOOL, 'Whether background re-indexing is currently active'),
            'title' => new external_value(PARAM_RAW, 'Banner title text'),
            'progresstext' => new external_value(PARAM_RAW, 'Formatted progress HTML'),
            'pct' => new external_value(PARAM_INT, 'Completion percentage (0-100)'),
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'areaid' => new external_value(PARAM_RAW, 'Search area identifier'),
                    'counthtml' => new external_value(PARAM_RAW, 'Document count badge HTML'),
                    'newestdoc' => new external_value(PARAM_RAW, 'Formatted newest document timestamp'),
                    'lastrunhtml' => new external_value(PARAM_RAW, 'Formatted last run status HTML'),
                ])
            ),
        ]);
    }
}
