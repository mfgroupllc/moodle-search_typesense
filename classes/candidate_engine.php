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
        $scope = $this->accessmanager->scope_courseids($filters);
        $enabled = array_keys(\core_search\manager::get_search_areas_list(true));
        $areas = !empty($filters->areaids) ? array_values(array_intersect($enabled, (array)$filters->areaids)) : $enabled;
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
        $quoted = array_map(function($area) {
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
        $params = [
            'q' => (string)($filters->q ?? '') !== '' ? (string)$filters->q : '*',
            'query_by' => 'title,content,description1,description2',
            'per_page' => static::BATCH_SIZE,
            'filter_by' => implode(' && ', $clauses),
        ];
        if (($filters->order ?? '') === self::SORT_DATE_DESC) {
            $params['sort_by'] = 'modified:desc';
        } else if (($filters->order ?? '') === self::SORT_DATE_ASC) {
            $params['sort_by'] = 'modified:asc';
        }
        return pro_bridge::extend_candidate_params($params, $filters, $scope, $areas);
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
        if (empty($data['id']) || empty($data['areaid']) || empty($data['itemid']) ||
                (int)$data['courseid'] <= 0 || (int)$data['contextid'] <= 0) {
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
            if (!empty($filters->{$key}) && !in_array((int)($data[$field] ?? 0),
                    access_manager::normalise_ids((array)$filters->{$key}), true)) {
                return false;
            }
        }
        if ((!empty($filters->timestart) && (int)($data['modified'] ?? 0) < (int)$filters->timestart) ||
                (!empty($filters->timeend) && (int)($data['modified'] ?? 0) > (int)$filters->timeend)) {
            return false;
        }
        if (!empty($filters->title) && \core_text::strpos(\core_text::strtolower($data['title'] ?? ''),
                \core_text::strtolower($filters->title)) === false) {
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
        if (isset($data['groupid']) && isset($access->separategroupscontexts[$contextid]) &&
                !isset($access->visiblegroupscontextsareas[$contextid][$areaid]) &&
                !isset($access->usergroups[(int)$data['groupid']])) {
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
