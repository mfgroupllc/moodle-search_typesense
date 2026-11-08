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
 * Request-scoped Moodle search access manager for candidate courses.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Reuses Moodle's access checks for courses represented in search candidates.
 *
 * Instantiate explicitly. This class does not replace core's manager singleton.
 * All caches belong to this object and last for one PHP request only.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_manager extends \core_search\manager {
    /** @var array Accessible course records, keyed by the allaccessible flag. */
    private $eligiblecourses = [];

    /** @var array Course IDs currently being evaluated, as a set. */
    private $candidatecourses = [];

    /** @var array Context maps already computed, grouped by the original search scope. */
    private $contextcache = [];

    /**
     * Normalise a set of positive numeric identifiers.
     *
     * @param array $ids IDs to normalise.
     * @return int[]
     */
    public static function normalise_ids(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($id) {
            return $id > 0;
        })));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Resolve explicit user-selected course restrictions (mycoursesonly and courseids).
     *
     * Category scopes (scopecourseids) are excluded here so that passing the explicit
     * course filter to Moodle's context builder does not suppress system/user contexts.
     *
     * @param \stdClass $filters Search filters.
     * @return int[]|null Null when unrestricted by explicit course filter; [] when empty.
     */
    public function explicit_courseids(\stdClass $filters): ?array {
        $scope = null;
        if (!empty($filters->mycoursesonly)) {
            $scope = array_keys($this->eligible_courses(false));
        }
        if (!empty($filters->courseids)) {
            $ids = self::normalise_ids((array)$filters->courseids);
            $scope = $scope === null ? $ids : array_values(array_intersect($scope, $ids));
        }
        return $scope === null ? null : self::normalise_ids($scope);
    }

    /**
     * Resolve combined search course scope; null means unrestricted and [] means none.
     *
     * When a category scope is active without an explicit course restriction, SITEID is
     * retained for non-empty scopes so system/user search areas remain searchable.
     *
     * @param \stdClass $filters Search filters.
     * @return int[]|null
     */
    public function scope_courseids(\stdClass $filters): ?array {
        $explicit = $this->explicit_courseids($filters);
        $scope = $explicit;
        if (property_exists($filters, 'scopecourseids') && $filters->scopecourseids !== null) {
            $ids = self::normalise_ids((array)$filters->scopecourseids);
            if ($ids && $explicit === null && ($filters->scope ?? 'current') === 'current') {
                $ids[] = (int)SITEID;
                $ids = self::normalise_ids($ids);
            }
            $scope = $scope === null ? $ids : array_values(array_intersect($scope, $ids));
        }
        return $scope === null ? null : self::normalise_ids($scope);
    }

    /**
     * Obtain the unchanged core access rules for a bounded set of candidates.
     *
     * @param int[] $courseids Courses present in a candidate batch.
     * @param \stdClass $filters Original search filters.
     * @return \stdClass
     */
    public function candidate_access(array $courseids, \stdClass $filters): \stdClass {
        $scope = $this->scope_courseids($filters);
        if ($scope === []) {
            return self::empty_access();
        }
        $explicit = $this->explicit_courseids($filters);
        if ($explicit === []) {
            return self::empty_access();
        }
        $contextids = self::normalise_ids((array)($filters->contextids ?? []));
        $courseids = self::normalise_ids($courseids);
        if ($scope !== null) {
            $courseids = array_values(array_intersect($courseids, $scope));
        }

        $key = json_encode([$explicit, $scope, $contextids]);
        if (!isset($this->contextcache[$key])) {
            $this->contextcache[$key] = ['courses' => [], 'info' => self::empty_access(), 'initialised' => false];
        }
        $cached = &$this->contextcache[$key];
        $missing = array_diff($courseids, array_keys($cached['courses']));
        if ($missing || !$cached['initialised']) {
            $this->candidatecourses = array_fill_keys($missing, true);
            // Pass only explicit course filters to parent so system/user contexts and
            // site-admin behaviour match core while get_my_courses() limits course expansion.
            $info = parent::get_areas_user_accesses($explicit ?? false, $contextids ?: false);
            $cached['info']->everything = !empty($info->everything);
            if (empty($info->everything)) {
                foreach ($info->usercontexts as $areaid => $contexts) {
                    $cached['info']->usercontexts[$areaid] = ($cached['info']->usercontexts[$areaid] ?? []) + $contexts;
                }
                foreach (['separategroupscontexts', 'usergroups'] as $field) {
                    $cached['info']->{$field} += $info->{$field};
                }
                foreach ($info->visiblegroupscontextsareas as $contextid => $areas) {
                    $cached['info']->visiblegroupscontextsareas[$contextid] =
                        ($cached['info']->visiblegroupscontextsareas[$contextid] ?? []) + $areas;
                }
            }
            $cached['courses'] += array_fill_keys($missing, true);
            $cached['initialised'] = true;
        }
        return $cached['info'];
    }

    /**
     * Read core course eligibility once per flag, without caching permission decisions across requests.
     *
     * @param bool $allaccessible Whether accessible unenrolled courses are included.
     * @return array
     */
    private function eligible_courses(bool $allaccessible): array {
        $key = (int)$allaccessible;
        if (!array_key_exists($key, $this->eligiblecourses)) {
            $this->eligiblecourses[$key] = parent::get_my_courses($allaccessible);
        }
        return $this->eligiblecourses[$key];
    }

    /**
     * Return courses accessible to the current user restricted to the current candidate batch.
     *
     * @param bool $allaccessible True to include unenrolled courses the user can access.
     * @return array
     */
    protected function get_my_courses($allaccessible) {
        return array_intersect_key($this->eligible_courses((bool)$allaccessible), $this->candidatecourses);
    }

    /**
     * Return course records for the current candidate batch when all courses are searched.
     *
     * @param array|false $limitcourseids Explicit course ID filter or false.
     * @return array
     */
    protected function get_all_courses($limitcourseids) {
        $ids = array_keys($this->candidatecourses);
        if ($limitcourseids !== false) {
            $ids = array_values(array_intersect($ids, $limitcourseids));
        }
        // Core interprets an empty limit as ALL courses. Never pass it through.
        return $ids ? parent::get_all_courses($ids) : [];
    }

    /**
     * Return a complete, fail-closed access descriptor.
     *
     * @return \stdClass
     */
    private static function empty_access(): \stdClass {
        return (object)[
            'everything' => false,
            'usercontexts' => [],
            'separategroupscontexts' => [],
            'visiblegroupscontextsareas' => [],
            'usergroups' => [],
        ];
    }
}
