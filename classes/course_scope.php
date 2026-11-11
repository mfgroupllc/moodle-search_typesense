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
 * Course category scopes and active-category inference for the optional search page.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit and inferred category classification; course permissions are checked separately.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_scope {
    /** @var string Regex matching explicit archive, trash, or test-only category names. */
    private const EXCLUDED_NAME_REGEX =
        '/\b(archive|archived|delete me|^delete$|do not use|test courses|moodletesting|debug|trash)\b/i';

    /**
     * Return administrator-selected category IDs.
     *
     * @return int[]
     */
    public static function roots(): array {
        $raw = (string)get_config('search_typesense', 'currentcategories');
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), function($id) {
            return $id > 0;
        })));
    }

    /**
     * Whether current categories have been explicitly selected.
     *
     * @return bool
     */
    public static function configured(): bool {
        return (bool)self::roots();
    }

    /**
     * Return the default scope; remains global until categories have been classified.
     *
     * @return string
     */
    public static function default_scope(): string {
        return self::configured() ? 'current' : 'all';
    }

    /**
     * Whether the mod_videotime plugin is installed on this Moodle site.
     *
     * @return bool
     */
    public static function videotime_installed(): bool {
        return \core_component::get_component_directory('mod_videotime') !== null;
    }

    /**
     * Return enabled Video Time search area IDs when the mod_videotime plugin is installed and enabled.
     *
     * @return string[]
     */
    public static function videotime_areaids(): array {
        if (!self::videotime_installed()) {
            return [];
        }
        $enabled = \core_search\manager::get_search_areas_list(true);
        $ids = [];
        foreach (array_keys($enabled) as $areaid) {
            if (strpos((string)$areaid, 'mod_videotime-') === 0) {
                $ids[] = (string)$areaid;
            }
        }
        return $ids;
    }

    /**
     * Whether the dedicated Video Time search tab/pill is available and enabled in settings.
     *
     * @return bool
     */
    public static function videotime_available(): bool {
        $enabled = get_config('search_typesense', 'enablevideotimetab');
        if ($enabled !== false && $enabled !== null && (string)$enabled === '0') {
            return false;
        }
        return (bool)self::videotime_areaids();
    }

    /**
     * Return the display label for the Video Time search tab/pill, falling back to the language string.
     *
     * @return string
     */
    public static function videotime_label(): string {
        $custom = trim((string)get_config('search_typesense', 'videotimetablabel'));
        if ($custom !== '') {
            return format_string($custom, true, ['context' => \context_system::instance()]);
        }
        return get_string('scopevideotime', 'search_typesense');
    }

    /**
     * Return the display label for any valid scope identifier.
     *
     * @param string $scope Scope identifier ('current', 'historical', 'all', 'videotime').
     * @return string
     */
    public static function scope_label(string $scope): string {
        if ($scope === 'videotime') {
            return self::videotime_label();
        }
        return get_string('scope' . $scope, 'search_typesense');
    }

    /**
     * Return the list of valid scope identifiers for the current site.
     *
     * @return string[]
     */
    public static function valid_scopes(): array {
        $scopes = self::configured() ? ['current', 'historical', 'all'] : ['all'];
        if (self::videotime_available()) {
            $scopes[] = 'videotime';
        }
        return $scopes;
    }

    /**
     * Whether a category path or name represents an explicit archive or trash folder.
     *
     * @param string $pathorname Category name or slash-separated path of names.
     * @return bool
     */
    public static function is_excluded_name(string $pathorname): bool {
        return (bool)preg_match(self::EXCLUDED_NAME_REGEX, $pathorname);
    }

    /**
     * Whether a category path represents a timeless template, resource, staff, or admissions container.
     *
     * @param string $pathorname Category name or slash-separated path of names.
     * @return bool
     */
    public static function is_timeless_container(string $pathorname): bool {
        return (bool)preg_match('/\b(template|templates|resource|resources|faculty|staff|admissions|preview)\b/i', $pathorname);
    }

    /**
     * Resolve the reference timestamp for course date evaluation (adapts automatically to cloned stage sites).
     *
     * @param int|null $now Optional explicit timestamp override.
     * @return int
     */
    public static function reference_timestamp(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $maxenrol = (int)$DB->get_field_sql(
            "SELECT MAX(CASE WHEN timecreated >= timestart THEN timecreated ELSE timestart END)
               FROM {user_enrolments}
              WHERE status = 0 AND timestart < :maxts",
            ['maxts' => $now + (180 * DAYSECS)]
        );
        if ($maxenrol > 0 && $maxenrol < $now - (30 * DAYSECS)) {
            return $maxenrol;
        }
        $maxmod = (int)$DB->get_field_sql(
            "SELECT MAX(timemodified)
               FROM {course}
              WHERE id <> :siteid",
            ['siteid' => SITEID]
        );
        if ($maxmod > 0 && $maxmod < $now - (30 * DAYSECS)) {
            return $maxmod;
        }
        return $now;
    }

    /**
     * Resolve a scope using current DB categories, including descendants of unsplit roots,
     * and enforcing course start/end dates for non-template cohort categories.
     *
     * Null means unrestricted; an empty array means no courses, never all courses.
     *
     * @param string $scope Scope identifier ('current', 'historical', or 'all').
     * @return int[]|null
     */
    public static function courseids(string $scope): ?array {
        global $DB;
        if ($scope === 'all') {
            return null;
        }
        if ($scope === 'videotime') {
            return self::configured() ? self::courseids('current') : null;
        }
        if (!in_array($scope, ['current', 'historical'], true)) {
            throw new \invalid_parameter_exception('Unknown course search scope');
        }
        $roots = array_fill_keys(self::roots(), true);
        if (!$roots) {
            if ($scope === 'current') {
                return [];
            }
            return array_map('intval', array_keys(
                $DB->get_records_select('course', "id <> :siteid", ['siteid' => SITEID], '', 'id')
            ));
        }

        if ($scope === 'historical') {
            $currentids = self::courseids('current');
            if (empty($currentids)) {
                return array_map('intval', array_keys(
                    $DB->get_records_select('course', "id <> :siteid", ['siteid' => SITEID], '', 'id')
                ));
            }
            [$notinsql, $nparams] = $DB->get_in_or_equal($currentids, SQL_PARAMS_NAMED, 'cid', false);
            $nparams['siteid'] = SITEID;
            return array_map('intval', array_keys($DB->get_records_select(
                'course',
                "id $notinsql AND id <> :siteid",
                $nparams,
                '',
                'id'
            )));
        }

        $categories = $DB->get_records('course_categories', null, 'path', 'id,name,path');
        $hasselecteddescendant = [];
        foreach ($categories as $category) {
            $id = (int)$category->id;
            if (!isset($roots[$id])) {
                continue;
            }
            $ancestors = array_map('intval', explode('/', trim((string)$category->path, '/')));
            array_pop($ancestors);
            foreach ($ancestors as $ancestorid) {
                if (isset($roots[$ancestorid])) {
                    $hasselecteddescendant[$ancestorid] = true;
                }
            }
        }

        $current = [];
        $timelesscats = [];
        foreach ($categories as $category) {
            $id = (int)$category->id;
            $chain = array_map('intval', explode('/', trim((string)$category->path, '/')));
            $pathnames = [];
            foreach ($chain as $stepid) {
                if (isset($categories[$stepid])) {
                    $pathnames[] = (string)$categories[$stepid]->name;
                }
            }
            $fullpath = implode(' / ', $pathnames);
            $istimeless = self::is_timeless_container($fullpath);

            if (isset($roots[$id])) {
                $current[] = $id;
                if ($istimeless) {
                    $timelesscats[$id] = true;
                }
                continue;
            }
            $matched = false;
            $count = count($chain);
            for ($i = 0; $i < $count - 1; $i++) {
                $ancestorid = $chain[$i];
                if (!isset($roots[$ancestorid]) || !empty($hasselecteddescendant[$ancestorid])) {
                    continue;
                }
                $blocked = false;
                for ($j = $i + 1; $j < $count; $j++) {
                    $stepid = $chain[$j];
                    if (isset($categories[$stepid]) && self::is_excluded_name((string)$categories[$stepid]->name)) {
                        $blocked = true;
                        break;
                    }
                }
                if (!$blocked) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $current[] = $id;
                if ($istimeless) {
                    $timelesscats[$id] = true;
                }
            }
        }

        if (!$current) {
            return [];
        }

        $refnow = self::reference_timestamp();
        $graceend = $refnow - (14 * DAYSECS);
        [$insql, $params] = $DB->get_in_or_equal($current, SQL_PARAMS_NAMED, 'cat', true);
        $params['siteid'] = SITEID;
        $courses = $DB->get_records_select(
            'course',
            "category $insql AND id <> :siteid",
            $params,
            '',
            'id,category,startdate,enddate'
        );
        $activeids = [];
        foreach ($courses as $course) {
            $catid = (int)$course->category;
            $startdate = (int)$course->startdate;
            $enddate = (int)$course->enddate;
            $iscurrentdate = ($enddate > 0)
                ? ($enddate >= $graceend)
                : ($startdate === 0 || $startdate >= ($refnow - (120 * DAYSECS)));
            if (isset($timelesscats[$catid]) || $iscurrentdate) {
                $activeids[] = (int)$course->id;
            }
        }
        return $activeids;
    }

    /**
     * Resolve the active course IDs (plus SITEID) that high-volume search areas like mod_forum-post should index.
     *
     * Automatically applies inferred active categories if none have been saved yet.
     *
     * @return int[]
     */
    public static function active_indexing_courseids(): array {
        if (!self::configured()) {
            self::apply_inferred_categories(false);
        }
        $ids = self::courseids('current') ?? [];
        $ids[] = (int)SITEID;
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Infer active and in-development categories from course dates, modifications, enrolments, and access.
     *
     * Enforces cohort naming patterns (e.g. YYMM term folders) and course start/end dates so completed
     * cohorts with expired end dates are excluded even if they had historical enrolments, and avoids
     * rolling up parent "Active" containers over distinct cohort child folders.
     *
     * @param int|null $now Optional reference timestamp for deterministic testing.
     * @return \stdClass[] Keyed by selected category ID, with diagnostic counts and reason codes.
     */
    public static function infer_current_categories(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $refnow = self::reference_timestamp($now);
        $graceend = $refnow - (14 * DAYSECS);

        $sql = "SELECT cc.id,
                       cc.name,
                       cc.parent,
                       cc.path,
                       cc.depth,
                       cc.visible,
                       COUNT(c.id) AS coursecount,
                       SUM(CASE WHEN c.startdate > :refup AND c.startdate <= :upstartmax
                                     AND (c.enddate = 0 OR c.enddate >= :graceup) THEN 1 ELSE 0 END) AS upcomingcourses,
                       SUM(CASE WHEN c.startdate > 0 AND c.startdate <= :now1 AND c.startdate >= :recentstart
                                     AND (c.enddate = 0 OR c.enddate >= :graceend1) THEN 1 ELSE 0 END) AS activedatedcourses,
                       SUM(CASE WHEN (c.timecreated >= :recentcreated OR c.timemodified >= :recentmod)
                                     AND (c.enddate = 0 OR c.enddate >= :graceend2)
                                THEN 1 ELSE 0 END) AS recentdevcourses,
                       SUM(CASE WHEN c.enddate > 0 AND c.enddate < :graceend3 THEN 1 ELSE 0 END) AS expiredcourses
                  FROM {course_categories} cc
             LEFT JOIN {course} c ON c.category = cc.id AND c.id <> :siteid
              GROUP BY cc.id, cc.name, cc.parent, cc.path, cc.depth, cc.visible
              ORDER BY cc.path";
        $cats = $DB->get_records_sql($sql, [
            'refup' => $refnow,
            'upstartmax' => max($now, $refnow) + (365 * DAYSECS),
            'graceup' => $graceend,
            'now1' => max($now, $refnow),
            'recentstart' => $refnow - (180 * DAYSECS),
            'graceend1' => $graceend,
            'graceend2' => $graceend,
            'graceend3' => $graceend,
            'recentcreated' => $refnow - (90 * DAYSECS),
            'recentmod' => $refnow - (60 * DAYSECS),
            'siteid' => SITEID,
        ]);
        if (!$cats) {
            return [];
        }

        $enrolsql = "SELECT c.category,
                            COUNT(ue.id) AS activeenrols,
                            SUM(CASE WHEN ue.timecreated >= :recentenrol1 OR ue.timestart >= :recentenrol2
                                     THEN 1 ELSE 0 END) AS recentenrols
                       FROM {course} c
                       JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                       JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.status = 0
                      WHERE c.id <> :siteid
                   GROUP BY c.category";
        $enrols = $DB->get_records_sql($enrolsql, [
            'recentenrol1' => $refnow - (180 * DAYSECS),
            'recentenrol2' => $refnow - (180 * DAYSECS),
            'siteid' => SITEID,
        ]);

        $accesssql = "SELECT c.category,
                             COUNT(ula.id) AS recentaccesses
                        FROM {course} c
                        JOIN {user_lastaccess} ula ON ula.courseid = c.id
                       WHERE c.id <> :siteid
                         AND ula.timeaccess >= :recentaccess
                    GROUP BY c.category";
        $accesses = $DB->get_records_sql($accesssql, [
            'siteid' => SITEID,
            'recentaccess' => $refnow - (90 * DAYSECS),
        ]);

        $children = [];
        $directactive = [];
        $excluded = [];
        $reasons = [];
        $cohortcodebycat = [];
        $maxactivecohortbyparent = [];

        foreach ($cats as $id => $cat) {
            $id = (int)$id;
            $parentid = (int)$cat->parent;
            $children[$parentid][] = $id;

            $pathnames = [];
            foreach (explode('/', trim((string)$cat->path, '/')) as $aid) {
                $aid = (int)$aid;
                if (isset($cats[$aid])) {
                    $pathnames[] = (string)$cats[$aid]->name;
                }
            }
            $cat->fullpath = implode(' / ', $pathnames);
            $excluded[$id] = self::is_excluded_name($cat->fullpath);
            $istimeless = self::is_timeless_container($cat->fullpath);

            $trimmedname = trim((string)$cat->name);
            if (preg_match('/^(2[0-9])(0[1-9]|1[0-2])$/', $trimmedname)) {
                $cohortcodebycat[$id] = (int)$trimmedname;
            }

            $enrol = $enrols[$id] ?? null;
            $access = $accesses[$id] ?? null;
            $cat->coursecount = (int)$cat->coursecount;
            $cat->upcomingcourses = (int)$cat->upcomingcourses;
            $cat->activedatedcourses = (int)$cat->activedatedcourses;
            $cat->recentdevcourses = (int)$cat->recentdevcourses;
            $cat->expiredcourses = (int)($cat->expiredcourses ?? 0);
            $cat->activeenrols = (int)($enrol->activeenrols ?? 0);
            $cat->recentenrols = (int)($enrol->recentenrols ?? 0);
            $cat->recentaccesses = (int)($access->recentaccesses ?? 0);

            // If a non-template category has expired dated courses and zero currently active or upcoming dated courses,
            // it is a completed cohort whose enddate has already passed.
            $alldatedexpired = !$istimeless
                && $cat->expiredcourses > 0
                && $cat->activedatedcourses === 0
                && $cat->upcomingcourses === 0;

            $catreasons = [];
            if (!$excluded[$id] && !$alldatedexpired) {
                if ($cat->upcomingcourses > 0 || $cat->recentdevcourses > 0) {
                    $catreasons[] = 'upcomingdev';
                }
                if ($cat->activedatedcourses > 0 && $cat->activeenrols >= 3) {
                    $catreasons[] = 'activedates';
                }
                if ($cat->recentenrols >= 5 && $cat->activeenrols >= 5) {
                    $catreasons[] = 'recentenrolments';
                }
                if ($cat->recentaccesses >= 5 && $cat->activeenrols >= 5) {
                    $catreasons[] = 'recentaccess';
                }
            }
            $reasons[$id] = $catreasons;
            $directactive[$id] = !empty($catreasons);
            if ($directactive[$id] && isset($cohortcodebycat[$id])) {
                $curmax = $maxactivecohortbyparent[$parentid] ?? 0;
                if ($cohortcodebycat[$id] > $curmax) {
                    $maxactivecohortbyparent[$parentid] = $cohortcodebycat[$id];
                }
            }
        }

        // Include empty pre-launch cohort folders (e.g. 2607 under Active) whose YYMM code is >= active sibling cohorts.
        foreach ($cohortcodebycat as $id => $code) {
            if ($excluded[$id] || $directactive[$id]) {
                continue;
            }
            $cat = $cats[$id];
            $parentid = (int)$cat->parent;
            $maxsibling = $maxactivecohortbyparent[$parentid] ?? 0;
            if ($cat->coursecount === 0 && $maxsibling > 0 && $code >= $maxsibling) {
                $reasons[$id] = ['upcomingdev'];
                $directactive[$id] = true;
            }
        }

        $bydepth = array_keys($cats);
        usort($bydepth, function($a, $b) use ($cats) {
            return (int)$cats[$b]->depth <=> (int)$cats[$a]->depth;
        });

        $subtreeclean = [];
        $subtreehasactive = [];
        $subtreecourses = [];
        foreach ($bydepth as $id) {
            $id = (int)$id;
            $cat = $cats[$id];
            $nonexpiredcount = max(0, $cat->coursecount - (int)$cat->expiredcourses);
            $clean = !$excluded[$id]
                && ((int)$cat->expiredcourses === 0 || self::is_timeless_container($cat->fullpath))
                && ($cat->coursecount === 0 || $directactive[$id]);
            $hasactive = $directactive[$id];
            $totalcourses = self::is_timeless_container($cat->fullpath) ? $cat->coursecount : $nonexpiredcount;
            foreach ($children[$id] ?? [] as $cid) {
                // Never roll up a parent folder if a child is a named cohort folder (e.g. 2604, 2605, 2607).
                if (!$subtreeclean[$cid] || isset($cohortcodebycat[$cid])) {
                    $clean = false;
                }
                if ($subtreehasactive[$cid]) {
                    $hasactive = true;
                }
                $totalcourses += $subtreecourses[$cid];
            }
            $subtreeclean[$id] = $clean;
            $subtreehasactive[$id] = $hasactive;
            $subtreecourses[$id] = $totalcourses;
        }

        $results = [];
        foreach ($cats as $id => $cat) {
            $id = (int)$id;
            $activecnt = self::is_timeless_container($cat->fullpath)
                ? $cat->coursecount
                : max(0, $cat->coursecount - (int)$cat->expiredcourses);
            if ($subtreeclean[$id] && $subtreehasactive[$id]) {
                $pid = (int)$cat->parent;
                if ($pid > 0 && !empty($subtreeclean[$pid]) && !empty($subtreehasactive[$pid])) {
                    continue;
                }
                $entry = clone $cat;
                $entry->coursecount = $activecnt;
                $entry->subtreecoursecount = $subtreecourses[$id];
                $entry->rolledup = !empty($children[$id]);
                $entry->reasons = $reasons[$id] ?: ['rolledup'];
                $results[$id] = $entry;
            } else if ($directactive[$id]) {
                $entry = clone $cat;
                $entry->coursecount = $activecnt;
                $entry->subtreecoursecount = $activecnt;
                $entry->rolledup = false;
                $entry->reasons = $reasons[$id];
                $results[$id] = $entry;
            }
        }
        return pro_bridge::refine_inferred_categories($results, $now);
    }

    /**
     * Return only the category IDs inferred as current.
     *
     * @param int|null $now Optional reference timestamp.
     * @return int[]
     */
    public static function inferred_category_ids(?int $now = null): array {
        return array_map('intval', array_keys(self::infer_current_categories($now)));
    }

    /**
     * Persist inferred categories into plugin configuration.
     *
     * @param bool $onlyifempty When true, do not overwrite an existing non-empty selection.
     * @param int|null $now Optional reference timestamp.
     * @return int[] The active category IDs now stored (or inferred).
     */
    public static function apply_inferred_categories(bool $onlyifempty = false, ?int $now = null): array {
        if ($onlyifempty && self::configured()) {
            return self::roots();
        }
        $ids = self::inferred_category_ids($now);
        set_config('currentcategories', implode(',', $ids), 'search_typesense');
        return $ids;
    }
}
