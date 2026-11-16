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
 * Typesense cluster health and telemetry snapshot collector and normalizer.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;


/**
 * Collects and normalizes read-only Typesense Cluster Operations and collection metrics.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server_health {
    /** @var float Warning threshold percentage for RAM and disk usage. */
    public const THRESHOLD_WARNING = 75.0;

    /** @var float Danger threshold percentage for RAM and disk usage. */
    public const THRESHOLD_DANGER = 90.0;

    /**
     * Collect a complete at-a-glance snapshot from a Typesense client.
     *
     * @param client $client Configured Typesense client.
     * @param string $collectionname Configured collection name.
     * @param string $endpoint Display endpoint URL (without credentials).
     * @return array Normalized health snapshot.
     */
    public static function collect(client $client, string $collectionname, string $endpoint = ''): array {
        $health = $client->get_health_status();
        $unreachable = ((int)($health['http_code'] ?? 0) === 0);

        if ($unreachable) {
            return [
                'endpoint' => $endpoint,
                'checked_at' => time(),
                'unreachable' => true,
                'health' => $health,
                'debug' => ['status' => 'unreachable', 'version' => '', 'role' => 'unknown', 'state' => null, 'error' => ''],
                'collection' => [
                    'status' => 'unreachable',
                    'name' => $collectionname,
                    'num_documents' => 0,
                    'fields_count' => 0,
                    'created_at' => 0,
                    'error' => '',
                ],
                'metrics' => ['status' => 'unreachable', 'error' => ''],
                'stats' => ['status' => 'unreachable', 'endpoints' => [], 'error' => ''],
            ];
        }

        $debugraw = $client->get_debug_info();
        $collectionraw = $client->get_collection_status($collectionname);
        $metricsraw = $client->get_metrics();
        $statsraw = $client->get_stats();

        return [
            'endpoint' => $endpoint,
            'checked_at' => time(),
            'unreachable' => false,
            'health' => $health,
            'debug' => self::normalize_debug($debugraw),
            'collection' => self::normalize_collection($collectionraw, $collectionname),
            'metrics' => self::normalize_metrics($metricsraw),
            'stats' => self::normalize_stats($statsraw),
        ];
    }

    /**
     * Normalize a GET /debug response wrapper.
     *
     * @param array $raw Diagnostic response wrapper from client::get_debug_info().
     * @return array
     */
    public static function normalize_debug(array $raw): array {
        $status = (string)($raw['status'] ?? 'error');
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $state = isset($data['state']) && is_numeric($data['state']) ? (int)$data['state'] : null;

        $role = 'unknown';
        if ($state === 1) {
            $role = 'leader';
        } else if ($state === 4) {
            $role = 'follower';
        }

        return [
            'status' => $status,
            'version' => isset($data['version']) ? (string)$data['version'] : '',
            'state' => $state,
            'role' => $role,
            'error' => (string)($raw['error'] ?? ''),
        ];
    }

    /**
     * Normalize a GET /collections/{name} response wrapper.
     *
     * @param array $raw Diagnostic response wrapper from client::get_collection_status().
     * @param string $collectionname Configured collection name.
     * @return array
     */
    public static function normalize_collection(array $raw, string $collectionname): array {
        $status = (string)($raw['status'] ?? 'error');
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        return [
            'status' => $status,
            'name' => isset($data['name']) && $data['name'] !== '' ? (string)$data['name'] : $collectionname,
            'num_documents' => isset($data['num_documents']) && is_numeric($data['num_documents'])
                ? (int)$data['num_documents'] : 0,
            'fields_count' => count($fields),
            'created_at' => isset($data['created_at']) && is_numeric($data['created_at'])
                ? (int)$data['created_at'] : 0,
            'error' => (string)($raw['error'] ?? ''),
        ];
    }

    /**
     * Normalize a GET /metrics.json response wrapper.
     *
     * Typesense encodes many /metrics.json counters as strings in JSON, so all numeric
     * values are parsed defensively via is_numeric().
     *
     * @param array $raw Diagnostic response wrapper from client::get_metrics().
     * @return array
     */
    public static function normalize_metrics(array $raw): array {
        $status = (string)($raw['status'] ?? 'error');
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        if ($status !== 'ok') {
            return [
                'status' => $status,
                'error' => (string)($raw['error'] ?? ''),
            ];
        }

        $cpu = self::extract_float($data, 'system_cpu_active_percentage');
        $memtotal = self::extract_float($data, 'system_memory_total_bytes');
        $memused = self::extract_float($data, 'system_memory_used_bytes');
        $mempct = ($memtotal !== null && $memtotal > 0 && $memused !== null)
            ? round(($memused / $memtotal) * 100, 1) : null;

        $disktotal = self::extract_float($data, 'system_disk_total_bytes');
        $diskused = self::extract_float($data, 'system_disk_used_bytes');
        $diskpct = ($disktotal !== null && $disktotal > 0 && $diskused !== null)
            ? round(($diskused / $disktotal) * 100, 1) : null;

        $tsallocated = self::extract_float($data, 'typesense_memory_allocated_bytes');
        $tsactive = self::extract_float($data, 'typesense_memory_active_bytes');
        $tsresident = self::extract_float($data, 'typesense_memory_resident_bytes');
        $tsfrag = self::extract_float($data, 'typesense_memory_fragmentation_ratio');
        $netrx = self::extract_float($data, 'system_network_received_bytes');
        $nettx = self::extract_float($data, 'system_network_sent_bytes');

        return [
            'status' => 'ok',
            'cpu_pct' => $cpu !== null ? round($cpu, 1) : null,
            'cpu_level' => $cpu !== null ? self::evaluate_usage_level($cpu) : 'ok',
            'mem_total_bytes' => $memtotal,
            'mem_used_bytes' => $memused,
            'mem_pct' => $mempct,
            'mem_level' => $mempct !== null ? self::evaluate_usage_level($mempct) : 'ok',
            'disk_total_bytes' => $disktotal,
            'disk_used_bytes' => $diskused,
            'disk_pct' => $diskpct,
            'disk_level' => $diskpct !== null ? self::evaluate_usage_level($diskpct) : 'ok',
            'ts_allocated_bytes' => $tsallocated,
            'ts_active_bytes' => $tsactive,
            'ts_resident_bytes' => $tsresident,
            'ts_fragmentation_ratio' => $tsfrag !== null ? round($tsfrag, 2) : null,
            'net_rx_bytes' => $netrx,
            'net_tx_bytes' => $nettx,
            'error' => '',
        ];
    }

    /**
     * Normalize a GET /stats.json response wrapper.
     *
     * @param array $raw Diagnostic response wrapper from client::get_stats().
     * @return array
     */
    public static function normalize_stats(array $raw): array {
        $status = (string)($raw['status'] ?? 'error');
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        if ($status !== 'ok') {
            return [
                'status' => $status,
                'endpoints' => [],
                'error' => (string)($raw['error'] ?? ''),
            ];
        }

        $rpsmap = is_array($data['requests_per_second'] ?? null) ? $data['requests_per_second'] : [];
        $latencymap = is_array($data['latency_ms'] ?? null) ? $data['latency_ms'] : [];

        $keys = array_values(array_unique(array_merge(array_keys($rpsmap), array_keys($latencymap))));
        $endpoints = [];
        $sumrps = 0.0;

        foreach ($keys as $ep) {
            $rps = isset($rpsmap[$ep]) && is_numeric($rpsmap[$ep]) ? round((float)$rpsmap[$ep], 2) : 0.0;
            $lat = isset($latencymap[$ep]) && is_numeric($latencymap[$ep]) ? round((float)$latencymap[$ep], 1) : 0.0;
            $sumrps += $rps;
            $endpoints[] = [
                'endpoint' => (string)$ep,
                'rps' => $rps,
                'latency_ms' => $lat,
            ];
        }

        usort($endpoints, static function (array $a, array $b): int {
            $cmp = $b['rps'] <=> $a['rps'];
            return $cmp !== 0 ? $cmp : ($b['latency_ms'] <=> $a['latency_ms']);
        });

        $totalrps = self::extract_float($data, 'total_requests_per_second');
        $overloaded = self::extract_float($data, 'overloaded_requests_per_second');
        $pendingwrites = self::extract_float($data, 'pending_write_batches');

        return [
            'status' => 'ok',
            'total_rps' => $totalrps !== null ? round($totalrps, 2) : round($sumrps, 2),
            'overloaded_rps' => $overloaded !== null ? round($overloaded, 2) : null,
            'pending_write_batches' => $pendingwrites !== null ? (int)$pendingwrites : null,
            'endpoints' => $endpoints,
            'error' => '',
        ];
    }

    /**
     * Map a resource usage percentage to a Bootstrap severity level.
     *
     * @param float $pct Usage percentage (0-100).
     * @return string 'ok'|'warning'|'danger'
     */
    public static function evaluate_usage_level(float $pct): string {
        if ($pct >= self::THRESHOLD_DANGER) {
            return 'danger';
        }
        if ($pct >= self::THRESHOLD_WARNING) {
            return 'warning';
        }
        return 'ok';
    }

    /**
     * Format a byte count into a human-readable string.
     *
     * @param float|null $bytes Byte count.
     * @return string
     */
    public static function format_bytes(?float $bytes): string {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }
        if (function_exists('display_size')) {
            return display_size((int)round($bytes));
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $val = $bytes;
        $idx = 0;
        while ($val >= 1024 && $idx < count($units) - 1) {
            $val /= 1024;
            $idx++;
        }
        return round($val, $idx === 0 ? 0 : 1) . ' ' . $units[$idx];
    }

    /**
     * Extract a numeric float value from an associative array by key.
     *
     * @param array $data Associative payload.
     * @param string $key Key name.
     * @return float|null
     */
    protected static function extract_float(array $data, string $key): ?float {
        if (!array_key_exists($key, $data) || !is_numeric($data[$key])) {
            return null;
        }
        return (float)$data[$key];
    }
}
