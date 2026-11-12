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
     * Guard flag indicating fast_reindex_area() is currently executing.
     *
     * @var bool
     */
    protected bool $infastreindex = false;

    /**
     * Pre-indexed area stats captured during area_index_starting() to pass back to manager::index().
     *
     * @var array<string, array>
     */
    protected array $preindexedstats = [];

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
            // The 'modified' field doubles as the default sorting field; int32 keeps us safe
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
     * Uses a 250-document batch size when file indexing is enabled so extracted
     * PDF/Office buffers stay well within memory limits on large activity tables.
     *
     * @return int
     */
    public function get_batch_max_documents(): int {
        return $this->file_indexing_enabled() ? 250 : 500;
    }

    /**
     * Run pre-indexing preparation, raising memory limits and repairing unbuilt context paths.
     *
     * @param bool $fullindex True if a full index will be performed.
     * @return void
     */
    public function index_starting($fullindex = false) {
        global $DB;
        raise_memory_limit(MEMORY_HUGE);
        try {
            if ($DB->record_exists_select('context', 'path IS NULL OR depth = 0')) {
                self::repair_unbuilt_contexts();
            }
        } catch (\Throwable $e) {
            debugging('Context path check skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Repair unbuilt {context} rows and remove orphaned contexts whose parent course/context was deleted.
     *
     * Fixes the root cause of Moodle core's Gradual Re-index page failing with
     * "moodle_database::get_in_or_equal() does not accept empty arrays" when orphaned
     * {course_modules} or {block_instances} records reference deleted courses/contexts.
     *
     * @return array{before:int, after:int, repaired:int}
     */
    public static function repair_unbuilt_contexts(): array {
        global $DB;
        \core_php_time_limit::raise(0);
        raise_memory_limit(MEMORY_HUGE);

        $before = (int)$DB->count_records_select('context', 'path IS NULL OR depth = 0');
        if ($before === 0) {
            return ['before' => 0, 'after' => 0, 'repaired' => 0];
        }

        try {
            \context_helper::cleanup_instances();
        } catch (\Throwable $e) {
            debugging('context_helper::cleanup_instances skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        try {
            \context_helper::build_all_paths(false);
        } catch (\Throwable $e) {
            debugging('context_helper::build_all_paths skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Any remaining rows with NULL path or 0 depth are orphans whose parent course or parent context no longer exists.
        $remaining = $DB->get_records_select(
            'context',
            'path IS NULL OR depth = 0',
            null,
            'id ASC',
            'id, contextlevel, instanceid'
        );
        if ($remaining) {
            $orphancmids = [];
            $orphanbiids = [];
            $orphanctxids = [];
            foreach ($remaining as $ctx) {
                if ((int)$ctx->id === SYSCONTEXTID) {
                    continue;
                }
                $orphanctxids[] = (int)$ctx->id;
                if ((int)$ctx->contextlevel === CONTEXT_MODULE && !empty($ctx->instanceid)) {
                    $cm = $DB->get_record('course_modules', ['id' => (int)$ctx->instanceid], 'id, course');
                    if ($cm && !$DB->record_exists('course', ['id' => (int)$cm->course])) {
                        $orphancmids[] = (int)$cm->id;
                    }
                } else if ((int)$ctx->contextlevel === CONTEXT_BLOCK && !empty($ctx->instanceid)) {
                    $bi = $DB->get_record('block_instances', ['id' => (int)$ctx->instanceid], 'id, parentcontextid');
                    if ($bi && !$DB->record_exists('context', ['id' => (int)$bi->parentcontextid])) {
                        $orphanbiids[] = (int)$bi->id;
                    }
                }
            }
            if ($orphancmids) {
                $DB->delete_records_list('course_modules', 'id', $orphancmids);
            }
            if ($orphanbiids) {
                $DB->delete_records_list('block_instances', 'id', $orphanbiids);
            }
            if ($orphanctxids) {
                $DB->delete_records_list('context', 'id', $orphanctxids);
            }
            \context_helper::reset_caches();
        }

        $after = (int)$DB->count_records_select('context', 'path IS NULL OR depth = 0');
        return [
            'before' => $before,
            'after' => $after,
            'repaired' => max(0, $before - $after),
        ];
    }

    /**
     * Enqueue a background ad-hoc task to run Fast NDJSON Re-index for a single search area.
     *
     * Avoids Apache mod_proxy_fcgi / ALB HTTP timeouts on large tables by executing via CLI cron.
     *
     * @param string $areaid Target search area ID.
     * @param bool $deletefirst Whether to delete existing documents in the area first.
     * @param int $timelimit Optional per-run time limit in seconds (0 = unlimited).
     * @return bool True if queued (or already queued).
     */
    public static function enqueue_area_reindex_task(
        string $areaid,
        bool $deletefirst = false,
        int $timelimit = 0
    ): bool {
        $existing = self::get_queued_reindex_tasks();
        foreach ($existing as $taskinfo) {
            if (($taskinfo['areaid'] ?? '') === $areaid) {
                return true;
            }
        }
        $task = new \search_typesense\task\fast_reindex_area_adhoc_task();
        $task->set_component('search_typesense');
        $task->set_custom_data([
            'areaid' => $areaid,
            'deletefirst' => $deletefirst,
            'timelimit' => $timelimit,
            'queuedat' => time(),
        ]);
        \core\task\manager::queue_adhoc_task($task);
        return true;
    }

    /**
     * Return all queued or currently running fast_reindex_area_adhoc_task records.
     *
     * @return array<int, array{id:int, areaid:string, deletefirst:bool, timestarted:int, timecreated:int}>
     */
    public static function get_queued_reindex_tasks(): array {
        global $DB;
        $classname = '\\search_typesense\\task\\fast_reindex_area_adhoc_task';
        $altclassname = 'search_typesense\\task\\fast_reindex_area_adhoc_task';
        try {
            [$insql, $params] = $DB->get_in_or_equal([$classname, $altclassname], SQL_PARAMS_NAMED);
            $records = $DB->get_records_select('task_adhoc', "classname $insql", $params, 'id ASC');
        } catch (\Throwable $e) {
            return [];
        }
        $list = [];
        foreach ($records as $rec) {
            $data = json_decode((string)($rec->customdata ?? '{}'), true);
            $list[(int)$rec->id] = [
                'id' => (int)$rec->id,
                'areaid' => (string)($data['areaid'] ?? ''),
                'deletefirst' => !empty($data['deletefirst']),
                'timestarted' => (int)($rec->timestarted ?? 0),
                'timecreated' => (int)($rec->timecreated ?? $data['queuedat'] ?? 0),
            ];
        }
        return $list;
    }

    /**
     * Cancel queued fast_reindex_area_adhoc_task records (optionally filtered by areaid).
     *
     * @param string $areaid Optional search area ID to cancel (empty = cancel all).
     * @return int Number of task rows removed.
     */
    public static function cancel_queued_reindex_tasks(string $areaid = ''): int {
        global $DB;
        $tasks = self::get_queued_reindex_tasks();
        $removed = 0;
        foreach ($tasks as $id => $info) {
            if ($areaid === '' || $info['areaid'] === $areaid) {
                $DB->delete_records('task_adhoc', ['id' => $id]);
                $removed++;
            }
        }
        if ($areaid === '' || get_config('search_typesense', 'active_reindex_area') === $areaid) {
            unset_config('active_reindex_area', 'search_typesense');
            unset_config('active_reindex_progress', 'search_typesense');
        }
        return $removed;
    }

    /**
     * Prepare memory, clear static course modinfo caches, and accelerate initial/resumed indexing
     * using Primary-Key chunked NDJSON streaming before Moodle core opens a full-table recordset.
     *
     * @param \core_search\base $searcharea The search area starting indexing.
     * @param bool $fullindex True if a full index is being performed.
     * @return void
     */
    public function area_index_starting($searcharea, $fullindex = false) {
        raise_memory_limit(MEMORY_HUGE);
        if ($searcharea instanceof \core_search\base_activity) {
            $searcharea->activitiesdata = [];
        }
        get_fast_modinfo(0, 0, true);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        if ($this->infastreindex || !$searcharea instanceof \core_search\base) {
            return;
        }
        $areaid = $searcharea->get_area_id();
        $ispkarea = ($searcharea instanceof \core_message\search\base_message)
            || ($searcharea instanceof \core_search\base_activity && str_ends_with($areaid, '-activity'))
            || ($areaid === 'mod_forum-post');
        if (!$ispkarea) {
            return;
        }

        [$componentconfigname, $varname] = $searcharea->get_config_var_name();
        $prevstart = (int)get_config($componentconfigname, $varname . '_indexingstart');
        $pkcursor = (int)get_config($componentconfigname, $varname . '_pkcursor');
        if ($fullindex || $prevstart <= 0 || $pkcursor > 0) {
            $stats = $this->fast_reindex_area($searcharea, (bool)$fullindex, 0);
            $this->preindexedstats[$areaid] = $stats;
            $now = time();
            set_config($varname . '_indexingstart', $now, $componentconfigname);
            unset_config($varname . '_partial', $componentconfigname);
        }
    }

    /**
     * Override parent add_documents() to return pre-indexed PK-chunked stats when area_index_starting()
     * already streamed the bulk table, avoiding duplicate full-table scans in manager::index().
     *
     * @param \Iterator $iterator Recordset walk iterator.
     * @param \core_search\base $searcharea Search area instance.
     * @param array $options Indexing options.
     * @return array Six-element array [records, docs, ignored, lastindexeddoc, partial, batches].
     */
    public function add_documents($iterator, $searcharea, $options) {
        $areaid = $searcharea->get_area_id();
        if (isset($this->preindexedstats[$areaid])) {
            $pre = $this->preindexedstats[$areaid];
            unset($this->preindexedstats[$areaid]);
            return [
                (int)$pre['records'],
                (int)$pre['docs'],
                (int)$pre['ignored'],
                (int)($pre['lastindexeddoc'] ?? time()),
                (bool)$pre['partial'],
                (int)$pre['batches'],
            ];
        }
        return parent::add_documents($iterator, $searcharea, $options);
    }

    /**
     * Release per-area caches and collect cyclic references after an area completes indexing.
     *
     * @param \core_search\base $searcharea The search area that completed.
     * @param int $numdocs The number of documents added.
     * @param bool $fullindex True if a full index is being performed.
     * @return bool
     */
    public function area_index_complete($searcharea, $numdocs = 0, $fullindex = false) {
        if ($searcharea instanceof \core_search\base_activity) {
            $searcharea->activitiesdata = [];
        }
        get_fast_modinfo(0, 0, true);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        return true;
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
        raise_memory_limit(MEMORY_HUGE);
        $collection = $this->ensure_collection();
        $userids = [];
        foreach ($documents as $document) {
            if (
                $document->is_set('areaid') &&
                    (string)$document->get('areaid') === user_profile_fields::USER_AREA_ID &&
                    $document->is_set('itemid')
            ) {
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
            get_fast_modinfo(0, 0, true);
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
        unset($mapped, $results, $succeededdocs);
        get_fast_modinfo(0, 0, true);
        return [$processed, $failed, 1];
    }

    /**
     * Return live document counts per search area from Typesense via a single facet query.
     *
     * @return array<string, int> Map of areaid => document count in Typesense.
     */
    public function get_area_document_counts(): array {
        if (!$this->is_installed()) {
            return [];
        }
        try {
            $collection = $this->ensure_collection();
            $res = $this->get_client()->search($collection, [
                'q' => '*',
                'query_by' => 'title',
                'facet_by' => 'areaid',
                'max_facet_values' => 250,
                'per_page' => 0,
            ]);
            $counts = [];
            foreach ($res['facet_counts'] ?? [] as $facet) {
                if (($facet['field_name'] ?? '') !== 'areaid') {
                    continue;
                }
                foreach ($facet['counts'] ?? [] as $entry) {
                    $val = (string)($entry['value'] ?? '');
                    if ($val !== '') {
                        $counts[$val] = (int)($entry['count'] ?? 0);
                    }
                }
            }
            return $counts;
        } catch (\Throwable $e) {
            debugging('Typesense area facet count failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }

    /**
     * Reset a single search area's indexing state so the next bulk indexer run re-indexes it from scratch via NDJSON.
     *
     * Also clears any slow context-level gradual reindex queue entries for this area in {search_index_requests}.
     *
     * @param \core_search\base $searcharea Target search area instance.
     * @return void
     */
    public static function reset_area_for_reindex(\core_search\base $searcharea): void {
        global $DB;
        [$componentconfigname, $varname] = $searcharea->get_config_var_name();
        $suffixes = [
            'indexingstart',
            'indexingend',
            'lastindexrun',
            'docsignored',
            'docsprocessed',
            'recordsprocessed',
            'partial',
            'pkcursor',
        ];
        foreach ($suffixes as $suffix) {
            unset_config($varname . '_' . $suffix, $componentconfigname);
        }
        try {
            $DB->delete_records('search_index_requests', ['searcharea' => $searcharea->get_area_id()]);
        } catch (\Throwable $e) {
            debugging('Could not clear search_index_requests for area: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Execute a fast bulk NDJSON re-index of a single search area directly without context-queue overhead.
     *
     * Persists progress counters (_docsprocessed, _recordsprocessed, _lastindexrun) incrementally after every
     * NDJSON batch so progress is visible in real time and preserved across time limits or interruptions.
     *
     * @param \core_search\base $searcharea Target search area instance.
     * @param bool $deletefirst Whether to delete existing documents for this area before indexing.
     * @param int $timelimit Optional time limit in seconds (0 = no limit).
     * @param \core\progress\base|null $progress Optional Moodle progress tracker.
     * @return array{records:int, docs:int, ignored:int, batches:int, partial:bool, elapsed:float}
     */
    public function fast_reindex_area(
        \core_search\base $searcharea,
        bool $deletefirst = false,
        int $timelimit = 0,
        ?\core\progress\base $progress = null
    ): array {
        global $DB;
        \core_php_time_limit::raise(0);
        $this->infastreindex = true;
        $areaid = $searcharea->get_area_id();
        $progressstarted = false;
        try {
            if ($progress !== null) {
                $progress->start_progress($searcharea->get_visible_name());
                $progressstarted = true;
            }
            $this->index_starting(false);
            $this->area_index_starting($searcharea, false);

            [$componentconfigname, $varname] = $searcharea->get_config_var_name();

            if ($deletefirst) {
                $this->delete($areaid);
                unset_config($varname . '_pkcursor', $componentconfigname);
                unset_config($varname . '_partial', $componentconfigname);
            }
            try {
                $DB->delete_records('search_index_requests', ['searcharea' => $areaid]);
            } catch (\Throwable $e) {
                debugging('Could not clear search_index_requests: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            $indexingstart = time();
            $started = microtime(true);
            set_config($varname . '_indexingstart', $indexingstart, $componentconfigname);
            set_config('active_reindex_area', $areaid, 'search_typesense');

            // Use Primary-Key range chunked streaming for core_message, base_activity, and forum_post areas.
            if ($searcharea instanceof \core_message\search\base_message) {
                $stats = $this->fast_reindex_message_area($searcharea, $timelimit, $started);
                $this->area_index_complete($searcharea, $stats['docs'], false);
                unset_config('active_reindex_area', 'search_typesense');
                unset_config('active_reindex_progress', 'search_typesense');
                set_config(
                    'last_reindex_summary',
                    json_encode(array_merge(['areaid' => $areaid, 'time' => time()], $stats)),
                    'search_typesense'
                );
                return $stats;
            }
            if ($searcharea instanceof \core_search\base_activity && str_ends_with($areaid, '-activity')) {
                $stats = $this->fast_reindex_activity_area($searcharea, $timelimit, $started, $progress);
                $this->area_index_complete($searcharea, $stats['docs'], false);
                unset_config('active_reindex_area', 'search_typesense');
                unset_config('active_reindex_progress', 'search_typesense');
                set_config(
                    'last_reindex_summary',
                    json_encode(array_merge(['areaid' => $areaid, 'time' => time()], $stats)),
                    'search_typesense'
                );
                return $stats;
            }
            if ($areaid === 'mod_forum-post') {
                $stats = $this->fast_reindex_forum_post_area($searcharea, $timelimit, $started, $progress);
                $this->area_index_complete($searcharea, $stats['docs'], false);
                unset_config('active_reindex_area', 'search_typesense');
                unset_config('active_reindex_progress', 'search_typesense');
                set_config(
                    'last_reindex_summary',
                    json_encode(array_merge(['areaid' => $areaid, 'time' => time()], $stats)),
                    'search_typesense'
                );
                return $stats;
            }

            $resume = (bool)get_config($componentconfigname, $varname . '_partial');
            $startfrom = $resume ? (int)get_config($componentconfigname, $varname . '_lastindexrun') : 0;
            $numrecords = $resume ? (int)get_config($componentconfigname, $varname . '_recordsprocessed') : 0;
            $numdocs = $resume ? (int)get_config($componentconfigname, $varname . '_docsprocessed') : 0;
            $numdocsignored = $resume ? (int)get_config($componentconfigname, $varname . '_docsignored') : 0;
            $batches = 0;
            $lastindexeddoc = $startfrom;
            $firstindexeddoc = 0;
            $partial = false;

            $recordset = $searcharea->get_recordset_by_timestamp($startfrom);
            $fileindexing = $this->file_indexing_enabled() && $searcharea->uses_file_indexing();
            $options = [
            'indexfiles' => $fileindexing,
            'lastindexedtime' => $startfrom,
            ];
            $stopat = ($timelimit > 0) ? ($started + $timelimit) : 0;
            $batchmax = $this->get_batch_max_documents();
            $contentmax = $this->get_batch_max_content();
            $currentbatch = [];

            $flushbatch = function () use (
                &$currentbatch,
                &$numdocs,
                &$numdocsignored,
                &$batches,
                &$numrecords,
                &$lastindexeddoc,
                $fileindexing,
                $varname,
                $componentconfigname,
                $areaid,
                $started,
                $progress
            ): void {
                if (!$currentbatch) {
                    return;
                }
                [$processed, $failed, $bcount] = $this->add_document_batch($currentbatch, $fileindexing);
                $numdocs += $processed;
                $numdocsignored += $failed;
                $batches += $bcount;
                $currentbatch = [];

                $now = time();
                set_config($varname . '_docsprocessed', $numdocs, $componentconfigname);
                set_config($varname . '_recordsprocessed', $numrecords, $componentconfigname);
                set_config($varname . '_docsignored', $numdocsignored, $componentconfigname);
                set_config($varname . '_indexingend', $now, $componentconfigname);
                if ($lastindexeddoc > 0) {
                    set_config($varname . '_lastindexrun', $lastindexeddoc, $componentconfigname);
                }
                $elapsed = max(0.01, round(microtime(true) - $started, 2));
                set_config('active_reindex_progress', json_encode([
                'areaid' => $areaid,
                'docs' => $numdocs,
                'records' => $numrecords,
                'batches' => $batches,
                'rate' => (int)round($numdocs / $elapsed),
                'updated' => $now,
                ]), 'search_typesense');
                if ($progress !== null) {
                    $progress->progress();
                }
            };

            if ($recordset) {
                $iterator = new \core\dml\recordset_walk($recordset, [$searcharea, 'get_document'], $options);
                foreach ($iterator as $document) {
                    if ($stopat > 0 && $lastindexeddoc !== $firstindexeddoc && microtime(true) >= $stopat) {
                        $partial = true;
                        break;
                    }
                    if (!$document instanceof \core_search\document) {
                        $numdocsignored++;
                        continue;
                    }
                    if ($startfrom === 0) {
                        $document->set_is_new(true);
                    }
                    if ($fileindexing) {
                        $searcharea->attach_files($document);
                    }
                    if (strlen((string)$document->get('content')) <= $contentmax) {
                        $currentbatch[] = $document;
                        if (count($currentbatch) >= $batchmax) {
                            $flushbatch();
                        }
                    } else {
                        if ($this->add_document($document, $fileindexing)) {
                            $numdocs++;
                        } else {
                            $numdocsignored++;
                        }
                        $batches++;
                    }
                    $lastindexeddoc = (int)$document->get('modified');
                    if (!$firstindexeddoc) {
                        $firstindexeddoc = $lastindexeddoc;
                    }
                    $numrecords++;
                }
                $flushbatch();
                $recordset->close();
            }

            $this->area_index_complete($searcharea, $numdocs, false);

            $indexingend = time();
            set_config($varname . '_indexingend', $indexingend, $componentconfigname);
            set_config($varname . '_docsignored', $numdocsignored, $componentconfigname);
            set_config($varname . '_docsprocessed', $numdocs, $componentconfigname);
            set_config($varname . '_recordsprocessed', $numrecords, $componentconfigname);
            if ($lastindexeddoc > 0) {
                set_config($varname . '_lastindexrun', $lastindexeddoc, $componentconfigname);
            } else if (!$partial) {
                set_config($varname . '_lastindexrun', $indexingend, $componentconfigname);
            }
            if ($partial) {
                set_config($varname . '_partial', 1, $componentconfigname);
            } else {
                unset_config($varname . '_partial', $componentconfigname);
            }

            $stats = [
                'records' => (int)$numrecords,
                'docs' => (int)$numdocs,
                'ignored' => (int)$numdocsignored,
                'batches' => (int)$batches,
                'partial' => (bool)$partial,
                'elapsed' => round(microtime(true) - $started, 2),
                'lastindexeddoc' => (int)$lastindexeddoc,
            ];
            unset_config('active_reindex_area', 'search_typesense');
            unset_config('active_reindex_progress', 'search_typesense');
            set_config(
                'last_reindex_summary',
                json_encode(array_merge(['areaid' => $areaid, 'time' => $indexingend], $stats)),
                'search_typesense'
            );

            return $stats;
        } finally {
            if ($progressstarted && $progress !== null) {
                try {
                    $progress->end_progress();
                } catch (\Throwable $e) {
                    debugging('Progress end skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            if ((string)get_config('search_typesense', 'active_reindex_area') === $areaid) {
                unset_config('active_reindex_area', 'search_typesense');
                unset_config('active_reindex_progress', 'search_typesense');
            }
            $this->infastreindex = false;
        }
    }

    /**
     * Universal Primary-Key chunked + pre-joined context & batch file streamer for ALL \core_search\base_activity plugins.
     *
     * Automatically accelerates any core or third-party activity module (assign, quiz, feedback, page, resource,
     * zoom, videotime, lti, customcert, forum, etc.) by:
     * 1. Streaming {modulename} in 2,500-row primary-key (m.id) chunks instead of buffering the entire table in RAM.
     * 2. Pre-joining {course_modules} and {context} in SQL to eliminate N per-row get_fast_modinfo() cache misses.
     * 3. Running a single batch {files} query per chunk to attach files only to activities that actually have files.
     *
     * @param \core_search\base_activity $searcharea Activity search area instance.
     * @param int $timelimit Optional time limit in seconds (0 = unlimited).
     * @param float $started Microtime start timestamp.
     * @param \core\progress\base|null $progress Optional Moodle progress tracker.
     * @return array{records:int, docs:int, ignored:int, batches:int, partial:bool, elapsed:float, lastindexeddoc:int}
     */
    protected function fast_reindex_activity_area(
        \core_search\base_activity $searcharea,
        int $timelimit,
        float $started,
        ?\core\progress\base $progress = null
    ): array {
        global $DB;
        $areaid = $searcharea->get_area_id();
        [$componentconfigname, $varname] = $searcharea->get_config_var_name();
        [$componentname, $areaname] = explode('-', $areaid, 2);
        $modname = str_starts_with($componentname, 'mod_') ? substr($componentname, 4) : '';
        $moduleid = ($modname !== '') ? (int)$DB->get_field('modules', 'id', ['name' => $modname]) : 0;

        if ($moduleid <= 0 || !$DB->get_manager()->table_exists($modname)) {
            return [
                'records' => 0,
                'docs' => 0,
                'ignored' => 0,
                'batches' => 0,
                'partial' => false,
                'elapsed' => 0.0,
                'lastindexeddoc' => time(),
            ];
        }

        $minmax = $DB->get_record_sql("SELECT MIN(id) AS minid, MAX(id) AS maxid FROM {{$modname}}");
        if (!$minmax || empty($minmax->maxid)) {
            $now = time();
            set_config($varname . '_indexingend', $now, $componentconfigname);
            set_config($varname . '_lastindexrun', $now, $componentconfigname);
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            return [
                'records' => 0,
                'docs' => 0,
                'ignored' => 0,
                'batches' => 0,
                'partial' => false,
                'elapsed' => 0.0,
                'lastindexeddoc' => $now,
            ];
        }

        $savedcursor = (int)get_config($componentconfigname, $varname . '_pkcursor');
        $cursor = ($savedcursor > 0) ? $savedcursor : (int)$minmax->minid;
        $maxid = (int)$minmax->maxid;
        $chunksize = 2500;

        $numrecords = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_recordsprocessed') : 0;
        $numdocs = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_docsprocessed') : 0;
        $numdocsignored = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_docsignored') : 0;
        $batches = 0;
        $lastindexeddoc = (int)get_config($componentconfigname, $varname . '_lastindexrun');
        $partial = false;
        $stopat = ($timelimit > 0) ? ($started + $timelimit) : 0;
        $fileindexing = $this->file_indexing_enabled() && $searcharea->uses_file_indexing();
        $batchmax = $this->get_batch_max_documents();
        $modifiedfield = $searcharea::MODIFIED_FIELD_NAME;

        // Detect whether get_document() and attach_files() use the standard base_activity implementation.
        $docdeclaring = (new \ReflectionMethod($searcharea, 'get_document'))->getDeclaringClass()->getName();
        $directdoc = in_array($docdeclaring, [
            \core_search\base_activity::class,
            'mod_page\\search\\activity',
            'mod_url\\search\\activity',
        ], true);
        $attachdeclaring = (new \ReflectionMethod($searcharea, 'attach_files'))->getDeclaringClass()->getName();
        $directattach = ($attachdeclaring === \core_search\base_activity::class);
        $fileareas = $fileindexing ? $searcharea->get_search_fileareas() : [];
        $fs = $fileindexing ? get_file_storage() : null;

        $preloadcols = \context_helper::get_preload_record_columns_sql('ctx');
        $sql = "SELECT m.*, cm.id AS _ts_cmid, ctx.id AS _ts_ctxid, {$preloadcols}
                  FROM {{$modname}} m
                  JOIN {course_modules} cm ON cm.instance = m.id AND cm.course = m.course AND cm.module = :modid
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlevel
                       AND ctx.path IS NOT NULL AND ctx.depth > 0
                 WHERE m.id >= :idstart AND m.id < :idend
              ORDER BY m.course ASC, m.id ASC";

        while ($cursor <= $maxid) {
            if ($stopat > 0 && microtime(true) >= $stopat) {
                $partial = true;
                break;
            }
            $idend = $cursor + $chunksize;
            $rs = $DB->get_recordset_sql($sql, [
                'modid' => $moduleid,
                'ctxlevel' => CONTEXT_MODULE,
                'idstart' => $cursor,
                'idend' => $idend,
            ]);

            $chunkdocs = [];
            $chunkctxids = [];
            foreach ($rs as $rec) {
                $ctxid = (int)$rec->_ts_ctxid;
                \context_helper::preload_from_record($rec);
                if ($directdoc) {
                    $doc = \core_search\document_factory::instance($rec->id, $componentname, $areaname);
                    $doc->set('title', content_to_text((string)($rec->name ?? ''), false));
                    $introfmt = (int)($rec->introformat ?? FORMAT_MOODLE);
                    $introtxt = content_to_text((string)($rec->intro ?? ''), $introfmt);
                    if ($docdeclaring === 'mod_page\\search\\activity') {
                        $contentfmt = (int)($rec->contentformat ?? FORMAT_MOODLE);
                        $doc->set('content', content_to_text((string)($rec->content ?? ''), $contentfmt));
                        $doc->set('description1', $introtxt);
                    } else if ($docdeclaring === 'mod_url\\search\\activity') {
                        $doc->set('content', $introtxt);
                        $doc->set('description1', (string)($rec->externalurl ?? ''));
                    } else {
                        $doc->set('content', $introtxt);
                    }
                    $doc->set('contextid', $ctxid);
                    $doc->set('courseid', (int)($rec->course ?? SITEID));
                    $doc->set('owneruserid', \core_search\manager::NO_OWNER_ID);
                    $modified = isset($rec->{$modifiedfield}) ? (int)$rec->{$modifiedfield} : time();
                    $doc->set('modified', $modified);
                    $doc->set_is_new(true);
                } else {
                    $doc = $searcharea->get_document($rec, ['indexfiles' => $fileindexing, 'lastindexedtime' => 0]);
                }

                if (!$doc instanceof \core_search\document) {
                    $numdocsignored++;
                    continue;
                }
                $chunkdocs[] = $doc;
                $chunkctxids[$ctxid] = $ctxid;
                $numrecords++;
                $modtime = (int)$doc->get('modified');
                if ($modtime > $lastindexeddoc) {
                    $lastindexeddoc = $modtime;
                }
            }
            $rs->close();

            // Batch-attach files for the chunk in 1 SQL query instead of N per-activity queries + get_fast_modinfo().
            if ($fileindexing && $chunkdocs && !empty($fileareas)) {
                if ($directattach && $chunkctxids) {
                    [$ctxsql, $ctxparams] = $DB->get_in_or_equal(array_values($chunkctxids), SQL_PARAMS_NAMED, 'ctx');
                    [$fasql, $faparams] = $DB->get_in_or_equal(array_values($fileareas), SQL_PARAMS_NAMED, 'fa');
                    $filerecords = $DB->get_recordset_sql(
                        "SELECT *
                           FROM {files}
                          WHERE contextid {$ctxsql}
                            AND component = :comp
                            AND filearea {$fasql}
                            AND filename <> '.'
                       ORDER BY contextid ASC, itemid ASC, sortorder DESC, id ASC",
                        array_merge($ctxparams, $faparams, ['comp' => $componentname])
                    );
                    $filesbyctx = [];
                    foreach ($filerecords as $frec) {
                        $filesbyctx[(int)$frec->contextid][] = $fs->get_file_instance($frec);
                    }
                    $filerecords->close();
                    foreach ($chunkdocs as $doc) {
                        $dctxid = (int)$doc->get('contextid');
                        if (!empty($filesbyctx[$dctxid])) {
                            foreach ($filesbyctx[$dctxid] as $file) {
                                $doc->add_stored_file($file);
                            }
                        }
                    }
                    unset($filesbyctx);
                } else {
                    foreach ($chunkdocs as $doc) {
                        $searcharea->attach_files($doc);
                    }
                }
            }

            foreach (array_chunk($chunkdocs, $batchmax) as $batch) {
                [$processed, $failed, $bcount] = $this->add_document_batch($batch, $fileindexing);
                $numdocs += $processed;
                $numdocsignored += $failed;
                $batches += $bcount;
                if ($progress !== null) {
                    $progress->progress();
                }
                $elapsed = max(0.01, round(microtime(true) - $started, 2));
                set_config('active_reindex_progress', json_encode([
                    'areaid' => $areaid,
                    'docs' => $numdocs,
                    'records' => $numrecords,
                    'batches' => $batches,
                    'rate' => (int)round($numdocs / $elapsed),
                    'cursor' => $cursor,
                    'maxid' => $maxid,
                    'updated' => time(),
                ]), 'search_typesense');
            }
            unset($chunkdocs, $chunkctxids);

            $cursor = $idend;
            $now = time();
            set_config($varname . '_pkcursor', $cursor, $componentconfigname);
            set_config($varname . '_docsprocessed', $numdocs, $componentconfigname);
            set_config($varname . '_recordsprocessed', $numrecords, $componentconfigname);
            set_config($varname . '_docsignored', $numdocsignored, $componentconfigname);
            set_config($varname . '_indexingend', $now, $componentconfigname);
            if ($lastindexeddoc > 0) {
                set_config($varname . '_lastindexrun', $lastindexeddoc, $componentconfigname);
            }
            $elapsed = max(0.01, round(microtime(true) - $started, 2));
            set_config('active_reindex_progress', json_encode([
                'areaid' => $areaid,
                'docs' => $numdocs,
                'records' => $numrecords,
                'batches' => $batches,
                'rate' => (int)round($numdocs / $elapsed),
                'cursor' => $cursor,
                'maxid' => $maxid,
                'updated' => $now,
            ]), 'search_typesense');
        }

        $now = time();
        if ($partial) {
            set_config($varname . '_partial', 1, $componentconfigname);
        } else {
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            set_config($varname . '_lastindexrun', max($lastindexeddoc, $now), $componentconfigname);
        }

        return [
            'records' => (int)$numrecords,
            'docs' => (int)$numdocs,
            'ignored' => (int)$numdocsignored,
            'batches' => (int)$batches,
            'partial' => (bool)$partial,
            'elapsed' => round(microtime(true) - $started, 2),
            'lastindexeddoc' => max($lastindexeddoc, $now),
        ];
    }

    /**
     * Stream mod_forum-post in 5,000-row primary-key (fp.id) chunks with pre-joined discussions, forums, and contexts.
     *
     * @param \core_search\base $searcharea Forum post search area instance.
     * @param int $timelimit Optional time limit in seconds (0 = unlimited).
     * @param float $started Microtime start timestamp.
     * @param \core\progress\base|null $progress Optional Moodle progress tracker.
     * @return array{records:int, docs:int, ignored:int, batches:int, partial:bool, elapsed:float, lastindexeddoc:int}
     */
    protected function fast_reindex_forum_post_area(
        \core_search\base $searcharea,
        int $timelimit,
        float $started,
        ?\core\progress\base $progress = null
    ): array {
        global $DB;
        $areaid = $searcharea->get_area_id();
        [$componentconfigname, $varname] = $searcharea->get_config_var_name();
        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'forum']);
        $activecourses = course_scope::active_indexing_courseids();
        if ($moduleid <= 0 || empty($activecourses)) {
            $now = time();
            set_config($varname . '_indexingend', $now, $componentconfigname);
            set_config($varname . '_lastindexrun', $now, $componentconfigname);
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            return [
                'records' => 0,
                'docs' => 0,
                'ignored' => 0,
                'batches' => 0,
                'partial' => false,
                'elapsed' => 0.0,
                'lastindexeddoc' => $now,
            ];
        }

        $savedcursor = (int)get_config($componentconfigname, $varname . '_pkcursor');
        [$csql, $cparams] = $DB->get_in_or_equal($activecourses, SQL_PARAMS_NAMED, 'ac');
        $cparams['minid'] = max(1, $savedcursor);
        $targetpostids = $DB->get_fieldset_sql(
            "SELECT fp.id
               FROM {forum} f
               JOIN {forum_discussions} fd ON fd.forum = f.id
               JOIN {forum_posts} fp ON fp.discussion = fd.id
              WHERE f.course {$csql}
                AND fp.id >= :minid
           ORDER BY fp.id ASC",
            $cparams
        );
        if (!$targetpostids) {
            $now = time();
            set_config($varname . '_indexingend', $now, $componentconfigname);
            set_config($varname . '_lastindexrun', $now, $componentconfigname);
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            return [
                'records' => 0,
                'docs' => 0,
                'ignored' => 0,
                'batches' => 0,
                'partial' => false,
                'elapsed' => 0.0,
                'lastindexeddoc' => $now,
            ];
        }

        $chunksize = 2500;
        $numrecords = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_recordsprocessed') : 0;
        $numdocs = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_docsprocessed') : 0;
        $numdocsignored = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_docsignored') : 0;
        $batches = 0;
        $lastindexeddoc = (int)get_config($componentconfigname, $varname . '_lastindexrun');
        $partial = false;
        $stopat = ($timelimit > 0) ? ($started + $timelimit) : 0;
        $fileindexing = $this->file_indexing_enabled() && $searcharea->uses_file_indexing();
        $batchmax = $this->get_batch_max_documents();
        $fs = $fileindexing ? get_file_storage() : null;

        $totaltarget = $numrecords + count($targetpostids);
        $idchunks = array_chunk(array_map('intval', $targetpostids), $chunksize);
        unset($targetpostids);

        foreach ($idchunks as $idchunk) {
            if ($stopat > 0 && microtime(true) >= $stopat) {
                $partial = true;
                break;
            }
            [$fpsql, $fpparams] = $DB->get_in_or_equal($idchunk, SQL_PARAMS_NAMED, 'fpid');
            $fpparams['modid'] = $moduleid;
            $fpparams['ctxlevel'] = CONTEXT_MODULE;
            $sql = "SELECT fp.id, fp.discussion, fp.created, fp.modified, fp.userid, fp.subject, fp.message, fp.messageformat,
                           f.id AS forumid, f.course AS courseid, fd.groupid AS groupid, ctx.id AS _ts_ctxid
                      FROM {forum_posts} fp
                      JOIN {forum_discussions} fd ON fd.id = fp.discussion
                      JOIN {forum} f ON f.id = fd.forum
                      JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = f.course AND cm.module = :modid
                      JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :ctxlevel
                           AND ctx.path IS NOT NULL AND ctx.depth > 0
                     WHERE fp.id {$fpsql}
                  ORDER BY fp.id ASC";
            $rs = $DB->get_recordset_sql($sql, $fpparams);

            $chunkdocs = [];
            $postids = [];
            foreach ($rs as $rec) {
                $postid = (int)$rec->id;
                $doc = \core_search\document_factory::instance($postid, 'mod_forum', 'post');
                $doc->set('title', content_to_text((string)($rec->subject ?? ''), false));
                $doc->set('content', content_to_text((string)($rec->message ?? ''), (int)($rec->messageformat ?? FORMAT_MOODLE)));
                $doc->set('contextid', (int)$rec->_ts_ctxid);
                $doc->set('courseid', (int)$rec->courseid);
                $doc->set('userid', (int)$rec->userid);
                $doc->set('owneruserid', \core_search\manager::NO_OWNER_ID);
                $doc->set('modified', (int)$rec->modified);
                if ((int)$rec->groupid > 0) {
                    $doc->set('groupid', (int)$rec->groupid);
                }
                $doc->set_is_new(true);

                $chunkdocs[$postid] = $doc;
                $postids[] = $postid;
                $numrecords++;
                if ((int)$rec->modified > $lastindexeddoc) {
                    $lastindexeddoc = (int)$rec->modified;
                }
            }
            $rs->close();

            if ($fileindexing && $postids) {
                [$pidsql, $pidparams] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'pid');
                $filerecords = $DB->get_recordset_sql(
                    "SELECT *
                       FROM {files}
                      WHERE component = 'mod_forum'
                        AND filearea IN ('attachment', 'post')
                        AND itemid {$pidsql}
                        AND filename <> '.'
                   ORDER BY itemid ASC, sortorder DESC, id ASC",
                    $pidparams
                );
                foreach ($filerecords as $frec) {
                    $pid = (int)$frec->itemid;
                    if (isset($chunkdocs[$pid])) {
                        $chunkdocs[$pid]->add_stored_file($fs->get_file_instance($frec));
                    }
                }
                $filerecords->close();
            }

            foreach (array_chunk(array_values($chunkdocs), $batchmax) as $batch) {
                [$processed, $failed, $bcount] = $this->add_document_batch($batch, $fileindexing);
                $numdocs += $processed;
                $numdocsignored += $failed;
                $batches += $bcount;
                if ($progress !== null) {
                    $progress->progress();
                }
            }
            unset($chunkdocs, $postids);

            $cursor = ((int)end($idchunk)) + 1;
            $now = time();
            set_config($varname . '_pkcursor', $cursor, $componentconfigname);
            set_config($varname . '_docsprocessed', $numdocs, $componentconfigname);
            set_config($varname . '_recordsprocessed', $numrecords, $componentconfigname);
            set_config($varname . '_docsignored', $numdocsignored, $componentconfigname);
            set_config($varname . '_indexingend', $now, $componentconfigname);
            if ($lastindexeddoc > 0) {
                set_config($varname . '_lastindexrun', $lastindexeddoc, $componentconfigname);
            }
            $elapsed = max(0.01, round(microtime(true) - $started, 2));
            set_config('active_reindex_progress', json_encode([
                'areaid' => $areaid,
                'docs' => $numdocs,
                'records' => $numrecords,
                'batches' => $batches,
                'rate' => (int)round($numdocs / $elapsed),
                'cursor' => $numrecords,
                'maxid' => max(1, $totaltarget),
                'updated' => $now,
            ]), 'search_typesense');
        }

        $now = time();
        if ($partial) {
            set_config($varname . '_partial', 1, $componentconfigname);
        } else {
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            set_config($varname . '_lastindexrun', max($lastindexeddoc, $now), $componentconfigname);
        }

        return [
            'records' => (int)$numrecords,
            'docs' => (int)$numdocs,
            'ignored' => (int)$numdocsignored,
            'batches' => (int)$batches,
            'partial' => (bool)$partial,
            'elapsed' => round(microtime(true) - $started, 2),
            'lastindexeddoc' => max($lastindexeddoc, $now),
        ];
    }

    /**
     * Stream core_message search areas in primary-key (m.id) chunks of 5,000 rows into NDJSON batches.
     *
     * Bypasses Moodle core's unindexed ORDER BY m.timecreated ASC full-table scan (which stalls on
     * multi-million-row {messages} tables) and pre-joins {user} and {context} to eliminate 2N per-row queries.
     *
     * @param \core_message\search\base_message $searcharea Message search area (received or sent).
     * @param int $timelimit Optional time limit in seconds (0 = unlimited).
     * @param float $started Microtime start timestamp.
     * @return array{records:int, docs:int, ignored:int, batches:int, partial:bool, elapsed:float}
     */
    protected function fast_reindex_message_area(
        \core_message\search\base_message $searcharea,
        int $timelimit,
        float $started
    ): array {
        global $DB;
        $areaid = $searcharea->get_area_id();
        [$componentconfigname, $varname] = $searcharea->get_config_var_name();
        $issent = ($areaid === 'core_message-message_sent');
        [$componentname, $areaname] = explode('-', $areaid, 2);

        $minmax = $DB->get_record_sql('SELECT MIN(id) AS minid, MAX(id) AS maxid FROM {messages}');
        if (!$minmax || empty($minmax->maxid)) {
            $now = time();
            set_config($varname . '_indexingend', $now, $componentconfigname);
            set_config($varname . '_lastindexrun', $now, $componentconfigname);
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            return ['records' => 0, 'docs' => 0, 'ignored' => 0, 'batches' => 0, 'partial' => false, 'elapsed' => 0.0];
        }

        $savedcursor = (int)get_config($componentconfigname, $varname . '_pkcursor');
        $defaultstart = pro_bridge::get_message_start_pk($areaid, (int)$minmax->minid, (int)$minmax->maxid);
        $cursor = ($savedcursor > 0) ? $savedcursor : $defaultstart;
        $maxid = (int)$minmax->maxid;
        $chunksize = 5000;

        $numrecords = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_recordsprocessed') : 0;
        $numdocs = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_docsprocessed') : 0;
        $numdocsignored = ($savedcursor > 0) ? (int)get_config($componentconfigname, $varname . '_docsignored') : 0;
        $batches = 0;
        $lastindexeddoc = (int)get_config($componentconfigname, $varname . '_lastindexrun');
        $partial = false;
        $stopat = ($timelimit > 0) ? ($started + $timelimit) : 0;
        $batchmax = 500;
        $currentbatch = [];
        $dedupstate = [];

        $ownerfield = $issent ? 'm.useridfrom' : 'mcm.userid';
        $sql = "SELECT m.id, m.useridfrom, m.subject, m.smallmessage, m.timecreated,
                       mcm.userid AS useridto, uctx.id AS ownerctxid
                  FROM {messages} m
                  JOIN {message_conversations} mc ON mc.id = m.conversationid
                  JOIN {message_conversation_members} mcm ON mcm.conversationid = mc.id AND mcm.userid != m.useridfrom
                  JOIN {user} uowner ON uowner.id = {$ownerfield} AND uowner.deleted = 0
                  JOIN {context} uctx ON uctx.contextlevel = :ctxuser AND uctx.instanceid = uowner.id
                 WHERE m.id >= :idstart AND m.id < :idend
                   AND {$ownerfield} != :noreplyuser
                   AND {$ownerfield} != :supportuser
              ORDER BY m.id ASC";

        while ($cursor <= $maxid) {
            if ($stopat > 0 && microtime(true) >= $stopat) {
                $partial = true;
                break;
            }
            $idend = $cursor + $chunksize;
            $rs = $DB->get_recordset_sql($sql, [
                'ctxuser' => CONTEXT_USER,
                'idstart' => $cursor,
                'idend' => $idend,
                'noreplyuser' => \core_user::NOREPLY_USER,
                'supportuser' => \core_user::SUPPORT_USER,
            ]);
            foreach ($rs as $rec) {
                $numrecords++;
                if ((int)$rec->timecreated > $lastindexeddoc) {
                    $lastindexeddoc = (int)$rec->timecreated;
                }
                if (!pro_bridge::should_index_message($rec, $dedupstate)) {
                    $numdocsignored++;
                    continue;
                }
                $owneruserid = $issent ? (int)$rec->useridfrom : (int)$rec->useridto;
                $otheruserid = $issent ? (int)$rec->useridto : (int)$rec->useridfrom;
                $doc = \core_search\document_factory::instance($rec->id, $componentname, $areaname);
                $doc->set('title', content_to_text((string)($rec->subject ?? ''), false));
                $doc->set('itemid', (int)$rec->id);
                $doc->set('content', content_to_text((string)($rec->smallmessage ?? ''), false));
                $doc->set('contextid', (int)$rec->ownerctxid);
                $doc->set('courseid', SITEID);
                $doc->set('owneruserid', $owneruserid);
                $doc->set('userid', $otheruserid);
                $doc->set('modified', (int)$rec->timecreated);
                $doc->set_is_new(true);

                $currentbatch[] = $doc;
                if (count($currentbatch) >= $batchmax) {
                    [$processed, $failed, $bcount] = $this->add_document_batch($currentbatch, false);
                    $numdocs += $processed;
                    $numdocsignored += $failed;
                    $batches += $bcount;
                    $currentbatch = [];
                }
            }
            $rs->close();

            if ($currentbatch) {
                [$processed, $failed, $bcount] = $this->add_document_batch($currentbatch, false);
                $numdocs += $processed;
                $numdocsignored += $failed;
                $batches += $bcount;
                $currentbatch = [];
            }

            $cursor = $idend;
            $now = time();
            set_config($varname . '_pkcursor', $cursor, $componentconfigname);
            set_config($varname . '_docsprocessed', $numdocs, $componentconfigname);
            set_config($varname . '_recordsprocessed', $numrecords, $componentconfigname);
            set_config($varname . '_docsignored', $numdocsignored, $componentconfigname);
            set_config($varname . '_indexingend', $now, $componentconfigname);
            if ($lastindexeddoc > 0) {
                set_config($varname . '_lastindexrun', $lastindexeddoc, $componentconfigname);
            }
            $elapsed = max(0.01, round(microtime(true) - $started, 2));
            set_config('active_reindex_progress', json_encode([
                'areaid' => $areaid,
                'docs' => $numdocs,
                'records' => $numrecords,
                'batches' => $batches,
                'rate' => (int)round($numdocs / $elapsed),
                'cursor' => $cursor,
                'maxid' => $maxid,
                'updated' => $now,
            ]), 'search_typesense');
        }

        $now = time();
        if ($partial) {
            set_config($varname . '_partial', 1, $componentconfigname);
        } else {
            unset_config($varname . '_partial', $componentconfigname);
            unset_config($varname . '_pkcursor', $componentconfigname);
            set_config($varname . '_lastindexrun', max($lastindexeddoc, $now), $componentconfigname);
        }

        return [
            'records' => (int)$numrecords,
            'docs' => (int)$numdocs,
            'ignored' => (int)$numdocsignored,
            'batches' => (int)$batches,
            'partial' => (bool)$partial,
            'elapsed' => round(microtime(true) - $started, 2),
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
        if ($document->is_set('areaid') && (string)$document->get('areaid') === 'mod_forum-post') {
            static $activecoursemap = null;
            if ($activecoursemap === null) {
                $activecoursemap = array_fill_keys(course_scope::active_indexing_courseids(), true);
            }
            $courseid = $document->is_set('courseid') ? (int)$document->get('courseid') : 0;
            if ($activecoursemap && !isset($activecoursemap[$courseid])) {
                return false;
            }
        }
        $collection = $this->ensure_collection();
        try {
            $mapped = $this->map_document($document, null, (bool)$fileindexing);
            $this->get_client()->add_document($collection, $mapped);
            pro_bridge::after_documents_indexed([$mapped]);
        } catch (\moodle_exception $e) {
            debugging(
                'Typesense add_document failed for ' . $document->get('id') . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
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
                unset($e);
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
