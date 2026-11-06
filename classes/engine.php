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
     * Total number of results from the most recent query.
     *
     * @var null|int
     */
    protected $totalresults = null;

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
            get_config('search_typesense', 'apikey'),
            get_config('search_typesense', 'verifytls') !== '0'
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
     * The full Moodle document is stored (as JSON) in `docdata` so it can be
     * faithfully reconstructed; the remaining fields are indexed for filtering
     * and/or searching.
     *
     * @return array
     */
    protected function get_schema_fields() {
        $locale = get_config('search_typesense', 'locale');
        $textfield = function ($name) use ($locale) {
            $field = ['name' => $name, 'type' => 'string'];
            if (!empty($locale)) {
                $field['locale'] = $locale;
            }
            return $field;
        };

        $fields = [
            ['name' => 'id', 'type' => 'string'],
            ['name' => 'areaid', 'type' => 'string', 'facet' => true],
            ['name' => 'courseid', 'type' => 'int32', 'facet' => true, 'optional' => true],
            ['name' => 'contextid', 'type' => 'int32', 'facet' => true, 'optional' => true],
            ['name' => 'userid', 'type' => 'int32', 'optional' => true],
            ['name' => 'owneruserid', 'type' => 'int32', 'optional' => true],
            ['name' => 'groupid', 'type' => 'int32', 'optional' => true],
            // 'modified' doubles as the default sorting field; int32 keeps us safe
            // until 2038 (epoch seconds fit in a signed 32-bit int until then).
            ['name' => 'modified', 'type' => 'int32'],
            $textfield('title'),
            $textfield('content'),
            $textfield('description1'),
            $textfield('description2'),
            ['name' => 'docdata', 'type' => 'string'],
        ];
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
     * Check the server is reachable, configured, and the collection exists.
     *
     * @return true|string Returns true if all good or an error string.
     */
    public function is_server_ready() {
        if (!$this->is_installed()) {
            return get_string('notconfigured', 'search_typesense');
        }
        try {
            if (!$this->get_client()->health()) {
                return get_string('servernotready', 'search_typesense');
            }
            $this->ensure_collection();
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }
        return true;
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
        $data = $document->export_for_engine();
        $int = function ($value) {
            return (int)$value;
        };
        return [
            'id'           => (string)$data['id'],
            'areaid'       => (string)$data['areaid'],
            'courseid'     => $int($data['courseid'] ?? 0),
            'contextid'    => $int($data['contextid'] ?? 0),
            'userid'       => $int($data['userid'] ?? 0),
            'owneruserid'  => $int($data['owneruserid'] ?? 0),
            'groupid'      => $int($data['groupid'] ?? 0),
            'type'         => $int($data['type'] ?? 1),
            'modified'     => $int($data['modified'] ?? 0),
            'title'        => (string)($data['title'] ?? ''),
            'content'      => (string)($data['content'] ?? ''),
            'description1' => (string)($data['description1'] ?? ''),
            'description2' => (string)($data['description2'] ?? ''),
            'docdata'      => json_encode($data),
        ];
    }

    /**
     * Adds a document to the search engine.
     *
     * Called by the parent's add_documents() iterator for each document.
     *
     * @param \core_search\document $document
     * @param bool $fileindexing True if file indexing is to be used
     * @return bool False if the document failed, true on success
     */
    public function add_document($document, $fileindexing = false) {
        $collection = $this->ensure_collection();
        try {
            $this->get_client()->add_document($collection, $this->map_document($document));
        } catch (\moodle_exception $e) {
            debugging('Typesense add_document failed for ' . $document->get('id') . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER);
            return false;
        }
        return true;
    }

    /**
     * Delete a single document by its id.
     *
     * @param string $id The document id to delete
     * @return void
     */
    public function delete_by_id($id) {
        $collection = $this->get_collection_name();
        $this->get_client()->delete_document($collection, $id);
    }

    /**
     * Delete all of an area's documents, or the whole index.
     *
     * @param string|null $areaid
     * @return void
     */
    public function delete($areaid = null) {
        $collection = $this->get_collection_name();
        $client = $this->get_client();
        if ($areaid) {
            $client->delete_by_filter($collection, 'areaid:"' . str_replace('"', '', $areaid) . '"');
        } else {
            // Removing the entire collection is the simplest way to wipe the index.
            try {
                $client->delete_collection($collection);
            } catch (\moodle_exception $e) {
                // Already gone; ignore.
            }
            $this->ensure_collection();
        }
    }

    /**
     * Deletes the index for a single context.
     *
     * @param int $oldcontextid
     * @return bool True if data was deleted
     */
    public function delete_index_for_context(int $oldcontextid) {
        $this->get_client()->delete_by_filter($this->get_collection_name(), 'contextid:' . (int)$oldcontextid);
        return true;
    }

    /**
     * Deletes the index for a single course.
     *
     * @param int $oldcourseid
     * @return bool True if data was deleted
     */
    public function delete_index_for_course(int $oldcourseid) {
        $this->get_client()->delete_by_filter($this->get_collection_name(), 'courseid:' . (int)$oldcourseid);
        return true;
    }

    /**
     * Runs a query using the given filters and access info.
     *
     * @param \stdClass $filters Containing query and filters.
     * @param array|true $accessinfo Contexts where the user has access (true = all).
     * @param int $limit The maximum number of results to return.
     * @return \core_search\document[] Results or empty array if none.
     * @throws \core_search\engine_exception
     */
    public function execute_query($filters, $accessinfo, $limit = 0) {
        if ($this->is_server_ready() !== true) {
            throw new \core_search\engine_exception('engineserverstatus', 'search');
        }

        if (empty($limit)) {
            $limit = \core_search\manager::MAX_RESULTS;
        }

        $q = $filters->q ?? '';
        if ($q === '' || $q === null) {
            $q = '*';
        }

        $params = [
            'q'        => $q,
            'query_by' => 'title,content,description1,description2',
            'per_page' => (int)$limit,
            'page'     => isset($filters->page) ? max(1, (int)$filters->page) : 1,
        ];

        // Build the filter_by expression, mirroring the access rules used by the
        // simple DB engine: restrict to the user's allowed contexts and to docs
        // they own (or docs with no owner).
        $fb = [];

        if ($accessinfo && is_array($accessinfo)) {
            $contexts = [];
            foreach ($accessinfo as $areaid => $areacontexts) {
                if (!empty($filters->areaids) && !in_array($areaid, $filters->areaids)) {
                    continue;
                }
                foreach ($areacontexts as $contextid) {
                    $contexts[$contextid] = $contextid;
                }
            }
            if (empty($contexts)) {
                // No valid contexts => no results.
                $this->totalresults = 0;
                return [];
            }
            $fb[] = 'contextid:[' . implode(',', $contexts) . ']';
        }

        if (!empty($filters->areaids)) {
            $quoted = array_map(function ($a) {
                return '"' . str_replace('"', '', $a) . '"';
            }, $filters->areaids);
            $fb[] = 'areaid:[' . implode(',', $quoted) . ']';
        }

        if (!empty($filters->courseids)) {
            $fb[] = 'courseid:[' . implode(',', $filters->courseids) . ']';
        }

        if (!empty($filters->timestart)) {
            $fb[] = 'modified:>=' . (int)$filters->timestart;
        }
        if (!empty($filters->timeend)) {
            $fb[] = 'modified:<=' . (int)$filters->timeend;
        }

        global $USER;
        $fb[] = '(owneruserid:0 || owneruserid:' . (int)$USER->id . ')';

        if (!empty($fb)) {
            $params['filter_by'] = implode(' && ', $fb);
        }

        if (!empty($filters->order)) {
            if ($filters->order == \core_search\manager::SORT_DATE_DESC) {
                $params['sort_by'] = 'modified:desc';
            } else if ($filters->order == \core_search\manager::SORT_DATE_ASC) {
                $params['sort_by'] = 'modified:asc';
            }
        }

        $results = $this->get_client()->search($this->get_collection_name(), $params);
        $this->totalresults = (int)($results['found'] ?? 0);

        $docs = [];
        $numgranted = 0;
        foreach ($results['hits'] ?? [] as $hit) {
            $docdata = json_decode($hit['document']['docdata'] ?? '{}', true);
            if (empty($docdata) || empty($docdata['areaid']) || !isset($docdata['itemid'])) {
                continue;
            }

            if (!$searcharea = $this->get_search_area($docdata['areaid'])) {
                continue;
            }

            $access = $searcharea->check_access($docdata['itemid']);
            if ($access == \core_search\manager::ACCESS_DELETED) {
                $this->delete_by_id($docdata['id']);
                $this->totalresults--;
                continue;
            }
            if ($access == \core_search\manager::ACCESS_DENIED) {
                $this->totalresults--;
                continue;
            }

            $docs[] = $this->to_document($searcharea, $docdata);
            $numgranted++;
            if ($numgranted >= $limit) {
                break;
            }
        }

        return $docs;
    }

    /**
     * Returns the total results of the most recent query.
     *
     * @return int
     */
    public function get_query_total_count() {
        if (is_null($this->totalresults)) {
            return \core_search\manager::MAX_RESULTS;
        }
        return $this->totalresults;
    }
}
