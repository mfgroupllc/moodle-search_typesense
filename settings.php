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
 * Typesense search engine settings.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'search_typesense/host',
        get_string('host', 'search_typesense'),
        get_string('host_desc', 'search_typesense'),
        'localhost',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'search_typesense/port',
        get_string('port', 'search_typesense'),
        get_string('port_desc', 'search_typesense'),
        '8108',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'search_typesense/protocol',
        get_string('protocol', 'search_typesense'),
        get_string('protocol_desc', 'search_typesense'),
        'https',
        ['http' => 'http', 'https' => 'https']
    ));

    $settings->add(new admin_setting_configselect(
        'search_typesense/verifytls',
        get_string('verifytls', 'search_typesense'),
        get_string('verifytls_desc', 'search_typesense'),
        '0',
        ['1' => get_string('yes'), '0' => get_string('no')]
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'search_typesense/apikey',
        get_string('apikey', 'search_typesense'),
        get_string('apikey_desc', 'search_typesense'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'search_typesense/collection',
        get_string('collection', 'search_typesense'),
        get_string('collection_desc', 'search_typesense'),
        'moodle',
        PARAM_ALPHANUMEXT
    ));

    // The locale used for text fields. Controls accent/diacritic handling and
    // typo-tolerance language. Empty = Typesense default (English-ish folding).
    $settings->add(new admin_setting_configtext(
        'search_typesense/locale',
        get_string('locale', 'search_typesense'),
        get_string('locale_desc', 'search_typesense'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'search_typesense/candidatefirst',
        get_string('candidatefirst', 'search_typesense'),
        get_string('candidatefirst_desc', 'search_typesense'),
        0
    ));

    $inferurl = new moodle_url('/search/engine/typesense/infer_categories.php');
    $inferbutton = html_writer::div(
        html_writer::link(
            $inferurl,
            get_string('infercategories_button', 'search_typesense'),
            ['class' => 'btn btn-secondary btn-sm mt-2']
        ),
        'mb-1'
    );

    $settings->add(new admin_setting_configmultiselect(
        'search_typesense/currentcategories',
        get_string('currentcategories', 'search_typesense'),
        get_string('currentcategories_desc', 'search_typesense') . $inferbutton,
        [],
        during_initial_install() ? [] : \core_course_category::make_categories_list()
    ));

    \search_typesense\pro_bridge::extend_settings($settings);
}
