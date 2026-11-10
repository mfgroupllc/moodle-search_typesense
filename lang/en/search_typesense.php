<?php
// This file is part of Moodle - https://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for the Typesense search engine.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Typesense';
$string['searchinfo'] = 'Search queries';
$string['searchinfo_help'] = 'Enter keywords to search across titles, content, and descriptions. Typesense matches prefixes, tolerates minor typos, and folds accents automatically. Use the filters below to narrow results by search area, course, or modification date.';
$string['search_typesense:setting'] = 'Typesense search engine';
$string['settings_connection_heading'] = 'Server connection & index schema';
$string['settings_connection_heading_desc'] = 'Configure how Moodle connects to your Typesense instance and which collection and language locale are used for indexing.';
$string['settings_scopes_heading'] = 'Search performance & course scopes';
$string['settings_scopes_heading_desc'] = 'Configure candidate-first search evaluation and classify which course categories appear in the default "Current courses" scope.';
$string['settings_display_heading'] = 'Result display & user identity';
$string['settings_display_heading_desc'] = 'Control which role-checked user profile fields are indexed and displayed as badges on user search results.';
$string['settings_fileindexing_heading'] = 'Attached document & file content indexing';
$string['settings_fileindexing_heading_desc'] = 'Index text inside uploaded files (.pdf, .docx, .pptx, .xlsx, .odt, .txt, .csv, .vtt, .html) attached to course resources, forums, assignments, and books without requiring Java on your Moodle web servers.';
$string['fileindexing'] = 'Index attached document contents';
$string['fileindexing_desc'] = 'When enabled, Typesense extracts and indexes up to 60,000 characters of searchable text per document (30,000 characters per file) from supported files attached to Moodle activities and resources. Office documents (.docx, .pptx, .xlsx, .odt) and text/caption files (.txt, .csv, .vtt, .srt, .html) are parsed natively in PHP. PDFs use an automatic local extraction chain: pdftotext (poppler-utils), Moodle\'s configured Ghostscript path ($CFG->pathtogs), or the built-in PHP PDF stream parser.';
$string['pathtopdftotext'] = 'Path to pdftotext';
$string['pathtopdftotext_desc'] = 'Optional path to the pdftotext binary (part of poppler-utils, often installed alongside Moodle\'s pdftoppm binary). If not present, Typesense automatically falls back to Moodle\'s Ghostscript binary ($CFG->pathtogs) or native PHP PDF stream extraction.';
$string['settings_videotime_heading'] = 'Video Time integration';
$string['settings_videotime_heading_desc'] = 'The Video Time activity module (mod_videotime) is installed on this site. You can display a dedicated Video Time filter button and result tab on the global search page.';
$string['videotime_detected_badge'] = 'Video Time detected ({$a} search areas enabled)';
$string['enablevideotimetab'] = 'Enable Video Time search button and tab';
$string['enablevideotimetab_desc'] = 'When enabled, adds a dedicated Video Time button to the search scope bar and a Video Time tab to the search area category bar.';
$string['videotimetablabel'] = 'Video Time button / tab label';
$string['videotimetablabel_desc'] = 'Customise the text shown on the Video Time filter button and tab (for example, "Video Time videos" or "Video Library"). Leave blank to use the default language string.';
$string['host'] = 'Typesense host';
$string['host_desc'] = 'Hostname or IP address of the Typesense server (e.g. localhost or search.example.com).';
$string['port'] = 'Typesense port';
$string['port_desc'] = 'Port Typesense is listening on (default 8108).';
$string['protocol'] = 'Protocol';
$string['protocol_desc'] = 'Whether to connect over http or https. The CloudFormation template enables TLS on port 8108 by default, so use https.';
$string['verifytls'] = 'Verify TLS certificate';
$string['verifytls_desc'] = 'When on, the Moodle server validates Typesense\'s TLS certificate (requires a CA-trusted cert). Turn off only if Typesense uses a self-signed certificate (the default for this plugin\'s CloudFormation template).';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'The Typesense API key (admin or search-only key with write access for indexing).';
$string['collection'] = 'Collection name';
$string['collection_desc'] = 'Name of the Typesense collection used to store Moodle documents.';
$string['locale'] = 'Text locale';
$string['locale_desc'] = 'Locale applied to text fields, e.g. "es", "fr", "de". Controls accent/diacritic handling and typo tolerance. Leave empty for Typesense defaults.';
$string['notconfigured'] = 'The Typesense search engine is not fully configured. Set the host, port and API key in the search engine settings.';
$string['servernotready'] = 'The Typesense server could not be reached. Check the host, port, protocol and API key.';
$string['typesense:unsupportedmethod'] = 'Unsupported Typesense request method: {$a}.';
$string['typesense:servererror'] = 'Typesense server error: {$a}.';

$string['candidatefirst'] = 'Search matching courses first';
$string['candidatefirst_desc'] = 'Use the Typesense search page to find candidates before checking Moodle access for their courses. Applies to browser global search. Disable to use the standard Moodle search page.';
$string['currentcategories'] = 'Current course categories';
$string['currentcategories_desc'] = 'Selected categories and their active subcategories are searched first. Other courses remain available through the historical courses link. This is an administrative classification, not a change to course access. Leave empty to search everything by default.';
$string['infercategories'] = 'Detect active course categories';
$string['infercategories_button'] = 'Detect / Re-check active categories';
$string['infercategories_explain'] = 'Categories below were detected using upcoming or recently modified course shells (including zero-enrolment courses under development), active course start/end dates, recent student enrolments, and recent course access. Archive and test-only folders are excluded automatically.';
$string['infercategories_signals'] = 'Detection signals';
$string['infercategories_apply'] = 'Apply recommended categories ({$a})';
$string['infercategories_applied'] = 'Updated current course categories ({$a} selected).';
$string['infercategories_none'] = 'No active course categories were detected automatically.';
$string['inferreason_upcomingdev'] = 'Upcoming or in-development courses';
$string['inferreason_activedates'] = 'Active course start/end dates';
$string['inferreason_recentenrolments'] = 'Recent enrolments';
$string['inferreason_recentaccess'] = 'Recent course access';
$string['inferreason_rolledup'] = 'Includes active subcategories';
$string['infermetric_dev'] = '{$a} upcoming/in-dev';
$string['infermetric_enrols'] = '{$a} recent enrolments';
$string['infermetric_access'] = '{$a} recent accesses';
$string['inferstatus_recommended'] = 'Recommended';
$string['infercategories_nav'] = 'Typesense: Active Course Categories';
$string['nav_analytics'] = 'Search Analytics';
$string['nav_connection'] = 'Connection & Scopes';
$string['nav_content'] = 'Content, Users & Integrations';
$string['nav_infer'] = 'Active Categories';
$string['nav_pro'] = 'Typesense Pro (AI & InstantSearch)';
$string['nav_reindex'] = 'Fast Area Re-index (NDJSON)';
$string['reindexareas_bg_active_progress'] = 'Currently streaming <strong>{$a->areaid}</strong>: <strong>{$a->docs}</strong> docs indexed ({$a->records} records, {$a->batches} batches) at ~<strong>{$a->rate} docs/sec</strong>.';
$string['reindexareas_bg_active_title'] = 'Background NDJSON re-index active ({$a} ad-hoc task(s) in queue — page auto-refreshes every 5s)';
$string['reindexareas_bg_cancelled'] = 'Cancelled {$a} queued background re-index task(s).';
$string['reindexareas_bg_queued_all'] = 'Queued {$a} background NDJSON re-index ad-hoc tasks. They will run automatically via Moodle cron without web server timeouts.';
$string['reindexareas_bg_queued_single'] = 'Queued background NDJSON re-index ad-hoc task for "{$a}". It will run via Moodle cron without web server timeouts.';
$string['reindexareas_btn_cancelbg'] = 'Cancel queued tasks';
$string['reindexareas_btn_fixcontexts'] = 'Repair missing context paths';
$string['reindexareas_btn_queue'] = 'Reset for scheduled cron';
$string['reindexareas_btn_queueall'] = 'Reset all for scheduled cron';
$string['reindexareas_btn_runbg'] = 'Run in Background (NDJSON)';
$string['reindexareas_btn_runbgall'] = 'Run all areas in Background (Ad-hoc Tasks)';
$string['reindexareas_btn_runnow'] = 'Run Inline';
$string['reindexareas_col_areaid'] = 'Area ID';
$string['reindexareas_col_tsdocs'] = 'Docs in Typesense';
$string['reindexareas_completed'] = 'Fast NDJSON re-index completed for {$a->area}: indexed {$a->docs} documents from {$a->records} records across {$a->batches} batch(es) in {$a->elapsed}s ({$a->ignored} ignored).';
$string['reindexareas_contexts_fixed'] = 'Rebuilt all missing Moodle context paths successfully.';
$string['reindexareas_contexts_fixed_count'] = 'Context repair complete: resolved {$a->repaired} unbuilt/orphaned context row(s) ({$a->before} before → {$a->after} remaining).';
$string['reindexareas_desc'] = 'Re-index individual Moodle search areas directly into Typesense using Primary-Key chunked SQL joins, bulk NDJSON batches, and raised memory limits (MEMORY_HUGE). Use "Run in Background (NDJSON)" to execute via Moodle CLI cron ad-hoc tasks with zero web server / proxy timeouts.';
$string['reindexareas_missing_contexts_warn'] = 'Detected {$a} unbuilt/orphaned row(s) in {context} with NULL path or zero depth. This causes Moodle core\'s Gradual Re-index page to fail with a get_in_or_equal() error. Click "Repair missing context paths" below to fix and clean them up.';
$string['reindexareas_nav'] = 'Typesense: Fast Area Re-index';
$string['reindexareas_queued_all'] = 'Reset indexing timestamps for all {$a} enabled search areas. They will be bulk-indexed via NDJSON on the next CLI or scheduled task run.';
$string['reindexareas_queued_single'] = 'Reset indexing timestamp for "{$a}". It will be bulk-indexed via NDJSON on the next CLI or scheduled task run.';
$string['reindexareas_running'] = 'Running fast NDJSON bulk re-index for: {$a}';
$string['reindexareas_status_bgqueued'] = 'Queued (Ad-hoc Task)';
$string['reindexareas_status_pending'] = 'Queued for next bulk run';
$string['reindexareas_status_running'] = 'Running NDJSON...';
$string['reindexareas_title'] = 'Typesense Fast Area Re-index (NDJSON)';
$string['reindexareas_button'] = 'Fast Area Re-index (NDJSON)';
$string['task_fast_reindex_area'] = 'Typesense fast bulk NDJSON area re-index';
$string['settings_content_page'] = 'Typesense: Content, Users & Integrations';
$string['restrictusersearch'] = 'Restrict User search to staff and faculty';
$string['restrictusersearch_desc'] = 'When enabled, only staff, faculty, and managers holding user-identity capabilities (moodle/site:viewuseridentity, moodle/user:viewalldetails, or moodle/user:viewhiddendetails) can search the Users directory (core_user-user). Students will not see the Users search tab or user profile results.';
$string['userprofilefields'] = 'User profile fields in search results';
$string['userprofilefields_desc'] = 'Select standard or custom user profile fields (such as ID number, email, department, or custom fields) to index for user search and display beside matching user results. Every field is checked at display and query time against Moodle\'s role and capability rules (moodle/site:viewuseridentity, moodle/user:viewalldetails, hidden user fields, and custom profile field visibility settings) so users only see fields their role permits. Re-index the Users search area after changing this setting.';
$string['userprofilefields_followidentity'] = 'Follow Moodle "Show user identity" ($CFG->showuseridentity)';
$string['scopecurrent'] = 'Current courses';
$string['scopehistorical'] = 'Historical / other courses';
$string['scopeall'] = 'Search everything';
$string['scopevideotime'] = 'Video Time videos';
$string['searchlimited'] = 'The search reached its processing limit. More matches may exist. Try a more specific query or course filter.';
$string['privacy:metadata:typesense'] = 'The Typesense search engine sends indexed Moodle course, activity, attached document, and user profile content (as well as user search queries) to the configured external Typesense server to fulfill global search requests.';
$string['privacy:metadata:typesense:userid'] = 'The ID of the user who authored the indexed document.';
$string['privacy:metadata:typesense:owneruserid'] = 'The ID of the user who owns a private search document.';
$string['privacy:metadata:typesense:groupid'] = 'The group ID associated with a group-restricted search document.';
$string['privacy:metadata:typesense:courseid'] = 'The course ID to which the indexed search document belongs.';
$string['privacy:metadata:typesense:contextid'] = 'The Moodle context ID of the indexed search document.';
$string['privacy:metadata:typesense:modified'] = 'The Unix timestamp when the indexed search document was last modified.';
$string['privacy:metadata:typesense:title'] = 'The searchable title of the indexed Moodle course, activity, post, or user record.';
$string['privacy:metadata:typesense:content'] = 'The searchable body text of the indexed Moodle item. When attached document indexing (search_typesense/fileindexing) is enabled, this also includes up to 60,000 characters of plain text extracted locally from supported files (.pdf, .docx, .pptx, .xlsx, .odt, .txt, .csv, .vtt, .srt, .html) attached to the item.';
$string['privacy:metadata:typesense:description1'] = 'Primary secondary text or summary description of the indexed Moodle item.';
$string['privacy:metadata:typesense:description2'] = 'Additional metadata and normalized course code tokens for the indexed Moodle item.';
$string['privacy:metadata:typesense:docdata'] = 'Serialised Moodle core_search document attributes required to reconstruct search results prior to permission checks.';
$string['privacy:metadata:typesense:useridentity'] = 'Configured user profile fields (such as ID number, email, department, institution, or custom profile fields) indexed for role-checked user search.';
$string['privacy:metadata:typesense:query'] = 'The search query text submitted by a user when executing a global search.';
