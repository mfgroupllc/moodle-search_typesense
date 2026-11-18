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
 * Tests for candidate search backend filters.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

/**
 * Unit tests for the course restriction clause and the site course split.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \search_typesense\candidate_engine
 */
final class candidate_engine_test extends \advanced_testcase {
    /**
     * Call a protected candidate_engine method.
     *
     * @param string $method Method name.
     * @param array $args Arguments.
     * @return mixed
     */
    protected function call(string $method, array $args) {
        $engine = new candidate_engine();
        $reflection = new \ReflectionMethod($engine, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($engine, $args);
    }

    /**
     * The scope and the accessible courses are sent as one sorted, intersected list.
     *
     * @return void
     */
    public function test_course_clause(): void {
        $this->resetAfterTest();
        $this->assertSame('', $this->call('course_clause', [null, (object)[]]));
        $this->assertSame('courseid:[3,7,9]', $this->call('course_clause', [[9, 3, 7, 3], (object)[]]));
        $this->assertSame('courseid:[2,5]', $this->call('course_clause', [null, (object)['candidatecourseids' => [5, 2]]]));
        $this->assertSame(
            'courseid:[3,9]',
            $this->call('course_clause', [[1, 3, 7, 9], (object)['candidatecourseids' => [9, 3, 12]]])
        );
        $this->assertSame('courseid:[-1]', $this->call('course_clause', [[1, 3], (object)['candidatecourseids' => [4]]]));
    }

    /**
     * A course list mixing the site course with other courses is split into two searches without paging.
     *
     * @return void
     */
    public function test_split_site_course(): void {
        $this->resetAfterTest();
        $site = (int)SITEID;
        $params = [
            'q' => 'forum',
            'page' => 2,
            'per_page' => 50,
            'filter_by' => 'owneruserid:0 && courseid:[' . $site . ',4,8] && modified:>=10',
        ];
        $searches = $this->call('split_site_course', [$params]);
        $this->assertCount(2, $searches);
        $this->assertSame('owneruserid:0 && courseid:[4,8] && modified:>=10', $searches[0]['filter_by']);
        $this->assertSame('owneruserid:0 && courseid:' . $site . ' && modified:>=10', $searches[1]['filter_by']);
        $this->assertArrayNotHasKey('page', $searches[0]);
        $this->assertArrayNotHasKey('per_page', $searches[1]);
        $this->assertSame('forum', $searches[1]['q']);

        $this->assertSame([], $this->call('split_site_course', [['filter_by' => 'courseid:[4,8]']]));
        $this->assertSame([], $this->call('split_site_course', [['filter_by' => 'courseid:[' . $site . ']']]));
        $this->assertSame([], $this->call('split_site_course', [['filter_by' => 'owneruserid:0']]));
    }
}
