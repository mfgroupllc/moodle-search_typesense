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
 * PHPUnit tests for search shortcuts.
 *
 * @package    search_typesense
 * @category   test
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

/**
 * Tests for matching searches to site links.
 *
 * @package    search_typesense
 * @category   test
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \search_typesense\shortcuts
 * @covers     \search_typesense\admin\setting_shortcuts
 */
final class shortcuts_test extends \advanced_testcase {
    /**
     * Links used by the matching tests, in menu order.
     *
     * @return array[]
     */
    protected function sample_links(): array {
        return [
            ['label' => 'Home', 'url' => 'https://example.com/', 'keywords' => []],
            ['label' => 'Technique Videos', 'url' => 'https://example.com/course/view.php?id=2', 'keywords' => []],
            ['label' => 'How To Videos', 'url' => 'https://example.com/course/view.php?id=3', 'keywords' => []],
            ['label' => 'Student Portal', 'url' => 'https://portal.example.com/', 'keywords' => ['sis']],
            ['label' => 'Calendar', 'url' => 'https://example.com/calendar/view.php', 'keywords' => []],
            ['label' => 'Grades', 'url' => 'https://example.com/grade/report/mygrades.php', 'keywords' => []],
            ['label' => 'Crème Club', 'url' => 'https://example.com/course/view.php?id=4', 'keywords' => []],
        ];
    }

    /**
     * Labels of the links matched for a search.
     *
     * @param string $query Search text.
     * @param bool $partial Whether extra or missing words are allowed.
     * @return string[]
     */
    protected function labels(string $query, bool $partial): array {
        return array_column(shortcuts::match($query, $this->sample_links(), $partial), 'label');
    }

    /**
     * Searches that name a link find it, whatever the case, accents or a one-letter typo.
     *
     * @return void
     */
    public function test_match_exact(): void {
        $this->resetAfterTest();
        $this->assertSame(['Student Portal'], $this->labels('student portal', false));
        $this->assertSame(['Student Portal'], $this->labels('  STUDENT   Portal ', false));
        $this->assertSame(['Student Portal'], $this->labels('sis', false));
        $this->assertSame(['Grades'], $this->labels('grades', false));
        $this->assertSame(['Calendar'], $this->labels('calender', false));
        $this->assertSame(['Crème Club'], $this->labels('creme club', false));
        $this->assertSame([], $this->labels('99999', true));
        $this->assertSame([], $this->labels('', true));

        // When the index keeps accents for a language, so do shortcuts.
        set_config('locale', 'fr', 'search_typesense');
        $this->assertSame([], $this->labels('creme club', false));
        $this->assertSame(['Crème Club'], $this->labels('crème club', false));
    }

    /**
     * Extra or missing words only match when partial matches are allowed.
     *
     * @return void
     */
    public function test_match_partial(): void {
        $this->resetAfterTest();
        $this->assertSame([], $this->labels('calendar view', false));
        $this->assertSame(['Calendar'], $this->labels('calendar view', true));
        $this->assertSame(['Calendar'], $this->labels('calendar vies', true));
        $this->assertSame([], $this->labels('home cooking', false));
        $this->assertSame([], $this->labels('student', false));
        $this->assertSame(['Student Portal'], $this->labels('student', true));
        $this->assertSame(['Technique Videos', 'How To Videos'], $this->labels('videos', true));
        // Links that share a name show once.
        $links = array_merge($this->sample_links(), [['label' => 'home', 'url' => 'https://example.com/my/']]);
        $this->assertSame(['https://example.com/'], array_column(shortcuts::match('home', $links, false), 'url'));
        // Too many extra words, or a short fragment of a label, do not match.
        $this->assertSame([], $this->labels('sautéing week 1 calendar', true));
        $this->assertSame([], $this->labels('how', true));
    }

    /**
     * Menu URLs are cleaned and links that act rather than navigate are dropped.
     *
     * @return void
     */
    public function test_clean_url(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->assertSame('https://portal.example.com/', shortcuts::clean_url('https://portal.example.com/ " target="_blank'));
        $encoded = 'https://portal.example.com/%20%22%20target=%22_blank';
        $this->assertSame('https://portal.example.com/', shortcuts::clean_url($encoded));
        $this->assertSame(
            $CFG->wwwroot . '/calendar/view.php?view=month&course=1',
            shortcuts::clean_url('/calendar/view.php?view=month&amp;course=1')
        );
        $this->assertSame('', shortcuts::clean_url($CFG->wwwroot . '/login/logout.php?sesskey=abc'));
        $this->assertSame('', shortcuts::clean_url($CFG->wwwroot . '/course/switchrole.php?id=2'));
        $this->assertSame('', shortcuts::clean_url('#'));
        $this->assertSame('', shortcuts::clean_url('javascript:alert(1)'));
        $this->assertSame('', shortcuts::clean_url('ftp://example.com/file'));
    }

    /**
     * The shortcut list setting is parsed and checked line by line.
     *
     * @return void
     */
    public function test_parse_list_and_validate(): void {
        $this->resetAfterTest();
        $this->assertSame([
            ['label' => 'Student Portal', 'url' => 'https://portal.example.com/', 'keywords' => ['sis', 'registration']],
            ['label' => 'Austin Campus', 'url' => 'https://example.com/austin/', 'keywords' => []],
        ], shortcuts::parse_list("Student Portal | https://portal.example.com/ | sis, registration\n\nnot a shortcut\n" .
            "Austin Campus|https://example.com/austin/"));

        $setting = new admin\setting_shortcuts('search_typesense/shortcuts', 'Shortcuts', '', '');
        $this->assertTrue($setting->validate("Student Portal | https://portal.example.com/\n\nGrades | /grade/mygrades.php"));
        $this->assertIsString($setting->validate("Student Portal | https://portal.example.com/\nBroken line\nBad | javascript:x"));
    }

    /**
     * Links come from the menus the user sees and the shortcut list, and only those can be opened.
     *
     * @return void
     */
    public function test_links_and_find_link(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/search/engine/typesense/query.php');
        $PAGE->set_context(\context_system::instance());
        set_config('custommenuitems', "Resources\n-Academic Catalog|https://example.edu/catalog/ \" target=\"_blank\n" .
            "Student Portal | https://portal.example.edu/ \" target=\"_blank");
        set_config(
            'shortcuts',
            "Austin Campus | https://example.edu/austin/ | texas\nStudent Portal | https://portal.example.edu/ | sis",
            'search_typesense'
        );
        $this->setUser($this->getDataGenerator()->create_user());

        $links = shortcuts::links();
        $byurl = array_column($links, null, 'url');
        $this->assertSame('Student Portal', $byurl['https://portal.example.edu/']['label']);
        $this->assertSame(['sis'], $byurl['https://portal.example.edu/']['keywords']);
        $this->assertArrayHasKey('https://example.edu/catalog/', $byurl);
        $this->assertArrayHasKey('https://example.edu/austin/', $byurl);
        // The user menu sends Grades to the user's grade report, wherever the site has it.
        $this->assertContains('Grades', array_column($links, 'label'));
        foreach ($links as $link) {
            $this->assertStringNotContainsString('sesskey', $link['url']);
        }

        $found = shortcuts::find_link(shortcuts::link_id('https://portal.example.edu/'));
        $this->assertSame('https://portal.example.edu/', $found['url']);
        $this->assertNull(shortcuts::find_link(shortcuts::link_id('https://evil.example.com/')));

        $shown = shortcuts::for_query('student portal', false);
        $this->assertCount(1, $shown);
        $this->assertStringContainsString('/search/engine/typesense/go.php', $shown[0]['clickurl']);

        // Without site menu links, only the shortcut list is used.
        set_config('shortcutsnav', 0, 'search_typesense');
        $this->assertEqualsCanonicalizing(
            ['https://example.edu/austin/', 'https://portal.example.edu/'],
            array_column(shortcuts::links(), 'url')
        );
    }
}
