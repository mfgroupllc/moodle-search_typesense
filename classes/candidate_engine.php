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
 * Candidate-first Typesense search engine for the plugin controller.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Searches ranked candidates before expanding Moodle course access contexts.
 *
 * This service is used by the plugin controller, never as a replacement for
 * core's manager singleton. Unauthorised candidates stay on the server.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class candidate_engine extends engine {
    /** @var string Sort order value for newest modified first. */
    public const SORT_DATE_DESC = 'datedesc';

    /** @var string Sort order value for oldest modified first. */
    public const SORT_DATE_ASC = 'dateasc';

    /** @var int Candidates requested per backend round trip. */
    protected const BATCH_SIZE = 50;

    /** @var int Maximum candidates inspected for one page request. */
    protected const MAX_CANDIDATES = 2000;

    /** @var int Cooperative time budget; an in-flight core/HTTP call cannot be interrupted. */
    protected const TIME_BUDGET = 10;

    /** @var access_manager Access calculations cached only for this request. */
    protected $accessmanager;

    /**
     * Whether this engine supports filtering by group IDs.
     *
     * @return bool
     */
    public function supports_group_filtering() {
        return true;
    }

    /**
     * Whether this engine supports filtering by user IDs.
     *
     * @return bool
     */
    public function supports_users() {
        return true;
    }

    /**
     * Return an authorised page, with no unverified backend result counts.
     *
     * limited means scanning stopped before enough results or proven exhaustion.
     * hasmore is true only when another authorised result was actually checked.
     *
     * @param \stdClass $filters Original search filters, plus optional scopecourseids.
     * @param int $page Zero-based result page.
     * @return \stdClass Results, actualpage, totalcount, hasmore and limited.
     */
    public function search_page(\stdClass $filters, int $page): \stdClass {
        require_capability('moodle/search:query', \context_system::instance());
        $started = microtime(true);
        $perpage = \core_search\manager::DISPLAY_RESULTS_PER_PAGE;
        $maxresults = \core_search\manager::MAX_RESULTS;
        $page = max(0, min($page, (int)ceil($maxresults / $perpage) - 1));
        $prefixlimit = min($maxresults, ($page + 1) * $perpage);
        $target = min($maxresults, $prefixlimit + 1);
        $this->accessmanager = $this->accessmanager ?? new access_manager($this);
        $enabled = user_profile_fields::filter_allowed_areas(
            array_keys(\core_search\manager::get_search_areas_list(true))
        );
        $areas = !empty($filters->areaids) ? array_values(array_intersect($enabled, (array)$filters->areaids)) : $enabled;
        $initialscope = $this->accessmanager->scope_courseids($filters);
        if (
            $areas === ['core_course-course'] && ($filters->scope ?? '') === 'current' &&
                $this->accessmanager->explicit_courseids($filters) === null
        ) {
            // When explicitly searching the Courses tab, include courses and templates across all categories.
            $filters = clone $filters;
            $filters->scopecourseids = null;
            $filters->scope = 'all';
        }
        $scope = $this->accessmanager->scope_courseids($filters);
        if ($scope === [] || !$areas) {
            return $this->page_result([], 0, false, false);
        }

        $params = $this->candidate_params($filters, $scope, $areas);
        $client = $this->get_client();
        $collection = $this->get_collection_name();
        $documents = [];
        $seen = [];
        $scanned = 0;
        $backendpage = 1;
        $exhausted = false;
        $limited = false;

        $isrelevancesort = empty($filters->order) || $filters->order === 'relevance';
        if (
            $page === 0 && $isrelevancesort && in_array('core_course-course', $areas, true) &&
                trim((string)($filters->q ?? '')) !== '' && trim((string)$filters->q) !== '*'
        ) {
            $maxpriority = ($areas === ['core_course-course']) ? $target : min(5, $target);
            $documents = $this->prepend_priority_courses($filters, $initialscope, $seen, $started, $maxpriority);
        }
        if (
            $page === 0 && $isrelevancesort && in_array(user_profile_fields::USER_AREA_ID, $areas, true) &&
                trim((string)($filters->q ?? '')) !== '' && trim((string)$filters->q) !== '*' &&
                count($documents) < $target
        ) {
            $maxuserpriority = ($areas === [user_profile_fields::USER_AREA_ID])
                ? ($target - count($documents))
                : min(5, $target - count($documents));
            $userdocs = $this->prepend_priority_users($filters, $seen, $started, $maxuserpriority);
            if ($userdocs) {
                $documents = array_merge($documents, $userdocs);
            }
        }

        while (count($documents) < $target && $scanned < static::MAX_CANDIDATES) {
            if (microtime(true) - $started >= static::TIME_BUDGET) {
                $limited = true;
                break;
            }
            $params['page'] = $backendpage++;
            $response = $client->search($collection, $params);
            // The /multi_search endpoint can return HTTP 200 with an error inside results[0].
            if (isset($response['error']) || (isset($response['code']) && (int)$response['code'] >= 400)) {
                throw new \moodle_exception(
                    'typesense:servererror',
                    'search_typesense',
                    '',
                    (string)($response['error'] ?? 'Search failed'),
                    'HTTP ' . (int)($response['code'] ?? 500)
                );
            }
            if (!isset($response['hits']) || !is_array($response['hits']) || !isset($response['found'])) {
                throw new \moodle_exception('typesense:servererror', 'search_typesense', '', 'Invalid search response');
            }
            $hits = $response['hits'];
            $batch = [];
            $courseids = [];
            foreach ($hits as $hit) {
                if ($scanned >= static::MAX_CANDIDATES) {
                    $limited = true;
                    break;
                }
                $scanned++;
                $data = $this->candidate_data($hit);
                if ($data === null || isset($seen[$data['id']])) {
                    continue;
                }
                $seen[$data['id']] = true;
                if (!$this->matches_filters($data, $filters, $scope, $areas)) {
                    continue;
                }
                $batch[] = $data;
                $courseids[(int)$data['courseid']] = (int)$data['courseid'];
            }

            if ($batch) {
                $access = $this->accessmanager->candidate_access(array_values($courseids), $filters);
                foreach ($batch as $data) {
                    if (microtime(true) - $started >= static::TIME_BUDGET) {
                        $limited = true;
                        break;
                    }
                    if (!$this->context_allows($data, $access)) {
                        continue;
                    }
                    $area = $this->get_search_area($data['areaid']);
                    if (!$area) {
                        continue;
                    }
                    try {
                        $itemaccess = $area->check_access($data['itemid']);
                        if ($itemaccess === \core_search\manager::ACCESS_DELETED) {
                            try {
                                $this->delete_by_id($data['id']);
                            } catch (\Throwable $deleteerror) {
                                debugging('Typesense stale document delete failed: ' . $deleteerror->getMessage(), DEBUG_DEVELOPER);
                            }
                            continue;
                        }
                        if ($itemaccess !== \core_search\manager::ACCESS_GRANTED) {
                            continue;
                        }
                        // URLs, display metadata and snippets are created only after all checks.
                        $documents[] = $this->to_document($area, $data);
                    } catch (\dml_exception $e) {
                        // A stale/missing record never makes a candidate visible.
                        continue;
                    }
                    if (count($documents) >= $target) {
                        break;
                    }
                }
            }
            if ($limited || count($documents) >= $target) {
                break;
            }
            if (!empty($response['search_cutoff'])) {
                $limited = true;
                break;
            }
            if (count($hits) < static::BATCH_SIZE || $scanned >= (int)$response['found']) {
                $exhausted = true;
                break;
            }
        }
        if (!$exhausted && count($documents) < $target) {
            $limited = true;
        }
        if ($exhausted && count($documents) <= $page * $perpage) {
            $page = max(0, (int)floor((count($documents) - 1) / $perpage));
        }
        $hasmore = count($documents) > ($page + 1) * $perpage;
        return $this->page_result($documents, $page, $hasmore, $limited);
    }

    /**
     * Build backend filters. These reduce candidates, but do not replace access checks.
     *
     * @param \stdClass $filters Search filters.
     * @param array|null $scope Resolved course restriction.
     * @param string[] $areas Enabled/requested areas.
     * @return array
     */
    protected function candidate_params(\stdClass $filters, ?array $scope, array $areas): array {
        global $USER;
        $quoted = array_map(function ($area) {
            // IDs originate in Moodle's enabled search areas, not arbitrary filter input.
            return '`' . str_replace('`', '', $area) . '`';
        }, $areas);
        $clauses = [
            'areaid:[' . implode(',', $quoted) . ']',
            '(owneruserid:0 || owneruserid:' . (int)$USER->id . ')',
        ];
        if ($scope !== null) {
            $clauses[] = 'courseid:[' . implode(',', $scope) . ']';
        }
        foreach (['contextids' => 'contextid', 'userids' => 'userid', 'groupids' => 'groupid'] as $key => $field) {
            if (!empty($filters->{$key})) {
                $ids = access_manager::normalise_ids((array)$filters->{$key});
                // Invalid explicit filters must match nothing, not turn into unfiltered queries.
                $clauses[] = $field . ':[' . ($ids ? implode(',', $ids) : '-1') . ']';
            }
        }
        if (!empty($filters->timestart)) {
            $clauses[] = 'modified:>=' . (int)$filters->timestart;
        }
        if (!empty($filters->timeend)) {
            $clauses[] = 'modified:<=' . (int)$filters->timeend;
        }
        $rawq = (string)($filters->q ?? '');
        $normq = trim((string)preg_replace('/_+/', ' ', $rawq));
        $effectiveq = $normq !== '' ? $normq : ($rawq !== '' ? $rawq : '*');
        $iscourseonly = ($areas === ['core_course-course']);
        $queryby = $iscourseonly
            ? 'title,description2,description1,content'
            : $this->get_query_by_fields();
        $params = [
            'q' => $effectiveq,
            'query_by' => $queryby,
            'per_page' => static::BATCH_SIZE,
            'filter_by' => implode(' && ', $clauses),
        ];
        if ($effectiveq !== '*') {
            // Require all search terms to match so adding terms narrows results rather than expanding via OR fallback.
            $params['drop_tokens_threshold'] = 0;
            $params['prioritize_token_position'] = 'true';
            $params['max_candidates'] = 200;
            if ($iscourseonly) {
                $params['query_by_weights'] = '12,10,3,1';
            }
        }
        if (($filters->order ?? '') === self::SORT_DATE_DESC) {
            $params['sort_by'] = 'modified:desc';
        } else if (($filters->order ?? '') === self::SORT_DATE_ASC) {
            $params['sort_by'] = 'modified:asc';
        }
        return pro_bridge::extend_candidate_params($params, $filters, $scope, $areas);
    }

    /**
     * Surface matching courses and templates to the top of Page 1 across categories.
     *
     * @param \stdClass $filters Search filters.
     * @param array|null $currentscope Initial category scope course IDs.
     * @param array $seen Reference to seen document IDs map.
     * @param float $started Request start microtime.
     * @param int $maxlimit Maximum priority course documents to return.
     * @return \core_search\document[]
     */
    protected function prepend_priority_courses(
        \stdClass $filters,
        ?array $currentscope,
        array &$seen,
        float $started,
        int $maxlimit
    ): array {
        global $USER;
        if ($maxlimit <= 0 || microtime(true) - $started >= static::TIME_BUDGET) {
            return [];
        }
        $area = $this->get_search_area('core_course-course');
        if (!$area) {
            return [];
        }
        $explicitscope = $this->accessmanager->explicit_courseids($filters);
        $coursescope = $explicitscope;
        if ($explicitscope === null && ($filters->scope ?? '') === 'historical') {
            $coursescope = $currentscope;
        }
        $clauses = [
            'areaid:[`core_course-course`]',
            '(owneruserid:0 || owneruserid:' . (int)$USER->id . ')',
        ];
        if ($coursescope !== null) {
            if ($coursescope === []) {
                return [];
            }
            $clauses[] = 'courseid:[' . implode(',', $coursescope) . ']';
        }
        if (!empty($filters->timestart)) {
            $clauses[] = 'modified:>=' . (int)$filters->timestart;
        }
        if (!empty($filters->timeend)) {
            $clauses[] = 'modified:<=' . (int)$filters->timeend;
        }
        $rawq = (string)($filters->q ?? '');
        $normq = trim((string)preg_replace('/_+/', ' ', $rawq));
        $params = [
            'q' => $normq !== '' ? $normq : $rawq,
            'query_by' => 'title,description2,description1',
            'query_by_weights' => '12,10,3',
            'drop_tokens_threshold' => 0,
            'prioritize_token_position' => 'true',
            'per_page' => 25,
            'page' => 1,
            'prefix' => 'true',
            'exclude_fields' => 'embedding',
            'filter_by' => implode(' && ', $clauses),
        ];
        try {
            $response = $this->get_client()->search($this->get_collection_name(), $params);
        } catch (\Throwable $e) {
            return [];
        }
        if (empty($response['hits']) || !is_array($response['hits'])) {
            return [];
        }

        $coursefilters = clone $filters;
        if ($explicitscope === null && ($filters->scope ?? '') !== 'historical') {
            $coursefilters->scopecourseids = null;
            $coursefilters->scope = 'all';
        }
        $currentlookup = is_array($currentscope) ? array_fill_keys($currentscope, true) : [];
        $tier0 = [];
        $tier1 = [];
        $courseids = [];
        foreach ($response['hits'] as $hit) {
            $data = $this->candidate_data($hit);
            if ($data === null || isset($seen[$data['id']])) {
                continue;
            }
            if (!$this->matches_filters($data, $coursefilters, $coursescope, ['core_course-course'])) {
                continue;
            }
            $cid = (int)$data['courseid'];
            $courseids[$cid] = $cid;
            $haystack = ($data['title'] ?? '') . ' ' . ($data['description1'] ?? '');
            $ispriority = isset($currentlookup[$cid]) ||
                preg_match('/(?:^|[\s_\-])(template|primary|master)(?:$|[\s_\-])/i', $haystack);
            if ($ispriority) {
                $tier0[] = $data;
            } else {
                $tier1[] = $data;
            }
        }
        $ordered = array_merge($tier0, $tier1);
        if (!$ordered) {
            return [];
        }

        $access = $this->accessmanager->candidate_access(array_values($courseids), $coursefilters);
        $documents = [];
        foreach ($ordered as $data) {
            if (microtime(true) - $started >= static::TIME_BUDGET) {
                break;
            }
            if (!$this->context_allows($data, $access)) {
                continue;
            }
            try {
                $itemaccess = $area->check_access($data['itemid']);
                if ($itemaccess === \core_search\manager::ACCESS_GRANTED) {
                    $seen[$data['id']] = true;
                    $documents[] = $this->to_document($area, $data);
                }
            } catch (\dml_exception $e) {
                continue;
            }
            if (count($documents) >= $maxlimit) {
                break;
            }
        }
        return $documents;
    }

    /**
     * Surface matching user profiles (by name, ID number / student number, email, or phone) to the top of results.
     *
     * Runs an exact-prefix pass (num_typos=0, max_candidates=500) first so numeric ID prefixes like "9999" match
     * student ID numbers ("9999999998") directly without being buried under 1-typo phone numbers or forum posts,
     * and falls back to typo-tolerant matching for name searches (such as "Sam Hutchens" -> "Sam Hutchins").
     *
     * @param \stdClass $filters Search filters.
     * @param array $seen Reference to seen document IDs map.
     * @param float $started Request start microtime.
     * @param int $maxlimit Maximum priority user documents to return.
     * @return \core_search\document[]
     */
    protected function prepend_priority_users(
        \stdClass $filters,
        array &$seen,
        float $started,
        int $maxlimit
    ): array {
        global $USER;
        if ($maxlimit <= 0 || microtime(true) - $started >= static::TIME_BUDGET) {
            return [];
        }
        $area = $this->get_search_area(user_profile_fields::USER_AREA_ID);
        if (!$area) {
            return [];
        }
        $clauses = [
            'areaid:[`' . user_profile_fields::USER_AREA_ID . '`]',
            '(owneruserid:0 || owneruserid:' . (int)$USER->id . ')',
        ];
        if (!empty($filters->timestart)) {
            $clauses[] = 'modified:>=' . (int)$filters->timestart;
        }
        if (!empty($filters->timeend)) {
            $clauses[] = 'modified:<=' . (int)$filters->timeend;
        }
        $rawq = trim((string)($filters->q ?? ''));
        $normq = trim((string)preg_replace('/_+/', ' ', $rawq));
        $effectiveq = $normq !== '' ? $normq : $rawq;
        if ($effectiveq === '' || $effectiveq === '*') {
            return [];
        }

        $plugin = !empty($this->pluginname) ? $this->pluginname : 'search_typesense';
        $canidentity = user_profile_fields::can_current_user_query_identity($plugin);
        $queryby = $canidentity
            ? 'title,useridentity,description1,description2,content'
            : 'title,description1,description2,content';
        $weights = $canidentity ? '12,12,4,4,1' : '12,4,4,1';

        $baseparams = [
            'q' => $effectiveq,
            'query_by' => $queryby,
            'query_by_weights' => $weights,
            'drop_tokens_threshold' => 0,
            'prioritize_token_position' => 'true',
            'per_page' => 20,
            'page' => 1,
            'prefix' => 'true',
            'max_candidates' => 500,
            'exclude_fields' => 'embedding',
            'filter_by' => implode(' && ', $clauses),
        ];

        $raws = [];
        try {
            $exactparams = $baseparams;
            $exactparams['num_typos'] = 0;
            $resp0 = $this->get_client()->search($this->get_collection_name(), $exactparams);
            if (!empty($resp0['hits']) && is_array($resp0['hits'])) {
                $raws = $resp0['hits'];
            }
            if (count($raws) < $maxlimit) {
                $resp1 = $this->get_client()->search($this->get_collection_name(), $baseparams);
                if (!empty($resp1['hits']) && is_array($resp1['hits'])) {
                    $raws = array_merge($raws, $resp1['hits']);
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        if (!$raws) {
            return [];
        }

        $userfilters = clone $filters;
        $userfilters->scopecourseids = null;
        $userfilters->scope = 'all';

        $qlower = \core_text::strtolower($effectiveq);
        $tier0 = [];
        $tier1 = [];
        foreach ($raws as $hit) {
            $data = $this->candidate_data($hit);
            if ($data === null || isset($seen[$data['id']])) {
                continue;
            }
            if (!$this->matches_filters($data, $userfilters, null, [user_profile_fields::USER_AREA_ID])) {
                continue;
            }
            $doc = $hit['document'] ?? [];
            $titlelower = \core_text::strtolower((string)($doc['title'] ?? ''));
            $identlower = \core_text::strtolower((string)($doc['useridentity'] ?? ''));
            $firstidenttoken = strtok($identlower, ' ');
            $isdirectidortitle = (strpos($titlelower, $qlower) === 0)
                || ($firstidenttoken !== false && strpos($firstidenttoken, $qlower) === 0);
            if ($isdirectidortitle) {
                $tier0[$data['id']] = $data;
            } else if (!isset($tier0[$data['id']])) {
                $tier1[$data['id']] = $data;
            }
        }
        $ordered = array_values($tier0) + array_values($tier1);
        $ordered = array_merge(array_values($tier0), array_values($tier1));
        if (!$ordered) {
            return [];
        }

        $access = $this->accessmanager->candidate_access([(int)SITEID], $userfilters);
        $documents = [];
        foreach ($ordered as $data) {
            if (microtime(true) - $started >= static::TIME_BUDGET) {
                break;
            }
            if (!$this->context_allows($data, $access)) {
                continue;
            }
            try {
                $itemaccess = $area->check_access($data['itemid']);
                if ($itemaccess === \core_search\manager::ACCESS_GRANTED) {
                    $seen[$data['id']] = true;
                    $documents[] = $this->to_document($area, $data);
                }
            } catch (\dml_exception $e) {
                continue;
            }
            if (count($documents) >= $maxlimit) {
                break;
            }
        }
        return $documents;
    }

    /**
     * Reject malformed or inconsistent indexed records before using their access metadata.
     *
     * @param array $hit Raw backend hit.
     * @return array|null
     */
    protected function candidate_data(array $hit): ?array {
        $indexed = $hit['document'] ?? [];
        $data = json_decode($indexed['docdata'] ?? '', true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['id', 'areaid', 'courseid', 'contextid', 'owneruserid'] as $field) {
            if (!isset($data[$field], $indexed[$field]) || (string)$data[$field] !== (string)$indexed[$field]) {
                return null;
            }
        }
        if (
            empty($data['id']) || empty($data['areaid']) || empty($data['itemid']) ||
                (int)$data['courseid'] <= 0 || (int)$data['contextid'] <= 0
        ) {
            return null;
        }
        foreach (['userid', 'groupid', 'modified'] as $field) {
            if (isset($data[$field]) && (!isset($indexed[$field]) || (string)$data[$field] !== (string)$indexed[$field])) {
                return null;
            }
        }
        return $data;
    }

    /**
     * Apply explicit filters and document ownership again to the payload being rendered.
     *
     * @param array $data Document data.
     * @param \stdClass $filters Search filters.
     * @param array|null $scope Resolved course restriction.
     * @param string[] $areas Permitted search areas.
     * @return bool
     */
    protected function matches_filters(array $data, \stdClass $filters, ?array $scope, array $areas): bool {
        global $USER;
        if (!in_array((int)$data['owneruserid'], [0, (int)$USER->id], true) || !in_array($data['areaid'], $areas, true)) {
            return false;
        }
        if ($scope !== null && !in_array((int)$data['courseid'], $scope, true)) {
            return false;
        }
        foreach (['contextids' => 'contextid', 'userids' => 'userid', 'groupids' => 'groupid'] as $key => $field) {
            if (
                !empty($filters->{$key}) && !in_array(
                    (int)($data[$field] ?? 0),
                    access_manager::normalise_ids((array)$filters->{$key}),
                    true
                )
            ) {
                return false;
            }
        }
        if (
            (!empty($filters->timestart) && (int)($data['modified'] ?? 0) < (int)$filters->timestart) ||
                (!empty($filters->timeend) && (int)($data['modified'] ?? 0) > (int)$filters->timeend)
        ) {
            return false;
        }
        if (
            !empty($filters->title) && \core_text::strpos(
                \core_text::strtolower($data['title'] ?? ''),
                \core_text::strtolower($filters->title)
            ) === false
        ) {
            return false;
        }
        return true;
    }

    /**
     * Check the exact area/context pair and Moodle's separate-groups restrictions.
     *
     * @param array $data Document data.
     * @param \stdClass $access Core access descriptor.
     * @return bool
     */
    protected function context_allows(array $data, \stdClass $access): bool {
        if (!empty($access->everything)) {
            return true;
        }
        $areaid = $data['areaid'];
        $contextid = (int)$data['contextid'];
        if (!isset($access->usercontexts[$areaid][$contextid])) {
            return false;
        }
        if (
            isset($data['groupid']) && isset($access->separategroupscontexts[$contextid]) &&
                !isset($access->visiblegroupscontextsareas[$contextid][$areaid]) &&
                !isset($access->usergroups[(int)$data['groupid']])
        ) {
            return false;
        }
        return true;
    }

    /**
     * Format only the authorised prefix. Totalcount deliberately excludes raw backend counts.
     *
     * @param array $documents Authorised documents in rank order.
     * @param int $page Zero-based page.
     * @param bool $hasmore Whether one more authorised document was found.
     * @param bool $limited Whether the scan stopped without proving exhaustion.
     * @return \stdClass
     */
    protected function page_result(array $documents, int $page, bool $hasmore, bool $limited): \stdClass {
        $perpage = \core_search\manager::DISPLAY_RESULTS_PER_PAGE;
        $this->totalresults = count($documents);
        return (object)[
            'results' => array_slice($documents, $page * $perpage, $perpage),
            'actualpage' => $page,
            'totalcount' => count($documents),
            'hasmore' => $hasmore,
            'limited' => $limited,
        ];
    }
}
