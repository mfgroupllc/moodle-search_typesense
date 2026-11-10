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

if (!empty($queuedtasks) || $activearea !== '') {
    echo html_writer::start_div('alert alert-info d-flex justify-content-between align-items-center flex-wrap mb-3');
    echo html_writer::start_div('mr-3 me-3');
    echo html_writer::tag('strong', get_string('reindexareas_bg_active_title', 'search_typesense', count($queuedtasks)));
    if (is_array($activeprogress) && !empty($activeprogress['areaid'])) {
        $pinfo = (object)[
            'areaid' => s((string)$activeprogress['areaid']),
            'docs' => number_format((int)($activeprogress['docs'] ?? 0)),
            'records' => number_format((int)($activeprogress['records'] ?? 0)),
            'batches' => number_format((int)($activeprogress['batches'] ?? 0)),
            'rate' => number_format((int)($activeprogress['rate'] ?? 0)),
        ];
        echo html_writer::div(
            get_string('reindexareas_bg_active_progress', 'search_typesense', $pinfo),
            'small mt-1'
        );
    }
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

    // Auto-refresh every 5 seconds while a background ad-hoc task is running/queued.
    $PAGE->requires->js_init_code('setTimeout(function(){ window.location.reload(); }, 5000);');
}

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
    $newestdoc = ($cfg && !empty($cfg->lastindexrun)) ? userdate((int)$cfg->lastindexrun) : '-';
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
        $countbadge,
        $newestdoc,
        $lastrun,
        $actionshtml,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
