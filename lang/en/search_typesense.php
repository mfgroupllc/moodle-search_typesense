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
$string['scopecurrent'] = 'Current courses';
$string['scopehistorical'] = 'Historical / other courses';
$string['scopeall'] = 'Search everything';
$string['searchlimited'] = 'The search reached its processing limit. More matches may exist. Try a more specific query or course filter.';
$string['privacy:metadata:typesense'] = 'The Typesense search engine sends indexed course and user content to the configured Typesense server to fulfill search queries.';
$string['privacy:metadata:typesense:userid'] = 'The ID of the user who authored the indexed document.';
$string['privacy:metadata:typesense:owneruserid'] = 'The ID of the user who owns a private search document.';
$string['privacy:metadata:typesense:content'] = 'The searchable title and body text of the indexed Moodle item.';
