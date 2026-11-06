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
