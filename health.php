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
 * Admin page displaying live read-only Typesense server health diagnostics.
 *
 * @package    search_typesense
 * @copyright  2026 Christian Murad <chrismurad@mfgrpllc.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();

$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/search/engine/typesense/health.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('health_page_title', 'search_typesense'));
$PAGE->set_heading(get_string('health_heading', 'search_typesense'));

// Release the session lock before making outbound HTTP requests to Typesense.
\core\session\manager::write_close();

/**
 * Render a colored usage progress bar for CPU, RAM, or Disk cards.
 *
 * @param float|null $pct Usage percentage (0-100).
 * @param string $level Severity level ('ok'|'warning'|'danger').
 * @return string HTML markup.
 */
function search_typesense_health_render_bar(?float $pct, string $level): string {
    if ($pct === null) {
        return '';
    }
    $clamped = max(0.0, min(100.0, $pct));
    $fillclasses = 'search-typesense-health-bar__fill';
    if ($level === 'warning' || $level === 'danger') {
        $fillclasses .= ' search-typesense-health-bar__fill--' . $level;
    }
    $fill = html_writer::div('', $fillclasses, ['style' => 'width: ' . $clamped . '%;']);
    return html_writer::div($fill, 'search-typesense-health-bar');
}

/**
 * Render a single health metric card.
 *
 * @param string $label Card header label.
 * @param string $valuehtml Main value HTML.
 * @param string $metahtml Secondary metadata HTML.
 * @param string $barhtml Optional progress bar HTML.
 * @return string HTML markup.
 */
function search_typesense_health_render_card(
    string $label,
    string $valuehtml,
    string $metahtml = '',
    string $barhtml = ''
): string {
    $inner = html_writer::div(s($label), 'search-typesense-health-card__label');
    $inner .= html_writer::div($valuehtml, 'search-typesense-health-card__value');
    if ($barhtml !== '') {
        $inner .= $barhtml;
    }
    if ($metahtml !== '') {
        $inner .= html_writer::div($metahtml, 'small text-muted mt-1');
    }
    return html_writer::div($inner, 'search-typesense-health-card');
}

$engine = new \search_typesense\engine();
$configured = (bool)$engine->is_installed();
$snapshot = null;

if ($configured) {
    $client = $engine->create_client();
    $snapshot = \search_typesense\server_health::collect(
        $client,
        $engine->get_configured_collection_name(),
        $engine->get_endpoint_display()
    );
}

$refreshurl = new moodle_url('/search/engine/typesense/health.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'search_typesense']);

echo $OUTPUT->header();
echo \search_typesense\admin_nav::render('health');
echo $OUTPUT->heading(get_string('health_heading', 'search_typesense'), 3);

if (!$configured || $snapshot === null) {
    echo $OUTPUT->notification(get_string('health_not_configured', 'search_typesense'), 'warning');
    echo html_writer::div(
        html_writer::link($settingsurl, get_string('health_btn_settings', 'search_typesense'), ['class' => 'btn btn-primary']),
        'mt-3'
    );
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag(
    'p',
    get_string('health_intro', 'search_typesense', s($snapshot['endpoint'])),
    ['class' => 'text-muted']
);

$toolbar = html_writer::link(
    $refreshurl,
    get_string('health_btn_refresh', 'search_typesense'),
    ['class' => 'btn btn-primary mr-2 me-2']
);
$toolbar .= html_writer::link(
    $settingsurl,
    get_string('health_btn_settings', 'search_typesense'),
    ['class' => 'btn btn-secondary mr-3 me-3']
);
$toolbar .= html_writer::span(
    get_string('health_checked_at', 'search_typesense', userdate($snapshot['checked_at'])),
    'small text-muted'
);
echo html_writer::div($toolbar, 'mb-3');

$health = $snapshot['health'];
$debug = $snapshot['debug'];
$collection = $snapshot['collection'];
$metrics = $snapshot['metrics'];
$stats = $snapshot['stats'];

if (!empty($snapshot['unreachable'])) {
    echo $OUTPUT->notification(
        get_string('health_error_unreachable', 'search_typesense', s($snapshot['endpoint'])),
        'error'
    );
} else if (!empty($health['resource_error'])) {
    echo $OUTPUT->notification(
        get_string('health_error_resource', 'search_typesense', s($health['resource_error'])),
        'error'
    );
}

// Card 1: Node Health.
if (!empty($snapshot['unreachable'])) {
    $nodevalue = html_writer::span(
        get_string('health_node_unreachable', 'search_typesense'),
        'badge bg-danger text-white badge-danger'
    );
    $nodemeta = !empty($health['error']) ? s($health['error']) : '';
} else if (!empty($health['ok'])) {
    $nodevalue = html_writer::span(
        get_string('health_node_healthy', 'search_typesense'),
        'badge bg-success text-white badge-success'
    );
    $nodemeta = get_string('health_latency_ms', 'search_typesense', (int)$health['latency_ms']);
} else {
    $nodevalue = html_writer::span(
        get_string('health_node_degraded', 'search_typesense', (int)$health['http_code']),
        'badge bg-danger text-white badge-danger'
    );
    $nodemeta = !empty($health['resource_error']) ? s($health['resource_error']) : s((string)$health['error']);
}
$cards = search_typesense_health_render_card(
    get_string('health_node_status', 'search_typesense'),
    $nodevalue,
    $nodemeta
);

// Card 2: Typesense Server (/debug).
if ($debug['status'] === 'ok') {
    $versionstr = $debug['version'] !== ''
        ? get_string('health_server_version', 'search_typesense', s($debug['version']))
        : get_string('health_role_' . $debug['role'], 'search_typesense');
    $rolelabel = get_string('health_role_' . $debug['role'], 'search_typesense');
    $servermeta = get_string('health_server_role', 'search_typesense', s($rolelabel));
    $servervalue = s($versionstr);
} else if ($debug['status'] === 'restricted') {
    $servervalue = '—';
    $servermeta = get_string('health_debug_restricted', 'search_typesense');
} else {
    $servervalue = '—';
    $servermeta = !empty($debug['error']) ? s($debug['error']) : '';
}
$cards .= search_typesense_health_render_card(
    get_string('health_server_heading', 'search_typesense'),
    $servervalue,
    $servermeta
);

// Card 3: Active Collection.
if ($collection['status'] === 'ok') {
    $colvalue = s($collection['name']);
    $colcounts = (object)[
        'docs' => number_format((int)$collection['num_documents']),
        'fields' => (int)$collection['fields_count'],
    ];
    $colmeta = get_string('health_collection_docs', 'search_typesense', $colcounts);
    if (!empty($collection['created_at'])) {
        $colmeta .= '<br>' . get_string(
            'health_collection_created',
            'search_typesense',
            userdate((int)$collection['created_at'])
        );
    }
} else if ($collection['status'] === 'notfound') {
    $colvalue = s($collection['name']);
    $colmeta = get_string('health_collection_missing', 'search_typesense', s($collection['name']));
} else {
    $colvalue = s($collection['name']);
    $colmeta = !empty($collection['error'])
        ? get_string('health_col_error', 'search_typesense', s($collection['error']))
        : '';
}
$cards .= search_typesense_health_render_card(
    get_string('health_active_collection', 'search_typesense'),
    $colvalue,
    $colmeta
);

// Cards 4-6: Host CPU, Host Memory, Host Disk (+ Process Memory).
if ($metrics['status'] === 'ok') {
    $cpupct = $metrics['cpu_pct'];
    $cards .= search_typesense_health_render_card(
        get_string('health_cpu_heading', 'search_typesense'),
        $cpupct !== null ? s(number_format($cpupct, 1) . '%') : '—',
        get_string('health_cpu_note', 'search_typesense'),
        search_typesense_health_render_bar($cpupct, (string)$metrics['cpu_level'])
    );

    $mempct = $metrics['mem_pct'];
    $memargs = (object)[
        'used' => \search_typesense\server_health::format_bytes($metrics['mem_used_bytes']),
        'total' => \search_typesense\server_health::format_bytes($metrics['mem_total_bytes']),
    ];
    $cards .= search_typesense_health_render_card(
        get_string('health_mem_heading', 'search_typesense'),
        $mempct !== null ? s(number_format($mempct, 1) . '%') : '—',
        get_string('health_mem_usage', 'search_typesense', $memargs),
        search_typesense_health_render_bar($mempct, (string)$metrics['mem_level'])
    );

    $diskpct = $metrics['disk_pct'];
    $diskargs = (object)[
        'used' => \search_typesense\server_health::format_bytes($metrics['disk_used_bytes']),
        'total' => \search_typesense\server_health::format_bytes($metrics['disk_total_bytes']),
    ];
    $cards .= search_typesense_health_render_card(
        get_string('health_disk_heading', 'search_typesense'),
        $diskpct !== null ? s(number_format($diskpct, 1) . '%') : '—',
        get_string('health_disk_usage', 'search_typesense', $diskargs),
        search_typesense_health_render_bar($diskpct, (string)$metrics['disk_level'])
    );

    echo html_writer::div($cards, 'search-typesense-health-grid');

    $procargs = (object)[
        'allocated' => \search_typesense\server_health::format_bytes($metrics['ts_allocated_bytes']),
        'active' => \search_typesense\server_health::format_bytes($metrics['ts_active_bytes']),
        'resident' => \search_typesense\server_health::format_bytes($metrics['ts_resident_bytes']),
        'fragmentation' => $metrics['ts_fragmentation_ratio'] !== null
            ? number_format((float)$metrics['ts_fragmentation_ratio'], 2)
            : '—',
    ];
    echo html_writer::tag('h4', get_string('health_proc_mem_heading', 'search_typesense'), ['class' => 'mt-3']);
    echo html_writer::tag('p', get_string('health_proc_mem_stats', 'search_typesense', $procargs), ['class' => 'text-muted']);
} else {
    echo html_writer::div($cards, 'search-typesense-health-grid');
    if ($metrics['status'] === 'restricted') {
        echo $OUTPUT->notification(get_string('health_metrics_restricted', 'search_typesense'), 'info');
    } else if (!empty($metrics['error']) && empty($snapshot['unreachable'])) {
        echo $OUTPUT->notification(s($metrics['error']), 'warning');
    }
}

// Live API Activity (/stats.json).
echo html_writer::tag('h4', get_string('health_activity_heading', 'search_typesense'), ['class' => 'mt-4']);
if ($stats['status'] === 'ok') {
    $statssummary = [
        get_string('health_total_rps', 'search_typesense', number_format((float)$stats['total_rps'], 2)),
    ];
    if ($stats['overloaded_rps'] !== null) {
        $statssummary[] = get_string(
            'health_overloaded_rps',
            'search_typesense',
            number_format((float)$stats['overloaded_rps'], 2)
        );
    }
    if ($stats['pending_write_batches'] !== null) {
        $statssummary[] = get_string('health_pending_writes', 'search_typesense', (int)$stats['pending_write_batches']);
    }
    echo html_writer::tag('p', s(implode(' · ', $statssummary)), ['class' => 'text-muted']);

    if (empty($stats['endpoints'])) {
        echo html_writer::tag('p', get_string('health_activity_none', 'search_typesense'), ['class' => 'text-muted']);
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table table-striped table-sm';
        $table->head = [
            get_string('health_api_endpoint', 'search_typesense'),
            get_string('health_api_rps', 'search_typesense'),
            get_string('health_api_latency', 'search_typesense'),
        ];
        foreach ($stats['endpoints'] as $ep) {
            $table->data[] = [
                html_writer::tag('code', s($ep['endpoint'])),
                number_format((float)$ep['rps'], 2),
                number_format((float)$ep['latency_ms'], 1),
            ];
        }
        echo html_writer::table($table);
    }
} else if ($stats['status'] === 'restricted') {
    echo $OUTPUT->notification(get_string('health_stats_restricted', 'search_typesense'), 'info');
} else if (!empty($stats['error']) && empty($snapshot['unreachable'])) {
    echo $OUTPUT->notification(s($stats['error']), 'warning');
}

echo $OUTPUT->footer();
