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
 * Shared sub-navigation bar for Typesense administration pages.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;


/**
 * Renders a consistent Bootstrap nav-tabs bar across all Typesense and Typesense Pro admin sub-pages.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_nav {
    /**
     * Render the Typesense administration navigation tabs.
     *
     * @param string $active Active tab key ('connection', 'content', 'reindex', 'infer', 'pro', 'analytics').
     * @return string HTML markup.
     */
    public static function render(string $active): string {
        $tabs = [
            'connection' => [
                'label' => get_string('nav_connection', 'search_typesense'),
                'url' => new \moodle_url('/admin/settings.php', ['section' => 'searchtypesense']),
            ],
            'content' => [
                'label' => get_string('nav_content', 'search_typesense'),
                'url' => new \moodle_url('/admin/settings.php', ['section' => 'searchtypesense_content']),
            ],
            'reindex' => [
                'label' => get_string('nav_reindex', 'search_typesense'),
                'url' => new \moodle_url('/search/engine/typesense/reindex_areas.php'),
            ],
            'infer' => [
                'label' => get_string('nav_infer', 'search_typesense'),
                'url' => new \moodle_url('/search/engine/typesense/infer_categories.php'),
            ],
        ];

        if (class_exists('\\local_typesensepro\\plugin')) {
            $tabs['pro'] = [
                'label' => get_string('nav_pro', 'search_typesense'),
                'url' => new \moodle_url('/admin/settings.php', ['section' => 'local_typesensepro_settings']),
            ];
            $tabs['analytics'] = [
                'label' => get_string('nav_analytics', 'search_typesense'),
                'url' => new \moodle_url('/local/typesensepro/analytics.php'),
            ];
        }

        $items = [];
        foreach ($tabs as $key => $tab) {
            $isactive = ($key === $active);
            $linkclasses = 'nav-link' . ($isactive ? ' active font-weight-bold fw-bold' : '');
            $attrs = ['class' => $linkclasses];
            if ($isactive) {
                $attrs['aria-current'] = 'page';
            }
            $link = \html_writer::link($tab['url'], s($tab['label']), $attrs);
            $items[] = \html_writer::tag('li', $link, ['class' => 'nav-item']);
        }

        $ul = \html_writer::tag('ul', implode('', $items), ['class' => 'nav nav-tabs mb-3']);
        return \html_writer::tag('nav', $ul, [
            'aria-label' => get_string('pluginname', 'search_typesense'),
            'class' => 'search-typesense-admin-nav',
        ]);
    }
}
