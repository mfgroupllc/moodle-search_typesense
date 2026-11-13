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

    /** @var int Above this many accessible courses, narrowing the backend query is not worthwhile. */
    private const MAX_NARROWED_COURSES = 1000;

    /** @var array Context maps already computed, grouped by the original search scope. */
    private $contextcache = [];

    /**
     * Evaluate whether a course record is searchable under `core_course-course` for the current viewer.
     *
     * When `search_typesense/restrictunenrolledcourses` is enabled, viewers without staff/faculty
     * identity or course-management capabilities only see courses they are enrolled in, can already
     * access (such as open guest/auto-enrol courses), or can actively self-enrol in.
     *
     * @param \stdClass $course Course record.
     * @return bool
     */
    public function can_search_course_record(\stdClass $course): bool {
        if (!\core_course_category::can_view_course_info($course)) {
            return false;
        }
        $plugin = ($this->engine && method_exists($this->engine, 'get_plugin_name'))
            ? $this->engine->get_plugin_name()
            : 'search_typesense';
        $restricted = get_config($plugin, 'restrictunenrolledcourses');
        if (($restricted === false || $restricted === null) && $plugin !== 'search_typesense') {
            $restricted = get_config('search_typesense', 'restrictunenrolledcourses');
        }
        if (empty($restricted)) {
            return true;
        }
        if (is_siteadmin()) {
            return true;
        }
        $sysctx = \context_system::instance();
        if (
            has_capability('moodle/course:view', $sysctx) ||
                has_capability('moodle/course:update', $sysctx) ||
                has_capability('moodle/category:manage', $sysctx) ||
                user_profile_fields::can_current_user_query_identity($plugin)
        ) {
            return true;
        }
        $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
        if (
            $coursecontext && (
                has_capability('moodle/course:view', $coursecontext) ||
                has_capability('moodle/course:update', $coursecontext)
            )
        ) {
            return true;
        }
        if (can_access_course($course, null, '', true)) {
            return true;
        }
        return self::is_course_self_enrollable((int)$course->id);
    }

    /**
     * Check whether the given course has an active enrollment instance that permits self-enrollment for the current user.
     *
     * @param int $courseid Course ID.
     * @return bool
     */
    private static function is_course_self_enrollable(int $courseid): bool {
        if ($courseid <= 0 || isguestuser() || !isloggedin()) {
            return false;
        }
        $enrolplugins = enrol_get_plugins(true);
        if (!$enrolplugins) {
            return false;
        }
        $instances = enrol_get_instances($courseid, true);
        foreach ($instances as $instance) {
            $plugin = $enrolplugins[$instance->enrol] ?? null;
            if (!$plugin) {
                continue;
            }
            if (method_exists($plugin, 'can_self_enrol') && $plugin->can_self_enrol($instance, false) === true) {
                return true;
            }
            if (method_exists($plugin, 'show_enrolme_link') && $plugin->show_enrolme_link($instance)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a set of positive numeric identifiers.
     *
     * @param array $ids IDs to normalize.
     * @return int[]
     */
    public static function normalise_ids(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
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
            if ($ids && $explicit === null && in_array($filters->scope ?? 'current', ['current', 'videotime'], true)) {
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
            $allaccessible = (bool)get_config('core', 'searchallavailablecourses');
            $needsparent = !$cached['initialised'] || is_siteadmin() || self::include_all_courses() ||
                !empty($this->get_my_courses($allaccessible));
            if ($needsparent) {
                // Pass only explicit course filters to parent so system/user contexts and
                // site-admin behavior match core while get_my_courses() limits course expansion.
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
            }
            // Ensure candidate course records (core_course-course) are searchable whenever the user can
            // view course info, even if global searchincludeallcourses is disabled to preserve /course/search.php.
            if ($missing && empty($cached['info']->everything) && empty($filters->mycoursesonly)) {
                $this->candidatecourses = array_fill_keys($missing, true);
                $candidatecourserecs = $this->get_all_courses($explicit ?? false);
                foreach ($candidatecourserecs as $course) {
                    $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
                    if (!$coursecontext || ($contextids && !in_array((int)$coursecontext->id, $contextids, true))) {
                        continue;
                    }
                    if ($this->can_search_course_record($course)) {
                        $cid = (int)$coursecontext->id;
                        $cached['info']->usercontexts['core_course-course'][$cid] = $cid;
                    }
                }
            }
            $cached['courses'] += array_fill_keys($missing, true);
            $cached['initialised'] = true;
        }
        return $cached['info'];
    }

    /**
     * Read enrolled course eligibility once, without loading all site courses into memory.
     *
     * @param bool $allaccessible Whether accessible unenrolled courses are included.
     * @return array
     */
    private function eligible_courses(bool $allaccessible): array {
        if (!array_key_exists(0, $this->eligiblecourses)) {
            $this->eligiblecourses[0] = parent::get_my_courses(false);
        }
        if (!$allaccessible) {
            return $this->eligiblecourses[0];
        }
        if (!array_key_exists(1, $this->eligiblecourses)) {
            $this->eligiblecourses[1] = $this->eligiblecourses[0];
        }
        $unchecked = array_keys(array_diff_key($this->candidatecourses, $this->eligiblecourses[1]));
        if ($unchecked) {
            $records = parent::get_all_courses($unchecked);
            foreach ($records as $cid => $course) {
                if (is_siteadmin() || can_access_course($course, null, '', true)) {
                    $this->eligiblecourses[1][$cid] = $course;
                }
            }
        }
        return $this->eligiblecourses[1];
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
     * Return the courses whose content the current user can open, for narrowing backend candidates.
     *
     * This only reduces the candidates requested from Typesense. Moodle's access checks still run on
     * every candidate, so a course listed here never makes anything visible by itself.
     *
     * @return int[]|null Course IDs including the site course, or null when candidates are not narrowed.
     */
    public function accessible_courseids(): ?array {
        global $USER;
        $enabled = get_config('search_typesense', 'narrowcandidates');
        if ($enabled !== false && !(bool)$enabled) {
            return null;
        }
        $userid = (int)($USER->id ?? 0);
        $ttl = self::narrow_cache_ttl();
        $cache = \cache::make('search_typesense', 'accessiblecourses');
        $cached = $cache->get('u' . $userid);
        if ($ttl > 0 && is_array($cached) && (time() - (int)($cached['ts'] ?? 0)) < $ttl) {
            return $cached['ids'];
        }
        $ids = $this->compute_accessible_courseids($userid);
        $cache->set('u' . $userid, ['ts' => time(), 'ids' => $ids]);
        return $ids;
    }

    /**
     * Work out the accessible course set for a user without consulting the cache.
     *
     * Users who can view courses site-wide, or who hold a role in a course category, are never narrowed.
     *
     * @param int $userid Current user ID (0 when not logged in).
     * @return int[]|null
     */
    private function compute_accessible_courseids(int $userid): ?array {
        global $DB;
        if (is_siteadmin() || has_capability('moodle/course:view', \context_system::instance())) {
            return null;
        }
        $ids = [];
        if ($userid > 0 && !isguestuser()) {
            $sql = "SELECT 1
                      FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                     WHERE ra.userid = :userid AND ctx.contextlevel = :contextlevel";
            if ($DB->record_exists_sql($sql, ['userid' => $userid, 'contextlevel' => CONTEXT_COURSECAT])) {
                return null;
            }
            $ids = array_keys(enrol_get_my_courses('id', 'id'));
        }
        $ids = self::normalise_ids(array_merge($ids, self::open_access_courseids(), [(int)SITEID]));
        return count($ids) > self::MAX_NARROWED_COURSES ? null : $ids;
    }

    /**
     * Return visible courses that can be opened without an enrollment through the configured enrollment methods.
     *
     * @return int[]
     */
    private static function open_access_courseids(): array {
        global $DB;
        $configured = get_config('search_typesense', 'openaccessenrol');
        $methods = $configured === false
            ? ['guest']
            : array_values(array_filter(array_map('trim', explode(',', (string)$configured))));
        if (!$methods) {
            return [];
        }
        $ttl = self::narrow_cache_ttl();
        $cache = \cache::make('search_typesense', 'openaccesscourses');
        $key = 'm' . sha1(implode(',', $methods));
        $cached = $cache->get($key);
        if ($ttl > 0 && is_array($cached) && (time() - (int)($cached['ts'] ?? 0)) < $ttl) {
            return $cached['ids'];
        }
        [$insql, $params] = $DB->get_in_or_equal($methods, SQL_PARAMS_NAMED, 'enrol');
        $params['status'] = ENROL_INSTANCE_ENABLED;
        $sql = "SELECT DISTINCT e.courseid
                  FROM {enrol} e
                  JOIN {course} c ON c.id = e.courseid
                 WHERE e.status = :status AND c.visible = 1 AND e.enrol $insql";
        $ids = self::normalise_ids($DB->get_fieldset_sql($sql, $params));
        $cache->set($key, ['ts' => time(), 'ids' => $ids]);
        return $ids;
    }

    /**
     * Return how long, in seconds, accessible course sets are reused before being worked out again.
     *
     * @return int
     */
    private static function narrow_cache_ttl(): int {
        $ttl = get_config('search_typesense', 'narrowcachettl');
        return $ttl === false ? 300 : max(0, (int)$ttl);
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
