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
 * Typesense search page with access checks limited to candidate results.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use core_search\manager;
use search_typesense\candidate_engine;
use search_typesense\course_scope;
use search_typesense\hook_callbacks;

$page = max(0, optional_param('page', 0, PARAM_INT));
$q = optional_param('q', '', PARAM_NOTAGS);
$contextid = optional_param('context', 0, PARAM_INT);
$scope = optional_param('scope', course_scope::default_scope(), PARAM_ALPHA);
$validscopes = course_scope::valid_scopes();
if (!in_array($scope, $validscopes, true)) {
    $scope = course_scope::default_scope();
}

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('globalsearch', 'search'));
$PAGE->set_heading(get_string('globalsearch', 'search'));
$PAGE->set_url(new moodle_url('/search/engine/typesense/query.php', ['scope' => $scope]));

if (!empty($CFG->forcelogin)) {
    require_login();
}
require_capability('moodle/search:query', $systemcontext);

if (!hook_callbacks::is_enabled()) {
    $target = (new moodle_url('/search/index.php'))->out(false);
    $fallbackparams = $_GET;
    foreach (['q', 'title', 'areaids', 'courseids', 'contextids', 'userids', 'groupids',
              'timestart', 'timeend', 'mycoursesonly', 'order', 'context', 'searchwithin', 'cat', 'page'] as $paramname) {
        if (!isset($fallbackparams[$paramname]) && isset($_POST[$paramname])) {
            $fallbackparams[$paramname] = is_array($_POST[$paramname])
                ? implode(',', $_POST[$paramname])
                : $_POST[$paramname];
        }
    }
    unset($fallbackparams['scope']);
    $query = http_build_query($fallbackparams, '', '&', PHP_QUERY_RFC3986);
    redirect($target . ($query !== '' ? '?' . $query : ''));
}

$searchrenderer = $PAGE->get_renderer('search_typesense');
if (!manager::is_global_search_enabled()) {
    echo $OUTPUT->header();
    echo $searchrenderer->render_search_disabled();
    echo $OUTPUT->footer();
    exit;
}

// Keep core's engine readiness checks and its form. Neither performs a search.
$search = manager::instance(true, true);
$engine = $search->get_engine();
$context = $systemcontext;
$coursecontext = null;
$customdata = ['searchengine' => $engine->get_plugin_name()];
if ($contextid) {
    $context = context::instance_by_id($contextid);
    $coursecontext = $context->get_course_context(false);
    if ($coursecontext) {
        $customdata['searchwithin'] = [
            '' => get_string('everywhere', 'search'),
            'course' => $coursecontext->get_context_name(),
        ];
        $customdata['withincourseid'] = $coursecontext->instanceid;
        if ($context->contextlevel != CONTEXT_COURSE) {
            $customdata['searchwithin']['context'] = $context->get_context_name();
            if ($context->contextlevel == CONTEXT_MODULE) {
                $customdata['withincmid'] = $context->instanceid;
            }
        }
    }
}
$customdata['orderoptions'] = $engine->get_supported_orders($context);
$cat = null;
if (manager::is_search_area_categories_enabled()) {
    $cat = manager::get_search_area_category_by_name(optional_param('cat', '', PARAM_NOTAGS));
    $customdata['cat'] = $cat->get_name();
}

// Keep scope in the form action so POSTs retain it without modifying Moodle's form.
$formurl = new moodle_url('/search/engine/typesense/query.php', ['scope' => $scope]);
$mform = new \core_search\output\form\search($formurl, $customdata);
$data = $mform->get_data();

// Both core's comma-separated URL filters and array form filters are accepted.
$readids = static function(string $name, string $type): array {
    $raw = $_POST[$name] ?? $_GET[$name] ?? '';
    if (is_array($raw)) {
        return array_values(optional_param_array($name, [], $type));
    }
    $value = optional_param($name, '', PARAM_RAW);
    return $value === '' ? [] : array_values(clean_param_array(explode(',', $value), $type));
};
if (!$data && $q !== '' && !$mform->is_submitted()) {
    $data = (object)[
        'q' => $q,
        'title' => optional_param('title', '', PARAM_NOTAGS),
        'areaids' => $readids('areaids', PARAM_ALPHANUMEXT),
        'courseids' => $readids('courseids', PARAM_INT),
        'contextids' => $readids('contextids', PARAM_INT),
        'userids' => $readids('userids', PARAM_INT),
        'groupids' => $readids('groupids', PARAM_INT),
        'timestart' => optional_param('timestart', 0, PARAM_INT),
        'timeend' => optional_param('timeend', 0, PARAM_INT),
        'mycoursesonly' => optional_param('mycoursesonly', 0, PARAM_INT),
        'order' => optional_param('order', 'relevance', PARAM_ALPHANUMEXT),
        'context' => $contextid,
        'searchwithin' => optional_param('searchwithin', '', PARAM_ALPHA),
    ];
    $mform->set_data($data);
}

$urlparams = ['scope' => $scope, 'page' => $page, 'context' => $contextid];
$emptyareas = false;
if ($data) {
    if ($coursecontext && !empty($data->searchwithin)) {
        if ($data->searchwithin === 'course' || $data->searchwithin === 'context') {
            $data->courseids = [$coursecontext->instanceid];
        }
        if ($data->searchwithin === 'context') {
            $data->contextids = [$context->id];
        }
    }
    // Preserve the user's filters in links before adding the search-category restriction.
    foreach (['q', 'title', 'timestart', 'timeend', 'mycoursesonly', 'order', 'searchwithin'] as $name) {
        if (isset($data->{$name})) {
            $urlparams[$name] = $data->{$name};
        }
    }
    foreach (['areaids', 'courseids', 'contextids', 'groupids', 'userids'] as $name) {
        if (!empty($data->{$name})) {
            $urlparams[$name] = implode(',', $data->{$name});
        }
    }
    if ($scope === 'videotime') {
        $videotimeareas = course_scope::videotime_areaids();
        $data->areaids = empty($data->areaids)
            ? $videotimeareas
            : array_values(array_intersect($data->areaids, $videotimeareas));
        $emptyareas = empty($data->areaids);
    }
    if ($cat) {
        $categoryareas = array_keys($cat->get_areas());
        $data->areaids = empty($data->areaids) ? $categoryareas : array_values(array_intersect($data->areaids, $categoryareas));
        $emptyareas = empty($data->areaids);
        $data->cat = $cat->get_name();
    }
    $data->context = $context;
    $data->scope = $scope;
    $data->scopecourseids = course_scope::courseids($scope);
}
if ($cat) {
    $urlparams['cat'] = $cat->get_name();
}
$url = new moodle_url('/search/engine/typesense/query.php', $urlparams);
$PAGE->set_url($url);

echo $OUTPUT->header();
\core\session\manager::write_close();

$results = null;
$searchelapsed = 0.0;
if ($data) {
    $searchstart = microtime(true);
    $candidateengine = new candidate_engine(hook_callbacks::is_alternate_engine());
    $results = $emptyareas ? (object)[
        'results' => [], 'actualpage' => 0, 'totalcount' => 0, 'hasmore' => false, 'limited' => false,
    ] : $candidateengine->search_page($data, $page);
    $searchelapsed = microtime(true) - $searchstart;
    \search_typesense\pro_bridge::record_search_telemetry($data, $scope, $results, $searchelapsed);
}

if (!empty($CFG->searchbannerenable) && !empty($CFG->searchbanner)) {
    echo $OUTPUT->notification(format_text($CFG->searchbanner, FORMAT_HTML), 'notifywarning');
}
if (count($validscopes) > 1) {
    $links = [];
    foreach ($validscopes as $linkscope) {
        $selected = $scope === $linkscope;
        $scopeurl = new moodle_url($url, ['scope' => $linkscope, 'page' => 0]);
        $pillclasses = 'search-typesense-nav-pill btn ' . ($selected
            ? 'search-typesense-nav-pill--selected btn-primary'
            : 'btn-outline-secondary');
        $attributes = ['class' => $pillclasses];
        if ($selected) {
            $attributes['aria-current'] = 'page';
        }
        $indicator = $selected
            ? html_writer::tag('span', '', ['class' => 'search-typesense-nav-pill__indicator', 'aria-hidden' => 'true'])
            : '';
        $label = html_writer::tag(
            'span',
            get_string('scope' . $linkscope, 'search_typesense'),
            ['class' => 'search-typesense-nav-pill__label']
        );
        $links[] = html_writer::link($scopeurl, $indicator . $label, $attributes);
    }
    echo html_writer::tag(
        'nav',
        implode(' ', $links),
        [
            'class' => 'search-typesense-scopes mb-3',
            'aria-label' => get_string('currentcategories', 'search_typesense'),
        ]
    );
}

$mform->display();
if ($results) {
    echo \search_typesense\pro_bridge::render_search_page_banner($data, $scope, $results, $url);
    if ($results->limited) {
        echo $OUTPUT->notification(get_string('searchlimited', 'search_typesense'), 'notifyinfo');
    } else if (!$results->results && !$results->hasmore) {
        echo $OUTPUT->notification(get_string('noresults', 'search'), 'notifymessage');
    }
    // The count is supplied by the service from checked results, never Typesense's raw hit count.
    echo $searchrenderer->render_results($results->results, $results->actualpage, $results->totalcount, $url, $cat);
    manager::trigger_search_results_viewed([
        'q' => $data->q,
        'page' => $results->actualpage,
        'title' => $data->title ?? '',
        'areaids' => $data->areaids ?? [],
        'courseids' => $data->courseids ?? [],
        'timestart' => $data->timestart ?? 0,
        'timeend' => $data->timeend ?? 0,
    ]);
}

echo $OUTPUT->footer();
