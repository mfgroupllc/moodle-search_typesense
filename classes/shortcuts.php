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
 * Search shortcuts to the site links a user already sees.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

/**
 * Matches a search against the site's navigation links and the administrator's shortcut list.
 *
 * Links come from the menus Moodle builds for the current user (primary navigation with the custom menu, the user
 * menu, the support and policy footer links) plus the configured shortcut list, so a shortcut never shows a link
 * the user could not already see.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shortcuts {
    /** @var int Most shortcuts shown for one search. */
    public const MAX_SHOWN = 3;
    /** @var int Seconds a user's link list is reused for. */
    private const CACHE_SECONDS = 600;
    /** @var int Shortest word a one-letter typo is allowed in. */
    private const TYPO_MIN_LENGTH = 5;

    /**
     * Whether shortcuts are switched on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return get_config('search_typesense', 'shortcutsnav') !== '0'
            || trim((string)get_config('search_typesense', 'shortcuts')) !== '';
    }

    /**
     * Shortcuts for a search, best first.
     *
     * @param string $query Search text.
     * @param bool $partial Also match when the search and the label differ by extra words (used when the search found
     *     no content, so a shortcut does not crowd out real results).
     * @return array[] Items with label, url and clickurl.
     */
    public static function for_query(string $query, bool $partial): array {
        if (!self::enabled() || trim($query) === '') {
            return [];
        }
        $out = [];
        foreach (self::match($query, self::links(), $partial) as $item) {
            $item['clickurl'] = (new \moodle_url('/search/engine/typesense/go.php', [
                'q' => \core_text::substr(trim($query), 0, 255),
                'link' => self::link_id($item['url']),
            ]))->out(false);
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Every link the current user can reach through a shortcut, without duplicates.
     *
     * The list is cached per user and language for a few minutes, because building Moodle's menus on a request that
     * has not set up the page theme (such as each InstantSearch keystroke) costs a few hundred milliseconds. The cache
     * key includes the menu and shortcut settings, so changes to them apply at once.
     *
     * @return array[] Items with label, url and keywords.
     */
    public static function links(): array {
        global $CFG, $USER;
        $settings = [
            $CFG->custommenuitems ?? '',
            $CFG->customusermenuitems ?? '',
            $CFG->supportavailability ?? '',
            $CFG->supportpage ?? '',
            $CFG->sitepolicyhandler ?? '',
            get_config('search_typesense', 'shortcutsnav'),
            get_config('search_typesense', 'shortcuts'),
            get_config('search_typesense', 'locale'),
        ];
        $key = (int)($USER->id ?? 0) . '_' . preg_replace('/[^a-zA-Z0-9]/', '', current_language()) . '_' .
            substr(sha1(json_encode($settings)), 0, 16);
        $cache = \cache::make('search_typesense', 'shortcutlinks');
        $cached = $cache->get($key);
        if (is_array($cached) && isset($cached['time'], $cached['links']) && $cached['time'] > time() - self::CACHE_SECONDS) {
            return $cached['links'];
        }
        $links = self::build_links();
        $cache->set($key, ['time' => time(), 'links' => $links]);
        return $links;
    }

    /**
     * Build the link list for the current user.
     *
     * @return array[] Items with label, url and keywords.
     */
    protected static function build_links(): array {
        $links = [];
        if (get_config('search_typesense', 'shortcutsnav') !== '0') {
            foreach (['navigation_links', 'user_menu_links', 'footer_links'] as $source) {
                try {
                    $links = array_merge($links, self::$source());
                } catch (\Throwable $e) {
                    // A theme or plugin that breaks one menu should not break search.
                    debugging('Search shortcuts skipped ' . $source . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
        $links = array_merge($links, self::configured_links());

        $out = [];
        foreach ($links as $link) {
            $url = self::clean_url((string)$link['url']);
            $label = trim(html_entity_decode((string)$link['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url === '' || $label === '') {
                continue;
            }
            $id = self::link_id($url);
            if (isset($out[$id])) {
                $out[$id]['keywords'] = array_values(array_unique(array_merge($out[$id]['keywords'], $link['keywords'] ?? [])));
                continue;
            }
            $out[$id] = ['label' => $label, 'url' => $url, 'keywords' => $link['keywords'] ?? []];
        }
        return array_values($out);
    }

    /**
     * Find the link a shortcut click points at, among the links the current user can reach.
     *
     * @param string $id Link id from the click URL.
     * @return array|null The link, or null when the user cannot reach it.
     */
    public static function find_link(string $id): ?array {
        foreach (self::links() as $link) {
            if (hash_equals(self::link_id($link['url']), $id)) {
                return $link;
            }
        }
        return null;
    }

    /**
     * Short stable id for a link URL.
     *
     * @param string $url Clean link URL.
     * @return string
     */
    public static function link_id(string $url): string {
        return substr(sha1($url), 0, 16);
    }

    /**
     * Rank links against a search.
     *
     * A link matches when every word of its label (or of one of its keyword phrases) is in the search and the search
     * has no other words. With $partial, the search may also hold extra words, or only some of a multi-word label.
     * Accents and case are ignored unless the search index keeps them for a language, and words of five or more
     * letters may differ by one letter.
     *
     * @param string $query Search text.
     * @param array[] $links Items with label, url and keywords.
     * @param bool $partial Whether extra or missing words are allowed.
     * @return array[] Matching items with label and url, best first, at most MAX_SHOWN.
     */
    public static function match(string $query, array $links, bool $partial): array {
        $words = self::words($query);
        if (!$words) {
            return [];
        }
        $scored = [];
        foreach ($links as $order => $link) {
            $best = null;
            foreach (array_merge([$link['label']], $link['keywords'] ?? []) as $phrase) {
                $score = self::score($words, self::words((string)$phrase), $partial);
                if ($score !== null && ($best === null || $score < $best)) {
                    $best = $score;
                }
            }
            if ($best !== null) {
                $scored[] = [$best, $order, ['label' => $link['label'], 'url' => $link['url']]];
            }
        }
        usort($scored, static function ($a, $b) {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });
        // Two menu links with the same name (a site may call both its front page and dashboard "Home") show once.
        $out = [];
        foreach (array_column($scored, 2) as $item) {
            $out[implode(' ', self::words($item['label']))] ??= $item;
        }
        return array_slice(array_values($out), 0, self::MAX_SHOWN);
    }

    /**
     * How far a phrase is from the search: 0 for the same words, more for each extra or missing word.
     *
     * @param string[] $words Search words.
     * @param string[] $phrase Phrase words.
     * @param bool $partial Whether extra or missing words are allowed.
     * @return int|null Null when the phrase does not match.
     */
    protected static function score(array $words, array $phrase, bool $partial): ?int {
        if (!$phrase) {
            return null;
        }
        $unused = $words;
        $matched = '';
        foreach ($phrase as $part) {
            foreach ($unused as $i => $word) {
                if (self::same_word($word, $part)) {
                    unset($unused[$i]);
                    $matched .= $part;
                    continue 2;
                }
            }
        }
        $found = count($words) - count($unused);
        $missing = count($phrase) - $found;
        $extra = count($unused);
        if ($missing === 0 && $extra === 0) {
            return 0;
        }
        if (!$partial || $found === 0) {
            return null;
        }
        // Part of a label only counts when the search is all label words and they are not just a short fragment.
        if ($missing > 0 && ($extra > 0 || \core_text::strlen($matched) < 4)) {
            return null;
        }
        if ($extra > max(1, $found)) {
            return null;
        }
        return $missing + $extra;
    }

    /**
     * Whether two normalised words are the same, allowing one typo in longer words.
     *
     * @param string $a First word.
     * @param string $b Second word.
     * @return bool
     */
    protected static function same_word(string $a, string $b): bool {
        if ($a === $b) {
            return true;
        }
        $la = \core_text::strlen($a);
        $lb = \core_text::strlen($b);
        if (min($la, $lb) < self::TYPO_MIN_LENGTH || abs($la - $lb) > 1) {
            return false;
        }
        // PHP's levenshtein() counts bytes, so typos are only allowed between single-byte words.
        if ($la !== strlen($a) || $lb !== strlen($b)) {
            return false;
        }
        return levenshtein($a, $b) <= 1;
    }

    /**
     * Split text into normalised words.
     *
     * @param string $text Text.
     * @return string[]
     */
    public static function words(string $text): array {
        $text = \core_text::strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (empty(get_config('search_typesense', 'locale')) && class_exists('\\Normalizer')) {
            // Typesense ignores accents unless the index keeps them for a language, so shortcuts do the same.
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $text = (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], 'strlen'));
    }

    /**
     * Turn a menu URL into a clean absolute URL, or '' when it should not be offered.
     *
     * Custom menu lines sometimes carry extra HTML after the URL (for example `" target="_blank`), which is cut off.
     * Links that act rather than navigate (log out, anything with a sesskey, role switching, log in as) are dropped.
     *
     * @param string $url Raw URL.
     * @return string
     */
    public static function clean_url(string $url): string {
        global $CFG;
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = (string)preg_replace('/(\s|"|%22|%20%22).*$/s', '', $url);
        if ($url === '' || $url[0] === '#' || stripos($url, 'javascript:') === 0) {
            return '';
        }
        if ($url[0] === '/') {
            $url = $CFG->wwwroot . $url;
        }
        if (!preg_match('~^https?://~i', $url) || clean_param($url, PARAM_URL) !== $url) {
            return '';
        }
        if (preg_match('~[?&]sesskey=|/login/logout\.php|/course/switchrole\.php|/course/loginas\.php~i', $url)) {
            return '';
        }
        return $url;
    }

    /**
     * Primary navigation and custom menu links, as Moodle builds them for the current user.
     *
     * @return array[]
     */
    protected static function navigation_links(): array {
        global $PAGE;
        // Moodle picks the page's renderer; $OUTPUT is only a placeholder before the page header is printed.
        $menu = (new \core\navigation\output\primary($PAGE))->export_for_template();
        $links = [];
        $walk = static function (array $nodes) use (&$walk, &$links) {
            foreach ($nodes as $node) {
                $node = (array)$node;
                if (!empty($node['divider'])) {
                    continue;
                }
                $url = $node['url'] ?? '';
                $url = $url instanceof \moodle_url ? $url->out(false) : (string)$url;
                if ($url !== '' && isset($node['text'])) {
                    $links[] = ['label' => strip_tags((string)$node['text']), 'url' => $url];
                }
                if (!empty($node['children'])) {
                    $walk((array)$node['children']);
                }
            }
        };
        $walk((array)($menu['mobileprimarynav'] ?? []));
        return $links;
    }

    /**
     * User menu links, as Moodle builds them for the current user.
     *
     * @return array[]
     */
    protected static function user_menu_links(): array {
        global $USER, $PAGE, $CFG;
        if (!isloggedin() || isguestuser()) {
            return [];
        }
        require_once($CFG->dirroot . '/user/lib.php');
        $links = [];
        foreach (user_get_user_navigation_info($USER, $PAGE)->navitems ?? [] as $item) {
            if (($item->itemtype ?? '') !== 'link' || empty($item->url)) {
                continue;
            }
            $url = $item->url instanceof \moodle_url ? $item->url->out(false) : (string)$item->url;
            $links[] = ['label' => strip_tags((string)$item->title), 'url' => $url];
        }
        return $links;
    }

    /**
     * Standard footer links: contact site support and the site policies, when the user would see them.
     *
     * @return array[]
     */
    protected static function footer_links(): array {
        global $CFG;
        $links = [];
        $support = $CFG->supportavailability ?? CONTACT_SUPPORT_DISABLED;
        if (
            $support != CONTACT_SUPPORT_DISABLED &&
                !($support == CONTACT_SUPPORT_AUTHENTICATED && (!isloggedin() || isguestuser()))
        ) {
            $links[] = [
                'label' => get_string('contactsitesupport', 'admin'),
                'url' => !empty($CFG->supportpage) ? $CFG->supportpage : $CFG->wwwroot . '/user/contactsitesupport.php',
            ];
        }
        if (
            ($CFG->sitepolicyhandler ?? '') === 'tool_policy' && class_exists('\\tool_policy\\api') &&
                \tool_policy\api::get_current_versions_ids()
        ) {
            // The footer link carries the current page as its return URL; the shortcut always opens the plain page.
            $links[] = [
                'label' => get_string('userpolicysettings', 'tool_policy'),
                'url' => $CFG->wwwroot . '/admin/tool/policy/viewall.php',
            ];
        }
        return $links;
    }

    /**
     * Links from the administrator's shortcut list.
     *
     * Each line is `label | URL | extra keywords`, with keywords separated by commas. Labels go through the
     * multi-language filter like other site text.
     *
     * @return array[]
     */
    public static function configured_links(): array {
        $links = [];
        foreach (self::parse_list((string)get_config('search_typesense', 'shortcuts')) as $line) {
            $line['label'] = format_string($line['label'], true, ['context' => \context_system::instance()]);
            $links[] = $line;
        }
        return $links;
    }

    /**
     * Parse the shortcut list setting.
     *
     * @param string $text Setting value.
     * @return array[] Items with label, url and keywords.
     */
    public static function parse_list(string $text): array {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $keywords = isset($parts[2]) ? array_values(array_filter(array_map('trim', explode(',', $parts[2])), 'strlen')) : [];
            $out[] = ['label' => $parts[0], 'url' => $parts[1], 'keywords' => $keywords];
        }
        return $out;
    }
}
