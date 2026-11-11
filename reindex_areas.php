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
 * Typesense Fast Bulk NDJSON Search Area Re-indexer admin page.
 *
 * Streams search area recordsets directly into Typesense's bulk NDJSON import API
 * with raised memory limits (MEMORY_HUGE) and Primary-Key chunking, either in the
 * background via Moodle Ad-hoc Tasks (avoiding mod_proxy_fcgi timeouts) or inline.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use search_typesense\admin_nav;
use search_typesense\engine;

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$action = optional_param('action', '', PARAM_ALPHA);
$areaid = optional_param('areaid', '', PARAM_ALPHANUMEXT);
$deletefirst = optional_param('deletefirst', 0, PARAM_BOOL);

$pageurl = new moodle_url('/search/engine/typesense/reindex_areas.php');
$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('reindexareas_title', 'search_typesense'));
$PAGE->set_heading(get_string('reindexareas_title', 'search_typesense'));

$tsengine = new engine();

// Check for active/queued background ad-hoc tasks and live progress.
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

// Handle lightweight AJAX status polling (updates progress bar and table cells without full-page reload).
if ($action === 'ajaxstatus') {
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
    $pct = null;
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
            $newestdoc = is_numeric($cfg->lastindexrun) ? userdate((int)$cfg->lastindexrun) : (string)$cfg->lastindexrun;
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

        $rows[$id] = [
            'counthtml' => $counthtml,
            'newestdoc' => s($newestdoc),
            'lastrunhtml' => $lastrun,
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'active' => (!empty($queuedtasks) || $activearea !== ''),
        'title' => get_string('reindexareas_bg_active_title', 'search_typesense', count($queuedtasks)),
        'progresstext' => $progresstext,
        'pct' => $pct,
        'rows' => $rows,
    ]);
    exit;
}

// Handle POST actions that redirect immediately.
$redirectactions = ['runbg', 'runbgall', 'cancelbg', 'queuearea', 'queueall', 'fixcontexts'];
if (in_array($action, $redirectactions, true) && data_submitted()) {
    require_sesskey();

    if ($action === 'fixcontexts') {
        $res = engine::repair_unbuilt_contexts();
        redirect(
            $pageurl,
            get_string('reindexareas_contexts_fixed_count', 'search_typesense', (object)$res),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'runbg' && $areaid !== '') {
        $targetarea = \core_search\manager::get_search_area($areaid);
        if (!$targetarea) {
            throw new moodle_exception('invalidrequest');
        }
        engine::reset_area_for_reindex($targetarea);
        engine::enqueue_area_reindex_task($areaid, (bool)$deletefirst, 0);
        redirect(
            $pageurl,
            get_string('reindexareas_bg_queued_single', 'search_typesense', $targetarea->get_visible_name()),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'runbgall') {
        $enabledareas = \core_search\manager::get_search_areas_list(true);
        $queued = 0;
        foreach ($enabledareas as $id => $area) {
            if ($area->supports_get_document_recordset()) {
                engine::reset_area_for_reindex($area);
                engine::enqueue_area_reindex_task($id, false, 0);
                $queued++;
            }
        }
        redirect(
            $pageurl,
            get_string('reindexareas_bg_queued_all', 'search_typesense', $queued),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'cancelbg') {
        $removed = engine::cancel_queued_reindex_tasks($areaid);
        redirect(
            $pageurl,
            get_string('reindexareas_bg_cancelled', 'search_typesense', $removed),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    if ($action === 'queuearea' && $areaid !== '') {
        $targetarea = \core_search\manager::get_search_area($areaid);
        if (!$targetarea) {
            throw new moodle_exception('invalidrequest');
        }
        engine::reset_area_for_reindex($targetarea);
        redirect(
            $pageurl,
            get_string('reindexareas_queued_single', 'search_typesense', $targetarea->get_visible_name()),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'queueall') {
        $enabledareas = \core_search\manager::get_search_areas_list(true);
        foreach ($enabledareas as $area) {
            engine::reset_area_for_reindex($area);
        }
        redirect(
            $pageurl,
            get_string('reindexareas_queued_all', 'search_typesense', count($enabledareas)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo admin_nav::render('reindex');
echo $OUTPUT->heading(get_string('reindexareas_title', 'search_typesense'));
echo html_writer::tag('p', get_string('reindexareas_desc', 'search_typesense'), ['class' => 'text-muted mb-3']);

// Execute synchronous fast NDJSON re-index for a single area when requested.
if ($action === 'runnow' && $areaid !== '' && data_submitted()) {
    require_sesskey();
    $targetarea = \core_search\manager::get_search_area($areaid);
    if (!$targetarea || !$targetarea->supports_get_document_recordset()) {
        echo $OUTPUT->notification(get_string('invalidrequest', 'error'), 'notifyproblem');
    } else {
        echo html_writer::start_div('card mb-4 border-primary');
        echo html_writer::start_div('card-body');
        echo html_writer::tag(
            'h4',
            get_string('reindexareas_running', 'search_typesense', s($targetarea->get_visible_name())),
            ['class' => 'card-title h5']
        );
        $progress = new \core\progress\display_if_slow('');
        try {
            $stats = $tsengine->fast_reindex_area($targetarea, (bool)$deletefirst, 0, $progress);
            $summary = (object)[
                'area' => s($targetarea->get_visible_name()),
                'docs' => $stats['docs'],
                'records' => $stats['records'],
                'batches' => $stats['batches'],
                'ignored' => $stats['ignored'],
                'elapsed' => $stats['elapsed'],
            ];
            echo $OUTPUT->notification(
                get_string('reindexareas_completed', 'search_typesense', $summary),
                'notifysuccess'
            );
        } catch (\Throwable $e) {
            echo $OUTPUT->notification(s($e->getMessage()), 'notifyproblem');
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

// Re-fetch live status after any synchronous inline run above.
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
$isbgactive = (!empty($queuedtasks) || $activearea !== '');

$bannerclass = 'alert alert-info justify-content-between align-items-center flex-wrap mb-3 '
    . ($isbgactive ? 'd-flex' : 'd-none');
echo html_writer::start_div($bannerclass, ['id' => 'ts-reindex-bg-banner']);
echo html_writer::start_div('mr-3 me-3 flex-grow-1');
echo html_writer::tag(
    'strong',
    get_string('reindexareas_bg_active_title', 'search_typesense', count($queuedtasks)),
    ['id' => 'ts-reindex-bg-title']
);

$initprogresstxt = '';
$initpct = 0;
if (is_array($activeprogress) && !empty($activeprogress['areaid'])) {
    $pinfo = (object)[
        'areaid' => s((string)$activeprogress['areaid']),
        'docs' => number_format((int)($activeprogress['docs'] ?? 0)),
        'records' => number_format((int)($activeprogress['records'] ?? 0)),
        'batches' => number_format((int)($activeprogress['batches'] ?? 0)),
        'rate' => number_format((int)($activeprogress['rate'] ?? 0)),
    ];
    $initprogresstxt = get_string('reindexareas_bg_active_progress', 'search_typesense', $pinfo);
    $cur = (int)($activeprogress['cursor'] ?? 0);
    $max = (int)($activeprogress['maxid'] ?? 0);
    if ($max > 0 && $cur > 0) {
        $initpct = min(100, max(2, (int)round(($cur / $max) * 100)));
    }
}
echo html_writer::div($initprogresstxt, 'small mt-1', ['id' => 'ts-reindex-bg-progress']);

$barwrapclass = 'progress mt-2 ' . ($initpct > 0 ? '' : 'd-none');
echo html_writer::start_div($barwrapclass, ['id' => 'ts-reindex-bg-bar-wrap', 'style' => 'height: 10px; max-width: 520px;']);
echo html_writer::div(
    '',
    'progress-bar progress-bar-striped progress-bar-animated bg-primary',
    [
        'id' => 'ts-reindex-bg-bar',
        'role' => 'progressbar',
        'style' => 'width: ' . ($initpct > 0 ? $initpct : 100) . '%;',
    ]
);
echo html_writer::end_div();
echo html_writer::end_div();

$cancelform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$cancelform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'cancelbg']);
$cancelform .= html_writer::tag(
    'button',
    get_string('reindexareas_btn_cancelbg', 'search_typesense'),
    ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']
);
echo html_writer::tag('form', $cancelform, ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']);
echo html_writer::end_div();

// Background AJAX poller: updates the progress bar and table cells in-place without reloading the page.
$ajaxurl = (new moodle_url('/search/engine/typesense/reindex_areas.php', ['action' => 'ajaxstatus']))->out(false);
$ajaxjs = "
(function() {
    var ajaxUrl = " . json_encode($ajaxurl) . ";
    var pollStatus = function() {
        fetch(ajaxUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(data) {
                if (!data) { return; }
                var banner = document.getElementById('ts-reindex-bg-banner');
                var titleEl = document.getElementById('ts-reindex-bg-title');
                var progEl = document.getElementById('ts-reindex-bg-progress');
                var barWrap = document.getElementById('ts-reindex-bg-bar-wrap');
                var barEl = document.getElementById('ts-reindex-bg-bar');
                if (banner) {
                    if (data.active) {
                        banner.classList.remove('d-none');
                        banner.classList.add('d-flex');
                    } else {
                        banner.classList.remove('d-flex');
                        banner.classList.add('d-none');
                    }
                }
                if (titleEl && data.title) { titleEl.textContent = data.title; }
                if (progEl) { progEl.innerHTML = data.progresstext || ''; }
                if (barWrap && barEl) {
                    if (data.active && data.pct !== null && data.pct > 0) {
                        barWrap.classList.remove('d-none');
                        barEl.style.width = data.pct + '%';
                    } else if (data.active && data.progresstext) {
                        barWrap.classList.remove('d-none');
                        barEl.style.width = '100%';
                    } else {
                        barWrap.classList.add('d-none');
                    }
                }
                if (data.rows) {
                    Object.keys(data.rows).forEach(function(areaid) {
                        var row = data.rows[areaid];
                        var cEl = document.querySelector('[data-ts-count=\"' + areaid + '\"]');
                        var nEl = document.querySelector('[data-ts-newest=\"' + areaid + '\"]');
                        var lEl = document.querySelector('[data-ts-lastrun=\"' + areaid + '\"]');
                        if (cEl && row.counthtml !== undefined) { cEl.innerHTML = row.counthtml; }
                        if (nEl && row.newestdoc !== undefined) { nEl.textContent = row.newestdoc; }
                        if (lEl && row.lastrunhtml !== undefined) { lEl.innerHTML = row.lastrunhtml; }
                    });
                }
                setTimeout(pollStatus, data.active ? 2500 : 8000);
            })
            .catch(function() {
                setTimeout(pollStatus, 6000);
            });
    };
    setTimeout(pollStatus, " . ($isbgactive ? '2500' : '6000') . ");
})();
";
$PAGE->requires->js_init_code($ajaxjs);

// Check for broken/unbuilt context rows that cause Moodle core's get_in_or_equal() error.
$missingcontexts = (int)$DB->count_records_select('context', 'path IS NULL OR depth = 0');
if ($missingcontexts > 0) {
    echo $OUTPUT->notification(
        get_string('reindexareas_missing_contexts_warn', 'search_typesense', $missingcontexts),
        'notifywarning'
    );
}

// Top action toolbar: Run All in Background + Queue All + Repair Context Paths + Core Search Areas.
echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3');

$bgallform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$bgallform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'runbgall']);
$bgallform .= html_writer::tag(
    'button',
    get_string('reindexareas_btn_runbgall', 'search_typesense'),
    ['type' => 'submit', 'class' => 'btn btn-primary btn-sm mr-2 me-2 mb-1']
);
echo html_writer::tag('form', $bgallform, ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']);

$queueallform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$queueallform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'queueall']);
$queueallform .= html_writer::tag(
    'button',
    get_string('reindexareas_btn_queueall', 'search_typesense'),
    ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm mr-2 me-2 mb-1']
);
echo html_writer::tag('form', $queueallform, ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']);

$fixctxform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$fixctxform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'fixcontexts']);
$fixctxbtnclass = $missingcontexts > 0
    ? 'btn btn-warning btn-sm mr-2 me-2 mb-1'
    : 'btn btn-outline-secondary btn-sm mr-2 me-2 mb-1';
$fixctxform .= html_writer::tag(
    'button',
    get_string('reindexareas_btn_fixcontexts', 'search_typesense'),
    ['type' => 'submit', 'class' => $fixctxbtnclass]
);
echo html_writer::tag('form', $fixctxform, ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']);

$coreareasurl = new moodle_url('/admin/searchareas.php');
echo html_writer::link(
    $coreareasurl,
    get_string('searchareas', 'admin'),
    ['class' => 'btn btn-outline-secondary btn-sm mb-1']
);
echo html_writer::end_div();

// Fetch live document counts per area from Typesense.
$areacounts = $tsengine->get_area_document_counts();
$searchareas = \core_search\manager::get_search_areas_list(true);
core_collator::asort_objects_by_method($searchareas, 'get_visible_name');

$searchmanager = null;
try {
    $searchmanager = \core_search\manager::instance();
} catch (\Throwable $e) {
    debugging('Search manager init skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
}
$areasconfig = $searchmanager ? $searchmanager->get_areas_config($searchareas) : [];

$table = new html_table();
$table->attributes['class'] = 'generaltable table table-striped align-middle';
$table->head = [
    get_string('searcharea', 'search'),
    get_string('reindexareas_col_areaid', 'search_typesense'),
    get_string('reindexareas_col_tsdocs', 'search_typesense'),
    get_string('newestdocindexed', 'admin'),
    get_string('searchlastrun', 'admin'),
    get_string('actions'),
];

foreach ($searchareas as $id => $area) {
    $tsdoccount = $areacounts[$id] ?? 0;
    $countbadgeclass = $tsdoccount > 0
        ? 'badge badge-success bg-success text-white'
        : 'badge badge-secondary bg-secondary text-white';
    $countbadge = html_writer::tag('span', number_format($tsdoccount), ['class' => $countbadgeclass]);

    $cfg = $areasconfig[$id] ?? null;
    $newestdoc = '-';
    if ($cfg && !empty($cfg->lastindexrun)) {
        $newestdoc = is_numeric($cfg->lastindexrun) ? userdate((int)$cfg->lastindexrun) : (string)$cfg->lastindexrun;
    }
    $lastrun = '-';
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

    $actionshtml = '';
    if ($area->supports_get_document_recordset()) {
        // 1. Primary action: Run in Background (Ad-hoc Task) — avoids mod_proxy_fcgi timeouts.
        $bgform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $bgform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'runbg']);
        $bgform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'areaid', 'value' => $id]);
        $bgform .= html_writer::tag(
            'button',
            get_string('reindexareas_btn_runbg', 'search_typesense'),
            ['type' => 'submit', 'class' => 'btn btn-primary btn-sm mr-1 me-1 mb-1']
        );
        $actionshtml .= html_writer::tag(
            'form',
            $bgform,
            ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']
        );

        // 2. Secondary action: Run Inline (for small areas).
        $runform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $runform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'runnow']);
        $runform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'areaid', 'value' => $id]);
        $runform .= html_writer::tag(
            'button',
            get_string('reindexareas_btn_runnow', 'search_typesense'),
            ['type' => 'submit', 'class' => 'btn btn-outline-primary btn-sm mr-1 me-1 mb-1']
        );
        $actionshtml .= html_writer::tag(
            'form',
            $runform,
            ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']
        );

        // 3. Reset / Queue for Scheduled Cron.
        $qform = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $qform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'queuearea']);
        $qform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'areaid', 'value' => $id]);
        $qform .= html_writer::tag(
            'button',
            get_string('reindexareas_btn_queue', 'search_typesense'),
            ['type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm mb-1']
        );
        $actionshtml .= html_writer::tag(
            'form',
            $qform,
            ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'd-inline']
        );
    }

    $table->data[] = [
        html_writer::tag('strong', s($area->get_visible_name())),
        html_writer::tag('code', s($id), ['class' => 'small']),
        html_writer::tag('span', $countbadge, ['data-ts-count' => s($id)]),
        html_writer::tag('span', s($newestdoc), ['data-ts-newest' => s($id)]),
        html_writer::tag('span', $lastrun, ['data-ts-lastrun' => s($id)]),
        $actionshtml,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
