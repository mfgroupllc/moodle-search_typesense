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
 * Privacy Subsystem implementation for search_typesense.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense\privacy;

use core_privacy\local\metadata\collection;


/**
 * Privacy provider declaring external Typesense index communication.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\subsystem\plugin_provider {
    /**
     * Returns metadata about external data sent to the Typesense server.
     *
     * @param collection $collection The initialised metadata collection to add items to.
     * @return collection The updated metadata collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('typesense', [
            'userid' => 'privacy:metadata:typesense:userid',
            'owneruserid' => 'privacy:metadata:typesense:owneruserid',
            'groupid' => 'privacy:metadata:typesense:groupid',
            'courseid' => 'privacy:metadata:typesense:courseid',
            'contextid' => 'privacy:metadata:typesense:contextid',
            'modified' => 'privacy:metadata:typesense:modified',
            'title' => 'privacy:metadata:typesense:title',
            'content' => 'privacy:metadata:typesense:content',
            'description1' => 'privacy:metadata:typesense:description1',
            'description2' => 'privacy:metadata:typesense:description2',
            'docdata' => 'privacy:metadata:typesense:docdata',
            'useridentity' => 'privacy:metadata:typesense:useridentity',
            'query' => 'privacy:metadata:typesense:query',
        ], 'privacy:metadata:typesense');
        return $collection;
    }
}
