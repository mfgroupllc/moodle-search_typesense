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
 * Tests for explicit and inferred course category scopes.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for course_scope resolution and active-category inference.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \search_typesense\course_scope
 */
final class course_scope_test extends \advanced_testcase {
    /**
     * Verify unconfigured search defaults to everything.
     *
     * @return void
     */
    public function test_unconfigured_search_defaults_to_everything(): void {
        $this->resetAfterTest();
        $this->assertFalse(course_scope::configured());
        $this->assertSame('all', course_scope::default_scope());
        $this->assertNull(course_scope::courseids('all'));
        $this->assertSame([], course_scope::courseids('current'));
    }

    /**
     * Verify descendants and historical complement reflect live category membership.
     *
     * @return void
     */
    public function test_descendants_and_complement_use_live_category_membership(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $child = $generator->create_category(['parent' => $root->id]);
        $other = $generator->create_category();
        $a = $generator->create_course(['category' => $root->id]);
        $b = $generator->create_course(['category' => $child->id]);
        $c = $generator->create_course(['category' => $other->id]);
        set_config('currentcategories', $root->id, 'search_typesense');
        $this->assertSame('current', course_scope::default_scope());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], course_scope::courseids('current'));
        $this->assertContains((int)$c->id, course_scope::courseids('historical'));
        $this->assertNotContains((int)SITEID, course_scope::courseids('historical'));
        $this->assertNotContains((int)$b->id, course_scope::courseids('historical'));
        $DB->set_field('course', 'category', $other->id, ['id' => $b->id]);
        $this->assertEqualsCanonicalizing([$a->id], course_scope::courseids('current'));
        $this->assertContains((int)$b->id, course_scope::courseids('historical'));
    }

    /**
     * Verify a deleted category ID does not expand current scope to all courses.
     *
     * @return void
     */
    public function test_deleted_selection_does_not_expand_current_scope(): void {
        $this->resetAfterTest();
        set_config('currentcategories', '999999999', 'search_typesense');
        $this->assertSame([], course_scope::courseids('current'));
    }

    /**
     * Verify inference includes zero-enrolment upcoming/in-development courses and excludes archives.
     *
     * @return void
     */
    public function test_inference_includes_upcoming_dev_and_excludes_archives(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $generator = $this->getDataGenerator();

        $program = $generator->create_category(['name' => 'Online Program']);
        $active = $generator->create_category(['name' => 'Active', 'parent' => $program->id]);
        $termcurrent = $generator->create_category(['name' => '2605', 'parent' => $active->id]);
        $termupcoming = $generator->create_category(['name' => '2607', 'parent' => $active->id]);
        $archived = $generator->create_category(['name' => 'Archived', 'parent' => $program->id]);
        $deleteme = $generator->create_category(['name' => 'Delete Me']);

        // Upcoming in-development course with ZERO enrolments.
        $devcourse = $generator->create_course([
            'category' => $termupcoming->id,
            'startdate' => $now + (14 * DAYSECS),
        ]);
        // Recently modified course shell in current term.
        $curcourse = $generator->create_course([
            'category' => $termcurrent->id,
            'startdate' => $now - (7 * DAYSECS),
        ]);
        // Old archived course and trash course.
        $oldcourse = $generator->create_course([
            'category' => $archived->id,
            'startdate' => $now - (800 * DAYSECS),
            'enddate' => $now - (700 * DAYSECS),
        ]);
        $DB->set_field('course', 'timecreated', $now - (800 * DAYSECS), ['id' => $oldcourse->id]);
        $DB->set_field('course', 'timemodified', $now - (700 * DAYSECS), ['id' => $oldcourse->id]);

        $trashcourse = $generator->create_course([
            'category' => $deleteme->id,
            'startdate' => $now + (7 * DAYSECS),
        ]);

        $ids = course_scope::apply_inferred_categories(false, $now);
        // Active container rolls up both 2605 and 2607; Archived and Delete Me are excluded.
        $this->assertContains((int)$active->id, $ids);
        $this->assertNotContains((int)$archived->id, $ids);
        $this->assertNotContains((int)$deleteme->id, $ids);

        $currentcourses = course_scope::courseids('current');
        $this->assertContains((int)$devcourse->id, $currentcourses);
        $this->assertContains((int)$curcourse->id, $currentcourses);
        $this->assertNotContains((int)$oldcourse->id, $currentcourses);
        $this->assertNotContains((int)$trashcourse->id, $currentcourses);
    }
}
