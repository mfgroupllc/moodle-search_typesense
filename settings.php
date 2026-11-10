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
 * Typesense search engine settings and administration sub-pages.
 *
 * Splits configuration into focused sub-pages under Site administration -> Plugins -> Search
 * with a shared top navigation bar (\search_typesense\admin_nav).
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // 1. Sub-page 1 (Main): Server Connection & Course Scopes.
    $connnav = during_initial_install() ? '' : \search_typesense\admin_nav::render('connection');
    $settings->add(new admin_setting_heading(
        'search_typesense/heading_connection',
        new lang_string('settings_connection_heading', 'search_typesense'),
        $connnav . get_string('settings_connection_heading_desc', 'search_typesense')
    ));

    $settings->add(new admin_setting_configtext(
        'search_typesense/host',
        new lang_string('host', 'search_typesense'),
        new lang_string('host_desc', 'search_typesense'),
        'localhost',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'search_typesense/port',
        new lang_string('port', 'search_typesense'),
        new lang_string('port_desc', 'search_typesense'),
        '8108',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'search_typesense/protocol',
        new lang_string('protocol', 'search_typesense'),
        new lang_string('protocol_desc', 'search_typesense'),
        'https',
        ['http' => 'http', 'https' => 'https']
    ));

    $settings->add(new admin_setting_configcheckbox(
        'search_typesense/verifytls',
        new lang_string('verifytls', 'search_typesense'),
        new lang_string('verifytls_desc', 'search_typesense'),
        '0'
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'search_typesense/apikey',
        new lang_string('apikey', 'search_typesense'),
        new lang_string('apikey_desc', 'search_typesense'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'search_typesense/collection',
        new lang_string('collection', 'search_typesense'),
        new lang_string('collection_desc', 'search_typesense'),
        'moodle',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'search_typesense/locale',
        new lang_string('locale', 'search_typesense'),
        new lang_string('locale_desc', 'search_typesense'),
        '',
        PARAM_ALPHANUMEXT
    ));

    // 2. Search Performance, Course Scopes & Fast Re-index Launcher.
    $reindexurl = new moodle_url('/search/engine/typesense/reindex_areas.php');
    $inferurl = new moodle_url('/search/engine/typesense/infer_categories.php');
    $contentsettingsurl = new moodle_url('/admin/settings.php', ['section' => 'searchtypesense_content']);
    $scopetoolbtns = html_writer::div(
        html_writer::link(
            $reindexurl,
            get_string('reindexareas_button', 'search_typesense'),
            ['class' => 'btn btn-outline-primary btn-sm mt-2 mr-2 me-2']
        ) . html_writer::link(
            $inferurl,
            get_string('infercategories_button', 'search_typesense'),
            ['class' => 'btn btn-outline-secondary btn-sm mt-2 mr-2 me-2']
        ) . html_writer::link(
            $contentsettingsurl,
            get_string('nav_content', 'search_typesense'),
            ['class' => 'btn btn-outline-secondary btn-sm mt-2']
        ),
        'mb-1'
    );

    $settings->add(new admin_setting_heading(
        'search_typesense/heading_scopes',
        new lang_string('settings_scopes_heading', 'search_typesense'),
        get_string('settings_scopes_heading_desc', 'search_typesense') . $scopetoolbtns
    ));

    $settings->add(new admin_setting_configcheckbox(
        'search_typesense/candidatefirst',
        new lang_string('candidatefirst', 'search_typesense'),
        new lang_string('candidatefirst_desc', 'search_typesense'),
        0
    ));

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
        new lang_string('currentcategories', 'search_typesense'),
        get_string('currentcategories_desc', 'search_typesense') . $inferbutton,
        [],
        during_initial_install() ? [] : \core_course_category::make_categories_list()
    ));

    // Allow Typesense Pro to render a compact status card & link to its dedicated settings page.
    \search_typesense\pro_bridge::extend_settings($settings);
}

// Register focused sub-pages under Site administration -> Plugins -> Search.
if (isset($ADMIN) && $ADMIN->locate('searchplugins')) {
    if (!$ADMIN->locate('searchtypesense_content')) {
        $contentsettings = new admin_settingpage(
            'searchtypesense_content',
            new lang_string('settings_content_page', 'search_typesense'),
            'moodle/site:config'
        );

        if ($ADMIN->fulltree) {
            $contentnav = during_initial_install() ? '' : \search_typesense\admin_nav::render('content');
            $contentsettings->add(new admin_setting_heading(
                'search_typesense/heading_display',
                new lang_string('settings_display_heading', 'search_typesense'),
                $contentnav . get_string('settings_display_heading_desc', 'search_typesense')
            ));

            $contentsettings->add(new admin_setting_configcheckbox(
                'search_typesense/restrictusersearch',
                new lang_string('restrictusersearch', 'search_typesense'),
                new lang_string('restrictusersearch_desc', 'search_typesense'),
                1
            ));

            $contentsettings->add(new admin_setting_configmultiselect(
                'search_typesense/userprofilefields',
                new lang_string('userprofilefields', 'search_typesense'),
                new lang_string('userprofilefields_desc', 'search_typesense'),
                [\search_typesense\user_profile_fields::FOLLOW_SHOWUSERIDENTITY],
                \search_typesense\user_profile_fields::get_setting_choices()
            ));

            // Attached Document & File Content Indexing.
            $contentsettings->add(new admin_setting_heading(
                'search_typesense/heading_fileindexing',
                new lang_string('settings_fileindexing_heading', 'search_typesense'),
                new lang_string('settings_fileindexing_heading_desc', 'search_typesense')
            ));

            $contentsettings->add(new admin_setting_configcheckbox(
                'search_typesense/fileindexing',
                new lang_string('fileindexing', 'search_typesense'),
                new lang_string('fileindexing_desc', 'search_typesense'),
                1
            ));

            $contentsettings->add(new admin_setting_configexecutable(
                'search_typesense/pathtopdftotext',
                new lang_string('pathtopdftotext', 'search_typesense'),
                new lang_string('pathtopdftotext_desc', 'search_typesense'),
                '/usr/bin/pdftotext'
            ));

            // Detected Integrations (Video Time).
            if (!during_initial_install() && \search_typesense\course_scope::videotime_installed()) {
                $vtareas = \search_typesense\course_scope::videotime_areaids();
                $vtstatusbadge = html_writer::tag(
                    'span',
                    get_string('videotime_detected_badge', 'search_typesense', count($vtareas)),
                    ['class' => 'badge bg-success text-white badge-success me-2 mr-2']
                );
                $vtheadingdesc = html_writer::div(
                    $vtstatusbadge . get_string('settings_videotime_heading_desc', 'search_typesense'),
                    'mb-2'
                );

                $contentsettings->add(new admin_setting_heading(
                    'search_typesense/heading_videotime',
                    new lang_string('settings_videotime_heading', 'search_typesense'),
                    $vtheadingdesc
                ));

                $contentsettings->add(new admin_setting_configcheckbox(
                    'search_typesense/enablevideotimetab',
                    new lang_string('enablevideotimetab', 'search_typesense'),
                    new lang_string('enablevideotimetab_desc', 'search_typesense'),
                    1
                ));

                $contentsettings->add(new admin_setting_configtext(
                    'search_typesense/videotimetablabel',
                    new lang_string('videotimetablabel', 'search_typesense'),
                    new lang_string('videotimetablabel_desc', 'search_typesense'),
                    get_string('scopevideotime', 'search_typesense'),
                    PARAM_TEXT
                ));
            }
        }

        $ADMIN->add('searchplugins', $contentsettings);
    }

    if (!$ADMIN->locate('searchtypesense_reindex')) {
        $ADMIN->add('searchplugins', new admin_externalpage(
            'searchtypesense_reindex',
            new lang_string('reindexareas_nav', 'search_typesense'),
            new moodle_url('/search/engine/typesense/reindex_areas.php'),
            'moodle/site:config'
        ));
    }

    if (!$ADMIN->locate('searchtypesense_infer')) {
        $ADMIN->add('searchplugins', new admin_externalpage(
            'searchtypesense_infer',
            new lang_string('infercategories_nav', 'search_typesense'),
            new moodle_url('/search/engine/typesense/infer_categories.php'),
            'moodle/site:config'
        ));
    }
}
