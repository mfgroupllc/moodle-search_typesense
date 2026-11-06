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
 * Typesense search engine.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Search engine backed by Typesense.
 *
 * Implements the \core_search\engine contract: index Moodle documents into a
 * Typesense collection and run filtered, ranked queries against it.
 */
class engine extends \core_search\engine {

    /**
     * Whether the plugin is fully configured.
     *
     * @return bool
     */
    public function is_installed() {
        $host = get_config('search_typesense', 'host');
        $port = get_config('search_typesense', 'port');
        $key = get_config('search_typesense', 'apikey');
        return !empty($host) && !empty($port) && $key !== null && $key !== '';
    }

    /**
     * Build (and cache) a Typesense client from plugin settings.
     *
     * @return client
     * @throws \moodle_exception
     */
    protected function get_client() {
        if (!$this->is_installed()) {
            throw new \moodle_exception('notconfigured', 'search_typesense');
        }
        return new client(
            get_config('search_typesense', 'host'),
            get_config('search_typesense', 'port'),
            get_config('search_typesense', 'protocol'),
            get_config('search_typesense', 'apikey')
        );
    }

    /**
     * Return the configured collection name.
     *
     * @return string
     */
    protected function get_collection_name() {
        return get_config('search_typesense', 'collection') ?: 'moodle';
    }

    /**
     * Typesense schema for a Moodle search collection.
     *
     * @return array
     */
    protected function get_schema_fields() {
        $locale = get_config('search_typesense', 'locale');
        $fields = [];
        foreach (['title', 'content', 'description1', 'description2'] as $name) {
            $field = ['name' => $name, 'type' => 'string'];
            if (!empty($locale)) {
                $field['locale'] = $locale;
            }
            $fields[] = $field;
        }
        $fields[] = ['name' => 'id', 'type' => 'string'];
        $fields[] = ['name' => 'areaid', 'type' => 'string', 'facet' => true];
        $fields[] = ['name' => 'courseid', 'type' => 'int32', 'facet' => true, 'optional' => true];
        $fields[] = ['name' => 'contextid', 'type' => 'int32', 'facet' => true, 'optional' => true];
        $fields[] = ['name' => 'userid', 'type' => 'int32', 'optional' => true];
        $fields[] = ['name' => 'groupid', 'type' => 'int32', 'optional' => true];
        // 'modified' doubles as the default sorting field; int32 keeps us safe
        // until 2038 (epoch seconds fit in a signed 32-bit int until then).
        $fields[] = ['name' => 'modified', 'type' => 'int32'];
        $fields[] = ['name' => 'timestart', 'type' => 'int32', 'optional' => true];
        $fields[] = ['name' => 'timeend', 'type' => 'int32', 'optional' => true];
        $fields[] = ['name' => 'type', 'type' => 'string', 'optional' => true];
        return $fields;
    }

    /**
     * Ensure the collection exists, creating it with our schema if not.
     *
     * @return string collection name
     */
    protected function ensure_collection() {
        $client = $this->get_client();
        $name = $this->get_collection_name();
        if (!$client->collection_exists($name)) {
            $client->create_collection($name, $this->get_schema_fields(), 'modified');
        }
        return $name;
    }

    /**
     * Check the server is reachable and configured.
     *
     * @return bool
     */
    public function is_server_ready() {
        if (!$this->is_installed()) {
            return false;
        }
        try {
            return $this->get_client()->health();
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * Whether incremental (event-driven) indexing is supported.
     *
     * @return bool
     */
    public function are_triggers_enabled() {
        return true;
    }

    /**
     * Whether documents should be ignored (e.g. engine disabled).
     *
     * @return bool
     */
    public function are_documents_ignored() {
        return false;
    }

    /**
     * Convert a Moodle search document into a Typesense document array.
     *
     * @param \core_search\document $document
     * @return array
     */
    protected function map_document(\core_search\document $document) {
        $int = function ($value) {
            $value = (int)$value;
            return $value ?: 0;
        };
        return [
            'id'           => $document->get_id(),
            'title'        => $document->get_title(),
            'content'      => $document->get_content(),
            'description1' => $document->get_description1(),
            'description2' => $document->get_description2(),
            'areaid'       => $document->get_area_id(),
            'courseid'     => $int($document->get_course_id()),
            'contextid'    => $int($document->get_context_id()),
            'userid'       => $int($document->get_user_id()),
            'groupid'      => $int($document->get_group_id()),
            'modified'     => $int($document->get_modified()),
            'timestart'    => $int($document->get_timestart()),
            'timeend'      => $int($document->get_timeend()),
            'type'         => $document->get_type(),
        ];
    }

    /**
     * Add a single document to the index.
     *
     * @param \core_search\document $document
     * @return void
     */
    protected function add_document($document) {
        $collection = $this->ensure_collection();
        $this->get_client()->add_document($collection, $this->map_document($document));
    }

    /**
     * Add multiple documents to the index (bulk import).
     *
     * @param \core_search\document[] $documents
     * @return void
     */
    protected function add_documents($documents) {
        $collection = $this->ensure_collection();
        $mapped = [];
        foreach ($documents as $document) {
            $mapped[] = $this->map_document($document);
        }
        if (!empty($mapped)) {
            $this->get_client()->import_documents($collection, $mapped);
        }
    }

    /**
     * Delete all documents belonging to a search area.
     *
     * @param \core_search\area\base $area
     * @return void
     */
    protected function delete_by_area($area) {
        $collection = $this->get_collection_name();
        $this->get_client()->delete_by_filter($collection, 'areaid:' . $area->get_area_id());
    }

    /**
     * Delete a single document by area + item id.
     *
     * @param string $areaid
     * @param int $itemid
     * @return void
     */
    protected function delete_by_id($areaid, $itemid) {
        $collection = $this->get_collection_name();
        $this->get_client()->delete_document($collection, $areaid . '-' . $itemid);
    }

    /**
     * Delete a document, optionally narrowing by context/user/group/date.
     *
     * @param string $areaid
     * @param int $itemid
     * @param int|null $contextid
     * @param int|null $userid
     * @param int|null $groupid
     * @param int|null $timestart
     * @param int|null $timeend
     * @return void
     */
    protected function delete($areaid, $itemid, $contextid = null, $userid = null, $groupid = null,
                              $timestart = null, $timeend = null) {
        $collection = $this->get_collection_name();
        $this->get_client()->delete_document($collection, $areaid . '-' . $itemid);
    }

    /**
     * Build a Typesense filter_by expression from a Moodle search query.
     *
     * @param \core_search\query $query
     * @return string
     */
    protected function build_filter($query) {
        $filters = [];

        $areaids = $query->get_area_ids();
        if (!empty($areaids)) {
            $quoted = array_map(function ($a) {
                return '"' . str_replace('"', '', $a) . '"';
            }, $areaids);
            $filters[] = 'areaid:[' . implode(',', $quoted) . ']';
        }

        $contextids = $query->get_context_ids();
        if (!empty($contextids)) {
            $filters[] = 'contextid:[' . implode(',', $contextids) . ']';
        }

        $courseid = $query->get_course_id();
        if ($courseid) {
            $filters[] = 'courseid:=' . (int)$courseid;
        }

        $timestart = $query->get_timestart();
        if ($timestart) {
            $filters[] = 'modified:>=' . (int)$timestart;
        }
        $timeend = $query->get_timeend();
        if ($timeend) {
            $filters[] = 'modified:<=' . (int)$timeend;
        }

        return implode(' && ', $filters);
    }

    /**
     * Run a search and return Moodle documents.
     *
     * @param \core_search\query $query
     * @param int $from
     * @param int $limit
     * @return \core_search\document[]
     */
    protected function search($query, $from = 0, $limit = 10) {
        $collection = $this->get_collection_name();

        $q = $query->get_query();
        if ($q === '' || $q === null) {
            $q = '*';
        }

        $sort = '_text_match:desc,modified:desc';
        if (defined('\\core_search\\manager::ORDER_MODIFIED') &&
            $query->get_order() == \core_search\manager::ORDER_MODIFIED) {
            $sort = 'modified:desc';
        }

        $params = [
            'q'        => $q,
            'query_by' => 'title,content,description1,description2',
            'filter_by' => $this->build_filter($query),
            'sort_by'  => $sort,
            'per_page' => (int)$limit,
            'page'     => floor($from / max(1, $limit)) + 1,
        ];

        $results = $this->get_client()->search($collection, $params);
        $documents = [];
        if (!empty($results['hits'])) {
            foreach ($results['hits'] as $hit) {
                if (empty($hit['document'])) {
                    continue;
                }
                $documents[] = \core_search\document::create(
                    $hit['document']['areaid'],
                    (object)$hit['document']
                );
            }
        }
        return $documents;
    }
}
