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
 * Typesense search renderer with Moodle Design System link deduplication.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders search results without redundant duplicate links on the same card.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \core_search\output\renderer {
    /**
     * Renders search results, injecting a dedicated Video Time tab when mod_videotime is enabled.
     *
     * @param \core_search\document[] $results Search hits.
     * @param int $page Zero-based page number.
     * @param int $totalcount Total number of results available.
     * @param \moodle_url $url Current search URL.
     * @param \core_search\area_category|null $cat Selected search area category or null.
     * @return string HTML
     */
    public function render_results($results, $page, $totalcount, $url, $cat = null) {
        $content = '';

        if (\core_search\manager::is_search_area_categories_enabled() && !empty($cat)) {
            $toprow = [];
            foreach (\core_search\manager::get_search_area_categories() as $category) {
                $taburl = clone $url;
                $taburl->param('cat', $category->get_name());
                $taburl->param('page', 0);
                $taburl->remove_params(['page', 'areaids']);
                if ($taburl->get_param('scope') === 'videotime') {
                    $taburl->param('scope', \search_typesense\course_scope::default_scope());
                }
                $toprow[$category->get_name()] = new \tabobject(
                    $category->get_name(),
                    $taburl,
                    $category->get_visiblename()
                );
            }

            if (\core_search\manager::should_hide_all_results_category()) {
                unset($toprow[\core_search\manager::SEARCH_AREA_CATEGORY_ALL]);
            }

            if (\search_typesense\course_scope::videotime_available()) {
                $vturl = clone $url;
                $vturl->param('scope', 'videotime');
                $vturl->remove_params(['page', 'areaids', 'cat']);
                $toprow['search_typesense_videotime'] = new \tabobject(
                    'search_typesense_videotime',
                    $vturl,
                    get_string('scopevideotime', 'search_typesense')
                );
            }

            $activecat = ($url->get_param('scope') === 'videotime')
                ? 'search_typesense_videotime'
                : $cat->get_name();
            $content .= $this->tabtree($toprow, $activecat);
        }

        // Paging bar.
        $perpage = \core_search\manager::DISPLAY_RESULTS_PER_PAGE;
        $content .= $this->output->paging_bar($totalcount, $page, $perpage, $url);

        // Results.
        $resultshtml = [];
        foreach ($results as $hit) {
            $resultshtml[] = $this->render_result($hit);
        }
        $content .= \html_writer::tag('div', implode('<hr/>', $resultshtml), ['class' => 'search-results']);

        // Paging bar.
        $content .= $this->output->paging_bar($totalcount, $page, $perpage, $url);

        return $content;
    }

    /**
     * Display a single search result with deduplicated context and course links.
     *
     * @param \core_search\document $doc Search document to display.
     * @return string HTML
     */
    public function render_result(\core_search\document $doc) {
        $docdata = $doc->export_for_template($this);

        // Limit text fields size.
        $docdata['title'] = shorten_text($docdata['title'], static::SEARCH_RESULT_STRING_SIZE, true);
        $docdata['content'] = $docdata['content'] ? shorten_text($docdata['content'], static::SEARCH_RESULT_TEXT_SIZE, true) : '';
        $docdata['description1'] = $docdata['description1']
            ? shorten_text($docdata['description1'], static::SEARCH_RESULT_TEXT_SIZE, true) : '';
        $docdata['description2'] = $docdata['description2']
            ? shorten_text($docdata['description2'], static::SEARCH_RESULT_TEXT_SIZE, true) : '';

        $docurl = (string)($docdata['docurl'] ?? '');
        $contexturl = (string)($docdata['contexturl'] ?? '');
        $courseurl = (string)($docdata['courseurl'] ?? '');

        // Only render secondary links when they point to a distinct URL from the primary title link.
        $docdata['showcontextlink'] = ($contexturl !== '' && $contexturl !== $docurl && $contexturl !== $courseurl);
        $docdata['showcourselink'] = ($courseurl !== '' && $courseurl !== $docurl);

        if ($doc->is_set('areaid')) {
            $areaid = (string)$doc->get('areaid');
            $area = \core_search\manager::get_search_area($areaid);
            if ($area) {
                $docdata['areavisiblename'] = (string)$area->get_visible_name();
            }
            if ($areaid === \search_typesense\user_profile_fields::USER_AREA_ID && $doc->is_set('itemid')) {
                $profilefields = \search_typesense\user_profile_fields::get_visible_fields_for_user(
                    (int)$doc->get('itemid')
                );
                if ($profilefields) {
                    $docdata['hasuserprofilefields'] = true;
                    $docdata['userprofilefields'] = $profilefields;
                }
            }
        }

        return $this->output->render_from_template('search_typesense/result', $docdata);
    }
}
