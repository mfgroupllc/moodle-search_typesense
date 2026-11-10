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
 * Admin page to preview and apply inferred active course categories.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use search_typesense\course_scope;

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$apply = optional_param('apply', 0, PARAM_BOOL);
$pageurl = new moodle_url('/search/engine/typesense/infer_categories.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'searchtypesense']);

$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('infercategories', 'search_typesense'));
$PAGE->set_heading(get_string('infercategories', 'search_typesense'));

if ($apply && data_submitted()) {
    require_sesskey();
    $oldvalue = (string)get_config('search_typesense', 'currentcategories');
    $ids = course_scope::apply_inferred_categories(false);
    $newvalue = implode(',', $ids);
    if ($oldvalue !== $newvalue) {
        add_to_config_log('currentcategories', $oldvalue, $newvalue, 'search_typesense');
    }
    redirect(
        $settingsurl,
        get_string('infercategories_applied', 'search_typesense', count($ids)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$inferred = course_scope::infer_current_categories();
$currentroots = array_fill_keys(course_scope::roots(), true);

echo $OUTPUT->header();
echo \search_typesense\admin_nav::render('infer');
echo $OUTPUT->heading(get_string('infercategories', 'search_typesense'));
echo html_writer::tag('p', get_string('infercategories_explain', 'search_typesense'), ['class' => 'text-muted mb-3']);
echo \search_typesense\pro_bridge::render_infer_page_banner($inferred);

if (!$inferred) {
    echo $OUTPUT->notification(get_string('infercategories_none', 'search_typesense'), 'notifyinfo');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-striped';
    $table->head = [
        get_string('category'),
        get_string('courses'),
        get_string('infercategories_signals', 'search_typesense'),
        get_string('status'),
    ];

    foreach ($inferred as $id => $cat) {
        $reasonlabels = [];
        foreach ($cat->reasons as $reason) {
            $reasonlabels[] = get_string('inferreason_' . $reason, 'search_typesense');
        }
        $metrics = [];
        if ($cat->upcomingcourses > 0 || $cat->recentdevcourses > 0) {
            $metrics[] = get_string(
                'infermetric_dev',
                'search_typesense',
                max($cat->upcomingcourses, $cat->recentdevcourses)
            );
        }
        if ($cat->recentenrols > 0) {
            $metrics[] = get_string('infermetric_enrols', 'search_typesense', $cat->recentenrols);
        }
        if ($cat->recentaccesses > 0) {
            $metrics[] = get_string('infermetric_access', 'search_typesense', $cat->recentaccesses);
        }
        $signaltext = s(implode(', ', $reasonlabels));
        if ($metrics) {
            $signaltext .= ' ' . html_writer::tag('span', '(' . s(implode('; ', $metrics)) . ')', ['class' => 'text-muted small']);
        }
        if (!empty($cat->aibadge)) {
            $signaltext .= ' ' . html_writer::tag(
                'span',
                s((string)$cat->aibadge),
                ['class' => 'badge badge-info bg-info text-dark ms-1 ml-1']
            );
        }
        if (!empty($cat->airationale)) {
            $signaltext .= html_writer::div(s((string)$cat->airationale), 'text-muted small mt-1');
        }
        $courseinfo = (string)$cat->subtreecoursecount;
        if (!empty($cat->rolledup)) {
            $courseinfo .= ' ' . html_writer::tag(
                'span',
                '(' . s(get_string('inferreason_rolledup', 'search_typesense')) . ')',
                ['class' => 'badge badge-info bg-info text-white']
            );
        }
        $statusbadge = isset($currentroots[(int)$id])
            ? html_writer::tag('span', get_string('selected', 'form'), ['class' => 'badge badge-success bg-success text-white'])
            : html_writer::tag(
                'span',
                get_string('inferstatus_recommended', 'search_typesense'),
                ['class' => 'badge badge-primary bg-primary text-white']
            );

        $table->data[] = [
            s($cat->fullpath),
            $courseinfo,
            $signaltext,
            $statusbadge,
        ];
    }

    echo html_writer::table($table);
}

$formcontent = html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
$formcontent .= html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'apply',
    'value' => '1',
]);
$formcontent .= html_writer::tag(
    'button',
    get_string('infercategories_apply', 'search_typesense', count($inferred)),
    ['type' => 'submit', 'class' => 'btn btn-primary mr-2 me-2']
);
$formcontent .= html_writer::link(
    $settingsurl,
    get_string('cancel'),
    ['class' => 'btn btn-secondary']
);

echo html_writer::tag('form', $formcontent, [
    'method' => 'post',
    'action' => $pageurl->out(false),
    'class' => 'mt-3',
]);

echo $OUTPUT->footer();
