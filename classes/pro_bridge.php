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
 * Extension bridge for the optional local_typesensepro add-on.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Dispatches optional extension hooks to local_typesensepro when installed and enabled.
 *
 * When local_typesensepro is absent, every method is a fast no-op returning the original value.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pro_bridge {
    /** @var string Class name of the optional Pro plugin entry point. */
    private const PRO_CLASS = '\\local_typesensepro\\plugin';

    /**
     * Whether the optional local_typesensepro plugin is installed and enabled.
     *
     * @return bool
     */
    public static function is_active(): bool {
        $class = self::PRO_CLASS;
        return class_exists($class) && method_exists($class, 'is_enabled') && $class::is_enabled();
    }

    /**
     * Allow local_typesensepro to append vector/metadata fields to the collection schema.
     *
     * @param array $fields Base Typesense schema fields.
     * @return array
     */
    public static function extend_schema_fields(array $fields): array {
        $class = self::PRO_CLASS;
        if (self::is_active() && method_exists($class, 'extend_schema_fields')) {
            return $class::extend_schema_fields($fields);
        }
        return $fields;
    }

    /**
     * Allow local_typesensepro to enrich a mapped document payload before indexing.
     *
     * @param array $mapped Mapped Typesense document payload.
     * @param \core_search\document $document Source Moodle search document.
     * @return array
     */
    public static function extend_mapped_document(array $mapped, \core_search\document $document): array {
        $class = self::PRO_CLASS;
        if (self::is_active() && method_exists($class, 'extend_mapped_document')) {
            return $class::extend_mapped_document($mapped, $document);
        }
        return $mapped;
    }

    /**
     * Notify local_typesensepro after documents are indexed so async embedding tasks can be queued.
     *
     * @param array $documents Indexed document payloads.
     * @return void
     */
    public static function after_documents_indexed(array $documents): void {
        $class = self::PRO_CLASS;
        if ($documents && self::is_active() && method_exists($class, 'after_documents_indexed')) {
            $class::after_documents_indexed($documents);
        }
    }

    /**
     * Allow local_typesensepro to attach hybrid vector_query parameters to candidate searches.
     *
     * @param array $params Typesense query parameters.
     * @param \stdClass $filters Search filter object.
     * @param array|null $scope Resolved course ID scope.
     * @param array $areas Permitted search area IDs.
     * @return array
     */
    public static function extend_candidate_params(array $params, \stdClass $filters, ?array $scope, array $areas): array {
        $class = self::PRO_CLASS;
        if (self::is_active() && method_exists($class, 'extend_candidate_params')) {
            return $class::extend_candidate_params($params, $filters, $scope, $areas);
        }
        return $params;
    }

    /**
     * Allow local_typesensepro to refine or annotate inferred active course categories.
     *
     * @param array $inferred Map of inferred category objects keyed by category ID.
     * @param int $now Reference timestamp.
     * @return array
     */
    public static function refine_inferred_categories(array $inferred, int $now): array {
        $class = self::PRO_CLASS;
        if (self::is_active() && method_exists($class, 'refine_inferred_categories')) {
            return $class::refine_inferred_categories($inferred, $now);
        }
        return $inferred;
    }

    /**
     * Render optional Pro UI above search results (permission-safe AI overview / historical intent banner).
     *
     * Only already-authorised documents in $results->results are passed to the extension.
     *
     * @param \stdClass|null $data Submitted search filter data.
     * @param string $scope Active scope identifier.
     * @param \stdClass|null $results Authorised search results object.
     * @param \moodle_url $url Current search page URL.
     * @return string HTML fragment or empty string.
     */
    public static function render_search_page_banner(
        ?\stdClass $data,
        string $scope,
        ?\stdClass $results,
        \moodle_url $url
    ): string {
        $class = self::PRO_CLASS;
        if ($data && $results && self::is_active() && method_exists($class, 'render_search_page_banner')) {
            return (string)$class::render_search_page_banner($data, $scope, $results, $url);
        }
        return '';
    }

    /**
     * Record search telemetry for the optional Pro analytics and zero-result insights dashboard.
     *
     * @param \stdClass $data Search filter data.
     * @param string $scope Active scope identifier.
     * @param \stdClass $results Authorised search results object.
     * @param float $elapsed Search execution time in seconds.
     * @return void
     */
    public static function record_search_telemetry(
        \stdClass $data,
        string $scope,
        \stdClass $results,
        float $elapsed
    ): void {
        $class = self::PRO_CLASS;
        if (self::is_active() && method_exists($class, 'record_search_telemetry')) {
            $class::record_search_telemetry($data, $scope, $results, $elapsed);
        }
    }

    /**
     * Allow local_typesensepro to inject settings directly into the Typesense admin settings page.
     *
     * @param \admin_settingpage $settings Typesense settings page instance.
     * @return void
     */
    public static function extend_settings(\admin_settingpage $settings): void {
        $class = self::PRO_CLASS;
        if (class_exists($class) && method_exists($class, 'extend_settings')) {
            $class::extend_settings($settings);
        }
    }

    /**
     * Render optional Pro AI classification controls or summary on infer_categories.php.
     *
     * @param array $inferred Inferred categories map.
     * @return string HTML fragment or empty string.
     */
    public static function render_infer_page_banner(array $inferred): string {
        $class = self::PRO_CLASS;
        if (self::is_active() && method_exists($class, 'render_infer_page_banner')) {
            return (string)$class::render_infer_page_banner($inferred);
        }
        return '';
    }
}
