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
    /** @var string Sort order value for newest modified first. */
    public const SORT_DATE_DESC = 'datedesc';

    /** @var string Sort order value for oldest modified first. */
    public const SORT_DATE_ASC = 'dateasc';

    /**
     * Total number of results from the most recent query.
     *
     * @var null|int
     */
    protected $totalresults = null;

    /**
     * Read a configuration setting, respecting alternate engine configuration when active.
     *
     * @param string $name Setting name.
     * @return mixed
     */
    protected function get_engine_config(string $name) {
        $plugin = !empty($this->pluginname) ? $this->pluginname : 'search_typesense';
        $value = get_config($plugin, $name);
        if (($value === false || $value === null || $value === '') && $plugin !== 'search_typesense') {
            $value = get_config('search_typesense', $name);
        }
        return $value;
    }

    /**
     * Whether the plugin is fully configured.
     *
     * @return bool
     */
    public function is_installed() {
        $host = $this->get_engine_config('host');
        $port = $this->get_engine_config('port');
        $key = $this->get_engine_config('apikey');
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
            $this->get_engine_config('host'),
            $this->get_engine_config('port'),
            $this->get_engine_config('protocol'),
            $this->get_engine_config('apikey'),
            $this->get_engine_config('verifytls') !== '0'
        );
    }

    /**
     * Return the configured collection name.
     *
     * @return string
     */
    protected function get_collection_name() {
        return $this->get_engine_config('collection') ?: 'moodle';
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
        $locale = $this->get_engine_config('locale');
        $textfield = function ($name, $optional = false) use ($locale) {
            $field = [
                'name' => $name,
                'type' => 'string',
                'token_separators' => ['_', '-', '/', '.'],
            ];
            if ($optional) {
                $field['optional'] = true;
            }
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
            $textfield('useridentity', true),
            ['name' => 'docdata', 'type' => 'string'],
        ];
        return pro_bridge::extend_schema_fields($fields);
    }

    /**
     * Return the comma-separated list of fields to search for the current viewer.
     *
     * @return string
     */
    protected function get_query_by_fields(): string {
        $fields = ['title', 'content', 'description1', 'description2'];
        $plugin = !empty($this->pluginname) ? $this->pluginname : 'search_typesense';
        if (user_profile_fields::can_current_user_query_identity($plugin)) {
            $fields[] = 'useridentity';
        }
        return implode(',', $fields);
    }

    /**
     * Ensure the collection exists, creating it with our schema if not, and adding any new optional schema fields.
     *
     * @return string collection name
     */
    protected function ensure_collection() {
        static $verified = [];
        $client = $this->get_client();
        $name = $this->get_collection_name();
        if (!empty($verified[$name])) {
            return $name;
        }
        $desired = $this->get_schema_fields();
        if (!$client->collection_exists($name)) {
            $client->create_collection($name, $desired, 'modified');
            $verified[$name] = true;
            return $name;
        }
        try {
            $existing = $client->get_collection($name);
            $existingnames = [];
            foreach ($existing['fields'] ?? [] as $field) {
                if (!empty($field['name'])) {
                    $existingnames[$field['name']] = true;
                }
            }
            $missing = [];
            foreach ($desired as $field) {
                $fname = $field['name'] ?? '';
                if ($fname !== '' && $fname !== 'id' && empty($existingnames[$fname])) {
                    $field['optional'] = true;
                    $missing[] = $field;
                }
            }
            if ($missing) {
                $client->update_collection($name, $missing);
            }
        } catch (\Throwable $e) {
            debugging('Typesense schema migration check skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $verified[$name] = true;
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
     * Returns true if attached file indexing is supported and enabled.
     *
     * @return bool
     */
    public function file_indexing_enabled() {
        return (bool)($this->get_engine_config('fileindexing') ?? 1);
    }

    /**
     * Convert a Moodle search document into a Typesense document array.
     *
     * @param \core_search\document $document
     * @param array|null $preloadedidentity Preloaded useridentity map for batch indexing.
     * @param bool $fileindexing True if attached file text should be extracted and indexed.
     * @return array
     */
    protected function map_document(
        \core_search\document $document,
        ?array $preloadedidentity = null,
        bool $fileindexing = false
    ) {
        $data = $document->export_for_engine();
        $int = function ($value) {
            return (int)$value;
        };
        $content = (string)($data['content'] ?? '');
        $plugin = !empty($this->pluginname) ? $this->pluginname : 'search_typesense';
        if (($fileindexing || $this->file_indexing_enabled()) && !empty($document->get_files())) {
            $filetext = file_extractor::extract_files_text($document, $plugin);
            if ($filetext !== '') {
                $content = trim($content . "\n" . $filetext);
            }
        }
        $title = (string)($data['title'] ?? '');
        $desc1 = (string)($data['description1'] ?? '');
        $desc2 = (string)($data['description2'] ?? '');
        if (strpbrk($title . $desc1, '_-/') !== false) {
            $expanded = trim((string)preg_replace('/[_\/-]+/', ' ', $title . ' ' . $desc1));
            if ($expanded !== '') {
                $desc2 = trim($desc2 . ' ' . $expanded);
            }
        }
        $mapped = [
            'id'           => (string)$data['id'],
            'areaid'       => (string)$data['areaid'],
            'courseid'     => $int($data['courseid'] ?? 0),
            'contextid'    => $int($data['contextid'] ?? 0),
            'userid'       => $int($data['userid'] ?? 0),
            'owneruserid'  => $int($data['owneruserid'] ?? 0),
            'groupid'      => $int($data['groupid'] ?? 0),
            'type'         => $int($data['type'] ?? 1),
            'modified'     => $int($data['modified'] ?? 0),
            'title'        => $title,
            'content'      => $content,
            'description1' => $desc1,
            'description2' => $desc2,
            'docdata'      => json_encode($data),
        ];
        if ((string)$data['areaid'] === user_profile_fields::USER_AREA_ID && !empty($data['itemid'])) {
            $uid = (int)$data['itemid'];
            if ($preloadedidentity !== null) {
                $identitytext = $preloadedidentity[$uid] ?? '';
            } else {
                $identitytext = user_profile_fields::get_indexable_text($uid, $plugin);
            }
            if ($identitytext !== '') {
                $mapped['useridentity'] = $identitytext;
            }
        }
        return pro_bridge::extend_mapped_document($mapped, $document);
    }

    /**
     * Checks if the search engine supports adding documents in a batch.
     *
     * @return bool
     */
    public function supports_add_document_batch(): bool {
        return true;
    }

    /**
     * Maximum number of documents to send together in one NDJSON import batch.
     *
     * @return int
     */
    public function get_batch_max_documents(): int {
        return 500;
    }

    /**
     * Adds a batch of documents to Typesense using the bulk NDJSON import endpoint.
     *
     * @param \core_search\document[] $documents Documents to add to the index.
     * @param bool $fileindexing True if file indexing is enabled.
     * @return int[] Array of [processed count, failed count, batch count].
     */
    public function add_document_batch(array $documents, bool $fileindexing = false): array {
        if (!$documents) {
            return [0, 0, 0];
        }
        $collection = $this->ensure_collection();
        $userids = [];
        foreach ($documents as $document) {
            if ($document->is_set('areaid') &&
                    (string)$document->get('areaid') === user_profile_fields::USER_AREA_ID &&
                    $document->is_set('itemid')) {
                $userids[] = (int)$document->get('itemid');
            }
        }
        $preloadedidentity = null;
        if ($userids) {
            $plugin = !empty($this->pluginname) ? $this->pluginname : 'search_typesense';
            $preloadedidentity = user_profile_fields::get_indexable_text_batch($userids, $plugin);
        }
        $mapped = [];
        foreach ($documents as $document) {
            $mapped[] = $this->map_document($document, $preloadedidentity, $fileindexing);
        }
        try {
            $results = $this->get_client()->import_documents($collection, $mapped);
        } catch (\moodle_exception $e) {
            debugging('Typesense add_document_batch failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [0, count($documents), 1];
        }
        $processed = 0;
        $failed = 0;
        $succeededdocs = [];
        foreach ($mapped as $index => $payload) {
            if (!empty($results[$index]['success'])) {
                $processed++;
                $succeededdocs[] = $payload;
            } else {
                $failed++;
                $err = $results[$index]['error'] ?? 'Unknown import error';
                debugging('Typesense import failed for ' . ($payload['id'] ?? $index) . ': ' . $err, DEBUG_DEVELOPER);
            }
        }
        if ($succeededdocs) {
            pro_bridge::after_documents_indexed($succeededdocs);
        }
        return [$processed, $failed, 1];
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
            $mapped = $this->map_document($document, null, (bool)$fileindexing);
            $this->get_client()->add_document($collection, $mapped);
            pro_bridge::after_documents_indexed([$mapped]);
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
            'query_by' => $this->get_query_by_fields(),
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
            if ($filters->order === self::SORT_DATE_DESC) {
                $params['sort_by'] = 'modified:desc';
            } else if ($filters->order === self::SORT_DATE_ASC) {
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
