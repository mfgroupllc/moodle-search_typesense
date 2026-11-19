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
 * Open a search shortcut, letting Typesense Pro note the click first.
 *
 * Only links from the current user's own shortcut list are opened, so this page cannot send anyone elsewhere.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Global search checks $CFG->forcelogin conditionally, matching core /search/index.php.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../../config.php');

$q = optional_param('q', '', PARAM_NOTAGS);
$linkid = required_param('link', PARAM_ALPHANUM);

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/search/engine/typesense/go.php', ['q' => $q, 'link' => $linkid]));
if (!empty($CFG->forcelogin)) {
    require_login();
}
require_capability('moodle/search:query', $systemcontext);

$link = \search_typesense\shortcuts::find_link($linkid);
if (!$link) {
    throw new moodle_exception('shortcutnotfound', 'search_typesense');
}
\search_typesense\pro_bridge::record_shortcut_click($q, $link['label'], $link['url']);
redirect($link['url']);
