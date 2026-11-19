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
 * Admin setting for the search shortcut list.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense\admin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Shortcut list textarea that checks each line and offers the links found in the site footer.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_shortcuts extends \admin_setting_configtextarea {
    /**
     * Every non-empty line needs a label and a web address.
     *
     * @param string $data Setting value.
     * @return true|string True, or an error message.
     */
    public function validate($data) {
        $bad = [];
        foreach (preg_split('/\R/', (string)$data) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $parsed = \search_typesense\shortcuts::parse_list($line);
            if (!$parsed || \search_typesense\shortcuts::clean_url($parsed[0]['url']) === '') {
                $bad[] = $number + 1;
            }
        }
        return $bad ? get_string('shortcuts_invalid', 'search_typesense', implode(', ', $bad)) : true;
    }

    /**
     * Show the textarea followed by the footer link helper.
     *
     * @param string $data Setting value.
     * @param string $query Admin search query.
     * @return string HTML
     */
    public function output_html($data, $query = '') {
        global $PAGE, $OUTPUT;
        $html = parent::output_html($data, $query);
        $html .= $OUTPUT->render_from_template('search_typesense/footer_links', ['textareaid' => $this->get_id()]);
        $PAGE->requires->js_call_amd('search_typesense/footer_links', 'init', [$this->get_id()]);
        return $html;
    }
}
