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
 * Unit tests for role-aware user profile metadata indexing and display.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for user_profile_fields capability enforcement and custom profile field visibility.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \search_typesense\user_profile_fields
 */
final class user_profile_fields_test extends \advanced_testcase {
    /**
     * Verify configured fields resolve $CFG->showuseridentity and explicit selections.
     *
     * @return void
     */
    public function test_configured_fields_resolution(): void {
        $this->resetAfterTest();
        set_config('showuseridentity', 'email,idnumber');

        // Default unset config follows showuseridentity.
        $this->assertSame(['email', 'idnumber'], user_profile_fields::get_configured_fields());

        // Explicit selection combines standard and custom fields without duplicates.
        set_config(
            'userprofilefields',
            user_profile_fields::FOLLOW_SHOWUSERIDENTITY . ',idnumber,department,profile_field_program',
            'search_typesense'
        );
        $this->assertSame(
            ['email', 'idnumber', 'department', 'profile_field_program'],
            user_profile_fields::get_configured_fields()
        );

        // Empty selection disables profile field indexing/display.
        set_config('userprofilefields', '', 'search_typesense');
        $this->assertSame([], user_profile_fields::get_configured_fields());
    }

    /**
     * Verify role-based visibility of standard and custom profile fields between students, teachers, and managers.
     *
     * @return void
     */
    public function test_role_based_field_visibility_and_indexing(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        // Create two custom profile fields: one visible to teachers/identity viewers, one visible to everyone.
        $catid = $DB->get_field_sql('SELECT MIN(id) FROM {user_info_category}');
        if (!$catid) {
            $catid = $DB->insert_record('user_info_category', (object)['name' => 'Academic', 'sortorder' => 1]);
        }
        $generator->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'cohortcode',
            'name' => 'Cohort Code',
            'categoryid' => $catid,
            'visible' => PROFILE_VISIBLE_TEACHERS,
        ]);
        $generator->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'campus',
            'name' => 'Campus',
            'categoryid' => $catid,
            'visible' => PROFILE_VISIBLE_ALL,
        ]);

        $target = $generator->create_user([
            'firstname' => 'Alice',
            'lastname' => 'Baker',
            'idnumber' => 'ESC-99421',
            'email' => 'alice.baker@example.edu',
            'department' => 'Pastry Arts',
            'maildisplay' => \core_user::MAILDISPLAY_HIDE,
            'profile_field_cohortcode' => '2605-ONL',
            'profile_field_campus' => 'Boulder',
        ]);
        $peerstudent = $generator->create_user();
        $teacher = $generator->create_user();
        $manager = $generator->create_user();

        $generator->enrol_user($target->id, $course->id, 'student');
        $generator->enrol_user($peerstudent->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $manager->id, \context_system::instance()->id);

        set_config(
            'userprofilefields',
            'idnumber,email,department,profile_field_cohortcode,profile_field_campus',
            'search_typesense'
        );

        // Indexable text contains configured values for Typesense useridentity field.
        $indexable = user_profile_fields::get_indexable_text($target->id);
        $this->assertStringContainsString('ESC-99421', $indexable);
        $this->assertStringContainsString('alice.baker@example.edu', $indexable);
        $this->assertStringContainsString('Pastry Arts', $indexable);
        $this->assertStringContainsString('2605-ONL', $indexable);
        $this->assertStringContainsString('Boulder', $indexable);

        // 1. Peer student: cannot query useridentity and only sees public PROFILE_VISIBLE_ALL custom field ('campus').
        $this->setUser($peerstudent);
        $this->assertFalse(user_profile_fields::can_current_user_query_identity());
        $studentfields = user_profile_fields::get_visible_fields_for_user($target->id);
        $studentkeys = array_column($studentfields, 'field');
        $this->assertSame(['profile_field_campus'], $studentkeys);

        // 2. Course editingteacher: has moodle/site:viewuseridentity in shared course, sees idnumber, email, department, and both custom fields.
        $this->setUser($teacher);
        $this->assertTrue(user_profile_fields::can_current_user_query_identity());
        $teacherfields = user_profile_fields::get_visible_fields_for_user($target->id);
        $teacherkeys = array_column($teacherfields, 'field');
        $this->assertSame(
            ['idnumber', 'email', 'department', 'profile_field_cohortcode', 'profile_field_campus'],
            $teacherkeys
        );

        // 3. System manager: sees all configured fields across the site.
        $this->setUser($manager);
        $this->assertTrue(user_profile_fields::can_current_user_query_identity());
        $managerfields = user_profile_fields::get_visible_fields_for_user($target->id);
        $managerkeys = array_column($managerfields, 'field');
        $this->assertSame(
            ['idnumber', 'email', 'department', 'profile_field_cohortcode', 'profile_field_campus'],
            $managerkeys
        );
    }
}
