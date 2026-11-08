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
     * Whether a category path or name represents an explicit archive or trash folder.
     *
     * @param string $pathorname Category name or slash-separated path of names.
     * @return bool
     */
    public static function is_excluded_name(string $pathorname): bool {
        return (bool)preg_match(self::EXCLUDED_NAME_REGEX, $pathorname);
    }

    /**
     * Resolve a scope using current DB categories, including descendants of unsplit roots.
     *
     * Null means unrestricted; an empty array means no courses, never all courses.
     * If a selected parent category also has specific descendants selected (for example,
     * a program category with direct courses plus an "Active" child category alongside an
     * unselected "Archived" child category), the parent matches its direct courses and only
     * the selected descendants expand to their subtrees.
     *
     * @param string $scope Scope identifier ('current', 'historical', or 'all').
     * @return int[]|null
     */
    public static function courseids(string $scope): ?array {
        global $DB;
        if ($scope === 'all') {
            return null;
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
        foreach ($categories as $category) {
            $id = (int)$category->id;
            if (isset($roots[$id])) {
                $current[] = $id;
                continue;
            }
            $chain = array_map('intval', explode('/', trim((string)$category->path, '/')));
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
            }
        }

        if (!$current) {
            if ($scope === 'current') {
                return [];
            }
            return array_map('intval', array_keys(
                $DB->get_records_select('course', "id <> :siteid", ['siteid' => SITEID], '', 'id')
            ));
        }
        [$insql, $params] = $DB->get_in_or_equal($current, SQL_PARAMS_NAMED, 'cat', $scope === 'current');
        $params['siteid'] = SITEID;
        return array_map('intval', array_keys($DB->get_records_select(
            'course',
            "category $insql AND id <> :siteid",
            $params,
            '',
            'id'
        )));
    }

    /**
     * Infer active and in-development categories from course dates, modifications, enrolments, and access.
     *
     * Includes categories with upcoming or recently created/modified courses even when they have
     * zero enrolments so course designers can locate courses being built before launch.
     * Applies leaf-safe rollup so clean parent containers (such as an "Active" or "Primary Templates"
     * folder) are selected as a single root without pulling in sibling archive trees.
     *
     * @param int|null $now Optional reference timestamp for deterministic testing.
     * @return \stdClass[] Keyed by selected category ID, with diagnostic counts and reason codes.
     */
    public static function infer_current_categories(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $refnow = $now;

        $maxenrol = (int)$DB->get_field_sql(
            "SELECT MAX( CASE WHEN timecreated >= timestart THEN timecreated ELSE timestart END )
               FROM {user_enrolments}
              WHERE status = 0"
        );
        $maxmod = (int)$DB->get_field_sql(
            "SELECT MAX(timemodified)
               FROM {course}
              WHERE id <> :siteid",
            ['siteid' => SITEID]
        );
        if ($maxenrol > 0 || $maxmod > 0) {
            $latest = max($maxenrol, $maxmod);
            if ($latest > 0 && $latest < $now - (30 * DAYSECS)) {
                $refnow = $latest;
            }
        }

        $sql = "SELECT cc.id,
                       cc.name,
                       cc.parent,
                       cc.path,
                       cc.depth,
                       cc.visible,
                       COUNT(c.id) AS coursecount,
                       SUM(CASE WHEN c.startdate >= :upstartmin AND c.startdate <= :upstartmax THEN 1 ELSE 0 END) AS upcomingcourses,
                       SUM(CASE WHEN c.startdate > 0 AND c.startdate <= :now1 AND c.startdate >= :recentstart
                                     AND (c.enddate = 0 OR c.enddate >= :graceend1) THEN 1 ELSE 0 END) AS activedatedcourses,
                       SUM(CASE WHEN c.timecreated >= :recentcreated
                                     OR (c.timemodified >= :recentmod AND (c.enddate = 0 OR c.enddate >= :graceend2))
                                THEN 1 ELSE 0 END) AS recentdevcourses
                  FROM {course_categories} cc
             LEFT JOIN {course} c ON c.category = cc.id AND c.id <> :siteid
              GROUP BY cc.id, cc.name, cc.parent, cc.path, cc.depth, cc.visible
              ORDER BY cc.path";
        $cats = $DB->get_records_sql($sql, [
            'upstartmin' => $refnow - (14 * DAYSECS),
            'upstartmax' => max($now, $refnow) + (365 * DAYSECS),
            'now1' => max($now, $refnow),
            'recentstart' => $refnow - (180 * DAYSECS),
            'graceend1' => $refnow - (30 * DAYSECS),
            'graceend2' => $refnow - (30 * DAYSECS),
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

            $enrol = $enrols[$id] ?? null;
            $access = $accesses[$id] ?? null;
            $cat->coursecount = (int)$cat->coursecount;
            $cat->upcomingcourses = (int)$cat->upcomingcourses;
            $cat->activedatedcourses = (int)$cat->activedatedcourses;
            $cat->recentdevcourses = (int)$cat->recentdevcourses;
            $cat->activeenrols = (int)($enrol->activeenrols ?? 0);
            $cat->recentenrols = (int)($enrol->recentenrols ?? 0);
            $cat->recentaccesses = (int)($access->recentaccesses ?? 0);

            $catreasons = [];
            if (!$excluded[$id]) {
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
            $clean = !$excluded[$id] && ($cat->coursecount === 0 || $directactive[$id]);
            $hasactive = $directactive[$id];
            $totalcourses = $cat->coursecount;
            foreach ($children[$id] ?? [] as $cid) {
                if (!$subtreeclean[$cid]) {
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
            if ($subtreeclean[$id] && $subtreehasactive[$id]) {
                $pid = (int)$cat->parent;
                if ($pid > 0 && !empty($subtreeclean[$pid]) && !empty($subtreehasactive[$pid])) {
                    continue;
                }
                $entry = clone $cat;
                $entry->subtreecoursecount = $subtreecourses[$id];
                $entry->rolledup = !empty($children[$id]);
                $entry->reasons = $reasons[$id] ?: ['rolledup'];
                $results[$id] = $entry;
            } else if ($directactive[$id]) {
                $entry = clone $cat;
                $entry->subtreecoursecount = $cat->coursecount;
                $entry->rolledup = false;
                $entry->reasons = $reasons[$id];
                $results[$id] = $entry;
            }
        }
        return $results;
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
