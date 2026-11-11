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
 * Role-aware user profile metadata resolver and search indexing helper.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;


/**
 * Resolves configured user profile fields for Typesense indexing and permission-safe display.
 *
 * Indexing stores configured user profile fields in the private Typesense `useridentity`
 * schema field (never in `docdata->description1`), and display evaluates Moodle's role and
 * capability hierarchy (`moodle/site:viewuseridentity`, `moodle/user:viewalldetails`,
 * `moodle/user:viewhiddendetails`, `$CFG->hiddenuserfields`, `maildisplay`, and custom profile
 * field visibility rules) per viewer at render time.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_profile_fields {
    /** @var string Search area identifier for Moodle user profiles. */
    public const USER_AREA_ID = 'core_user-user';

    /** @var string Prefix used for custom profile field identifiers. */
    public const PROFILE_FIELD_PREFIX = 'profile_field_';

    /** @var string Special setting token that inherits Moodle's $CFG->showuseridentity fields. */
    public const FOLLOW_SHOWUSERIDENTITY = '__showuseridentity__';

    /** @var string[] Standard user table fields supported for indexing and display. */
    private const STANDARD_FIELDS = [
        'idnumber',
        'email',
        'username',
        'department',
        'institution',
        'phone1',
        'phone2',
        'city',
        'country',
    ];

    /**
     * Return the admin setting choices for `search_typesense/userprofilefields`.
     *
     * @return array Associative array of field key => human-readable label.
     */
    public static function get_setting_choices(): array {
        global $CFG;
        if (during_initial_install()) {
            return [];
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $choices = [
            self::FOLLOW_SHOWUSERIDENTITY => get_string('userprofilefields_followidentity', 'search_typesense'),
            'idnumber' => get_string('idnumber'),
            'email' => get_string('email'),
            'username' => get_string('username'),
            'department' => get_string('department'),
            'institution' => get_string('institution'),
            'phone1' => get_string('phone1'),
            'phone2' => get_string('phone2'),
            'city' => get_string('city'),
            'country' => get_string('country'),
        ];

        $sysctx = \context_system::instance();
        $customfields = profile_get_custom_fields();
        foreach ($customfields as $field) {
            if (empty($field->shortname)) {
                continue;
            }
            $label = format_string($field->name, true, ['context' => $sysctx]);
            $choices[self::PROFILE_FIELD_PREFIX . $field->shortname] = $label . ' (' . $field->shortname . ')';
        }

        return $choices;
    }

    /**
     * Resolve the deduplicated list of configured profile field identifiers.
     *
     * @param string $plugin Plugin config component name (supports alternate configuration).
     * @return string[]
     */
    public static function get_configured_fields(string $plugin = 'search_typesense'): array {
        global $CFG;

        $raw = get_config($plugin, 'userprofilefields');
        if (($raw === false || $raw === null) && $plugin !== 'search_typesense') {
            $raw = get_config('search_typesense', 'userprofilefields');
        }
        if ($raw === false || $raw === null) {
            // Default to following Moodle's showuseridentity setting.
            $selected = [self::FOLLOW_SHOWUSERIDENTITY];
        } else if ($raw === '') {
            return [];
        } else {
            $selected = array_filter(array_map('trim', explode(',', (string)$raw)));
        }

        $resolved = [];
        foreach ($selected as $item) {
            if ($item === self::FOLLOW_SHOWUSERIDENTITY) {
                $identity = array_filter(array_map('trim', explode(',', (string)($CFG->showuseridentity ?? ''))));
                foreach ($identity as $idfield) {
                    $resolved[$idfield] = $idfield;
                }
                continue;
            }
            if (
                in_array($item, self::STANDARD_FIELDS, true) ||
                    strpos($item, self::PROFILE_FIELD_PREFIX) === 0
            ) {
                $resolved[$item] = $item;
            }
        }

        return array_values($resolved);
    }

    /**
     * Preload and build indexable profile text for a batch of user IDs in bulk (max 2 SQL queries).
     *
     * @param int[] $userids User IDs in the batch.
     * @param string $plugin Engine plugin name.
     * @return array Map of userid => space-separated indexable text.
     */
    public static function get_indexable_text_batch(array $userids, string $plugin = 'search_typesense'): array {
        global $CFG, $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), function ($id) {
            return $id > 0;
        })));
        if (!$userids) {
            return [];
        }
        $fields = self::get_configured_fields($plugin);
        if (!$fields) {
            return [];
        }

        $standardcols = ['id'];
        $customshortnames = [];
        foreach ($fields as $fieldname) {
            if (strpos($fieldname, self::PROFILE_FIELD_PREFIX) === 0) {
                $short = substr($fieldname, strlen(self::PROFILE_FIELD_PREFIX));
                if ($short !== '') {
                    $customshortnames[\core_text::strtolower($short)] = $short;
                }
            } else if (in_array($fieldname, self::STANDARD_FIELDS, true)) {
                $standardcols[$fieldname] = $fieldname;
            }
        }

        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $uparams['deleted'] = 0;
        $users = $DB->get_records_select(
            'user',
            "id $uinsql AND deleted = :deleted",
            $uparams,
            '',
            implode(',', $standardcols)
        );
        if (!$users) {
            return [];
        }

        $customdata = [];
        if ($customshortnames) {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            $allcustom = profile_get_custom_fields();
            $targetfieldids = [];
            $fielddefs = [];
            foreach ($allcustom as $fdef) {
                $lower = \core_text::strtolower((string)$fdef->shortname);
                if (isset($customshortnames[$lower])) {
                    $targetfieldids[] = (int)$fdef->id;
                    $fielddefs[(int)$fdef->id] = $fdef;
                }
            }
            if ($targetfieldids) {
                [$finsql, $fparams] = $DB->get_in_or_equal($targetfieldids, SQL_PARAMS_NAMED, 'fid');
                $sql = "SELECT uid.id, uid.userid, uid.fieldid, uid.data
                          FROM {user_info_data} uid
                         WHERE uid.userid $uinsql
                           AND uid.fieldid $finsql";
                $rows = $DB->get_records_sql($sql, $uparams + $fparams);
                foreach ($rows as $row) {
                    $fdef = $fielddefs[(int)$row->fieldid] ?? null;
                    if (!$fdef) {
                        continue;
                    }
                    $key = \core_text::strtolower(self::PROFILE_FIELD_PREFIX . $fdef->shortname);
                    $customdata[(int)$row->userid][$key] = trim(content_to_text((string)$row->data, false));
                }
            }
        }

        $countries = in_array('country', $fields, true)
            ? get_string_manager()->get_list_of_countries()
            : [];

        $result = [];
        foreach ($users as $uid => $user) {
            $uid = (int)$uid;
            $tokens = [];
            foreach ($fields as $fieldname) {
                if (strpos($fieldname, self::PROFILE_FIELD_PREFIX) === 0) {
                    $lookup = \core_text::strtolower($fieldname);
                    $val = $customdata[$uid][$lookup] ?? '';
                    if ($val !== '') {
                        $tokens[] = $val;
                    }
                } else if (in_array($fieldname, self::STANDARD_FIELDS, true)) {
                    $rawval = trim((string)($user->{$fieldname} ?? ''));
                    if ($rawval === '') {
                        continue;
                    }
                    if ($fieldname === 'country' && !empty($countries[$rawval])) {
                        $tokens[] = $rawval . ' ' . $countries[$rawval];
                    } else {
                        $tokens[] = $rawval;
                    }
                }
            }
            if ($tokens) {
                $result[$uid] = implode(' ', $tokens);
            }
        }

        return $result;
    }

    /**
     * Build the plain-text string of configured profile values for indexing a `core_user-user` document.
     *
     * @param int $userid Target user ID.
     * @param string $plugin Engine plugin name.
     * @return string Space-separated searchable values, or empty string if none apply.
     */
    public static function get_indexable_text(int $userid, string $plugin = 'search_typesense'): string {
        if ($userid <= 0) {
            return '';
        }
        $batch = self::get_indexable_text_batch([$userid], $plugin);
        return $batch[$userid] ?? '';
    }

    /**
     * Whether the current viewer may search the private `useridentity` field in Typesense.
     *
     * Restricting `useridentity` in `query_by` ensures a viewer without identity/detail
     * capabilities cannot use search queries as an oracle to probe hidden ID numbers or emails.
     *
     * @param string $plugin Engine plugin name.
     * @return bool
     */
    public static function can_current_user_query_identity(string $plugin = 'search_typesense'): bool {
        if (!self::get_configured_fields($plugin)) {
            return false;
        }
        $sysctx = \context_system::instance();
        if (
            has_capability('moodle/site:viewuseridentity', $sysctx) ||
                has_capability('moodle/user:viewalldetails', $sysctx) ||
                has_capability('moodle/user:viewhiddendetails', $sysctx)
        ) {
            return true;
        }
        return self::has_identity_capability_in_any_context();
    }

    /**
     * Whether the current viewer is permitted to search the `core_user-user` directory area.
     *
     * When `search_typesense/restrictusersearch` is enabled, only staff/faculty holding
     * `moodle/site:viewuseridentity`, `moodle/user:viewalldetails`, or `moodle/user:viewhiddendetails`
     * at the system, category, or course level can search for user profiles.
     *
     * @param string $plugin Engine plugin name.
     * @return bool
     */
    public static function can_current_user_search_users(string $plugin = 'search_typesense'): bool {
        $restricted = get_config($plugin, 'restrictusersearch');
        if (($restricted === false || $restricted === null) && $plugin !== 'search_typesense') {
            $restricted = get_config('search_typesense', 'restrictusersearch');
        }
        if (empty($restricted)) {
            return true;
        }
        $sysctx = \context_system::instance();
        if (
            has_capability('moodle/site:viewuseridentity', $sysctx) ||
                has_capability('moodle/user:viewalldetails', $sysctx) ||
                has_capability('moodle/user:viewhiddendetails', $sysctx)
        ) {
            return true;
        }
        return self::has_identity_capability_in_any_context();
    }

    /**
     * Filter an array of search area IDs, removing `core_user-user` when restricted for the current viewer.
     *
     * @param string[] $areaids Search area IDs.
     * @param string $plugin Engine plugin name.
     * @return string[]
     */
    public static function filter_allowed_areas(array $areaids, string $plugin = 'search_typesense'): array {
        if (self::can_current_user_search_users($plugin)) {
            return array_values($areaids);
        }
        return array_values(array_filter($areaids, function (string $areaid): bool {
            return $areaid !== self::USER_AREA_ID;
        }));
    }

    /**
     * Resolve the list of profile field badges that the current viewer is permitted to see for a user result.
     *
     * Every configured field is checked against Moodle's role and capability structure for the
     * current `$USER` viewing `$userid`.
     *
     * @param int $userid Target user ID from `core_user-user` document itemid.
     * @param string $plugin Engine plugin name.
     * @return array[] List of ['field' => string, 'label' => string, 'value' => string] items.
     */
    public static function get_visible_fields_for_user(int $userid, string $plugin = 'search_typesense'): array {
        global $CFG, $DB;

        if ($userid <= 0) {
            return [];
        }
        $fields = self::get_configured_fields($plugin);
        if (!$fields) {
            return [];
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            return [];
        }

        $userctx = \context_user::instance($userid, IGNORE_MISSING);
        if (!$userctx) {
            return [];
        }

        $hascustom = false;
        foreach ($fields as $fieldname) {
            if (strpos($fieldname, self::PROFILE_FIELD_PREFIX) === 0) {
                $hascustom = true;
                break;
            }
        }

        $custommap = [];
        if ($hascustom) {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            $custominstances = profile_get_user_fields_with_data($userid);
            foreach ($custominstances as $instance) {
                $key = \core_text::strtolower(self::PROFILE_FIELD_PREFIX . $instance->get_shortname());
                $custommap[$key] = $instance;
            }
        }

        $visible = [];
        foreach ($fields as $fieldname) {
            if (strpos($fieldname, self::PROFILE_FIELD_PREFIX) === 0) {
                $lookup = \core_text::strtolower($fieldname);
                if (!isset($custommap[$lookup])) {
                    continue;
                }
                $fieldobj = $custommap[$lookup];
                if ($fieldobj->is_empty() || !self::can_view_custom_field($fieldobj, $userid, $userctx)) {
                    continue;
                }
                $val = trim(html_to_text($fieldobj->display_data(), 0, false));
                if ($val === '') {
                    continue;
                }
                $visible[] = [
                    'field' => $fieldname,
                    'label' => $fieldobj->display_name(true),
                    'value' => $val,
                ];
            } else if (in_array($fieldname, self::STANDARD_FIELDS, true)) {
                $rawval = trim((string)($user->{$fieldname} ?? ''));
                if ($rawval === '' || !self::can_view_standard_field($fieldname, $user, $userctx)) {
                    continue;
                }
                if ($fieldname === 'country') {
                    $countries = get_string_manager()->get_list_of_countries();
                    $rawval = $countries[$rawval] ?? $rawval;
                }
                $visible[] = [
                    'field' => $fieldname,
                    'label' => \core_user\fields::get_display_name($fieldname),
                    'value' => $rawval,
                ];
            }
        }

        return $visible;
    }

    /**
     * Evaluate whether the current viewer can see a standard user table field for `$user`.
     *
     * Mirrors the capability rules in Moodle core's `user_get_user_details()` and `\core_user\fields`:
     * - Current user or `moodle/user:viewalldetails` (system or user context) can see all fields.
     * - `username` requires current user or `moodle/user:viewalldetails` (or `$CFG->showuseridentity` + identity capability).
     * - `idnumber`, `department`, `institution`, `phone1`, `phone2` require `moodle/site:viewuseridentity`
     *   (in system context or a shared course context) or `moodle/user:viewalldetails`.
     * - `email` is visible when permitted by `moodle/site:viewuseridentity` (and not hidden without
     *   `viewhiddendetails`) OR by the target user's `maildisplay` preference.
     * - `city` and `country` respect `$CFG->hiddenuserfields` and `moodle/user:viewhiddendetails`.
     *
     * @param string $fieldname Standard user field name.
     * @param \stdClass $user Target user record.
     * @param \context_user $userctx Target user context.
     * @return bool
     */
    private static function can_view_standard_field(
        string $fieldname,
        \stdClass $user,
        \context_user $userctx
    ): bool {
        global $CFG, $USER;

        if ((int)$USER->id === (int)$user->id || is_siteadmin()) {
            return true;
        }

        $sysctx = \context_system::instance();
        if (
            has_capability('moodle/user:viewalldetails', $sysctx) ||
                has_capability('moodle/user:viewalldetails', $userctx)
        ) {
            return true;
        }

        $hiddenfields = array_flip(array_filter(array_map('trim', explode(',', (string)($CFG->hiddenuserfields ?? '')))));
        $ishidden = isset($hiddenfields[$fieldname]);
        $canviewhidden = has_capability('moodle/user:viewhiddendetails', $sysctx) ||
            has_capability('moodle/user:viewhiddendetails', $userctx) ||
            self::has_shared_course_capability((int)$user->id, 'moodle/course:viewhiddenuserfields');

        $canviewidentity = has_capability('moodle/site:viewuseridentity', $sysctx) ||
            has_capability('moodle/site:viewuseridentity', $userctx) ||
            self::has_shared_course_capability((int)$user->id, 'moodle/site:viewuseridentity');

        switch ($fieldname) {
            case 'username':
                $showidentity = array_filter(array_map('trim', explode(',', (string)($CFG->showuseridentity ?? ''))));
                return in_array('username', $showidentity, true) && $canviewidentity && (!$ishidden || $canviewhidden);

            case 'idnumber':
            case 'department':
            case 'institution':
            case 'phone1':
            case 'phone2':
                return $canviewidentity && (!$ishidden || $canviewhidden);

            case 'email':
                if ($ishidden && !$canviewhidden) {
                    return false;
                }
                if ($canviewidentity) {
                    return true;
                }
                if ((int)$user->maildisplay === \core_user::MAILDISPLAY_EVERYONE) {
                    return true;
                }
                if (
                    (int)$user->maildisplay === \core_user::MAILDISPLAY_COURSE_MEMBERS_ONLY &&
                        enrol_sharing_course($user, $USER)
                ) {
                    return true;
                }
                return false;

            case 'city':
            case 'country':
                return !$ishidden || $canviewhidden;

            default:
                return false;
        }
    }

    /**
     * Evaluate whether the current viewer can see a custom profile field on `$userid`.
     *
     * Respects Moodle's `PROFILE_VISIBLE_ALL`, `PROFILE_VISIBLE_TEACHERS` (checking both
     * `field->is_visible($userctx)` and `moodle/site:viewuseridentity` in system/shared course
     * contexts), `PROFILE_VISIBLE_PRIVATE`, and `PROFILE_VISIBLE_NONE`.
     *
     * @param \profile_field_base $fieldobj Loaded profile field instance.
     * @param int $userid Target user ID.
     * @param \context_user $userctx Target user context.
     * @return bool
     */
    private static function can_view_custom_field(
        \profile_field_base $fieldobj,
        int $userid,
        \context_user $userctx
    ): bool {
        if ($fieldobj->is_visible($userctx)) {
            return true;
        }
        if ((string)($fieldobj->field->visible ?? '') === (string)PROFILE_VISIBLE_TEACHERS) {
            $sysctx = \context_system::instance();
            if (
                has_capability('moodle/site:viewuseridentity', $sysctx) ||
                    self::has_shared_course_capability($userid, 'moodle/site:viewuseridentity')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether the current viewer holds `$capability` in any course shared with `$targetuserid`.
     *
     * @param int $targetuserid Target user ID.
     * @param string $capability Capability name.
     * @return bool
     */
    private static function has_shared_course_capability(int $targetuserid, string $capability): bool {
        global $USER;
        static $cache = [];
        $viewerid = (int)($USER->id ?? 0);
        $key = $viewerid . ':' . $targetuserid . ':' . $capability;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $courses = enrol_get_all_users_courses($targetuserid, true);
        foreach ($courses as $course) {
            \context_helper::preload_from_record($course);
            $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            if ($coursecontext && has_capability($capability, $coursecontext)) {
                $cache[$key] = true;
                return true;
            }
        }

        $cache[$key] = false;
        return false;
    }

    /**
     * Check whether the current viewer holds `moodle/site:viewuseridentity` in any enrolled or assigned context.
     *
     * @return bool
     */
    private static function has_identity_capability_in_any_context(): bool {
        global $USER;
        static $cached = [];
        $uid = (int)($USER->id ?? 0);
        if (array_key_exists($uid, $cached)) {
            return $cached[$uid];
        }
        if ($uid <= 0 || isguestuser()) {
            $cached[$uid] = false;
            return false;
        }
        $courses = enrol_get_users_courses($uid, true);
        foreach ($courses as $course) {
            \context_helper::preload_from_record($course);
            $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            if (
                $coursecontext && (has_capability('moodle/site:viewuseridentity', $coursecontext) ||
                    has_capability('moodle/user:viewalldetails', $coursecontext) ||
                    has_capability('moodle/user:viewhiddendetails', $coursecontext))
            ) {
                $cached[$uid] = true;
                return true;
            }
        }
        $accessdata = get_user_accessdata($uid);
        foreach (array_keys($accessdata['ra'] ?? []) as $contextpath) {
            $ctxid = (int)preg_replace('~^.*/~', '', (string)$contextpath);
            if ($ctxid <= 0) {
                continue;
            }
            $ctx = \context::instance_by_id($ctxid, IGNORE_MISSING);
            if (
                $ctx && (has_capability('moodle/site:viewuseridentity', $ctx) ||
                    has_capability('moodle/user:viewalldetails', $ctx) ||
                    has_capability('moodle/user:viewhiddendetails', $ctx))
            ) {
                $cached[$uid] = true;
                return true;
            }
        }
        $cached[$uid] = false;
        return false;
    }
}
