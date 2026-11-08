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
            $area = \core_search\manager::get_search_area($doc->get('areaid'));
            if ($area) {
                $docdata['areavisiblename'] = (string)$area->get_visible_name();
            }
        }

        return $this->output->render_from_template('search_typesense/result', $docdata);
    }
}
