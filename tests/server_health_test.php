<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace search_typesense;

/**
 * Unit tests for server health diagnostics and response normalization.
 *
 * @package    search_typesense
 * @copyright  2026 Christian Murad <chrismurad@mfgrpllc.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \search_typesense\server_health
 * @covers     \search_typesense\client
 */
final class server_health_test extends \advanced_testcase {
    /**
     * Build a client stub that returns canned request_raw() responses by path.
     *
     * @param array $responses Map of path => raw response array.
     * @return client
     */
    private function make_stub_client(array $responses): client {
        return new class ('typesense.test', 8108, 'http', 'test-api-key', true, $responses) extends client {
            /** @var array */
            private array $responses;

            /** @var array */
            public array $calledpaths = [];

            /**
             * Constructor.
             *
             * @param string $host Typesense host.
             * @param int $port Typesense port.
             * @param string $protocol http|https.
             * @param string $apikey API key.
             * @param bool $verifytls Whether to verify TLS.
             * @param array $responses Canned responses.
             */
            public function __construct(
                string $host,
                int $port,
                string $protocol,
                string $apikey,
                bool $verifytls,
                array $responses
            ) {
                parent::__construct($host, $port, $protocol, $apikey, $verifytls);
                $this->responses = $responses;
            }

            /**
             * Return canned response for the requested endpoint path.
             *
             * @param string $method HTTP method.
             * @param string $path Endpoint path.
             * @param mixed $body Request payload.
             * @param array $query Query parameters.
             * @param int|null $timeout Optional timeout.
             * @return array
             */
            protected function request_raw(
                string $method,
                string $path,
                $body = null,
                array $query = [],
                ?int $timeout = null
            ): array {
                $this->calledpaths[] = $path;
                return $this->responses[$path] ?? [
                    'http_code' => 404,
                    'body' => '{"message":"Not Found"}',
                    'error' => '',
                    'latency_ms' => 1,
                ];
            }
        };
    }

    /**
     * Test collecting a healthy cluster snapshot with string-encoded metrics.
     */
    public function test_collect_healthy_snapshot(): void {
        $client = $this->make_stub_client([
            '/health' => [
                'http_code' => 200,
                'body' => '{"ok":true}',
                'error' => '',
                'latency_ms' => 4,
            ],
            '/debug' => [
                'http_code' => 200,
                'body' => '{"state":1,"version":"30.2"}',
                'error' => '',
                'latency_ms' => 3,
            ],
            '/collections/moodle_docs' => [
                'http_code' => 200,
                'body' => json_encode([
                    'name' => 'moodle_docs',
                    'num_documents' => 1420,
                    'created_at' => 1700000000,
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'title', 'type' => 'string'],
                    ],
                ]),
                'error' => '',
                'latency_ms' => 5,
            ],
            '/metrics.json' => [
                'http_code' => 200,
                'body' => json_encode([
                    'system_cpu_active_percentage' => '18.42',
                    'system_memory_total_bytes' => '8589934592',
                    'system_memory_used_bytes' => '4294967296',
                    'system_disk_total_bytes' => '107374182400',
                    'system_disk_used_bytes' => '21474836480',
                    'typesense_memory_allocated_bytes' => '268435456',
                    'typesense_memory_active_bytes' => '201326592',
                    'typesense_memory_resident_bytes' => '310378496',
                    'typesense_memory_fragmentation_ratio' => '0.25',
                    'system_network_received_bytes' => '1000',
                    'system_network_sent_bytes' => '2000',
                ]),
                'error' => '',
                'latency_ms' => 6,
            ],
            '/stats.json' => [
                'http_code' => 200,
                'body' => json_encode([
                    'requests_per_second' => [
                        'POST /collections/moodle_docs/documents/search' => 4.5,
                        'GET /health' => 0.2,
                    ],
                    'latency_ms' => [
                        'POST /collections/moodle_docs/documents/search' => 7.4,
                        'GET /health' => 1.1,
                    ],
                    'total_requests_per_second' => 4.7,
                    'overloaded_requests_per_second' => 0.0,
                    'pending_write_batches' => 0,
                ]),
                'error' => '',
                'latency_ms' => 4,
            ],
        ]);

        $snapshot = server_health::collect($client, 'moodle_docs', 'http://typesense.test:8108');

        $this->assertFalse($snapshot['unreachable']);
        $this->assertSame('http://typesense.test:8108', $snapshot['endpoint']);

        $this->assertTrue($snapshot['health']['ok']);
        $this->assertSame(200, $snapshot['health']['http_code']);
        $this->assertSame(4, $snapshot['health']['latency_ms']);

        $this->assertSame('ok', $snapshot['debug']['status']);
        $this->assertSame('30.2', $snapshot['debug']['version']);
        $this->assertSame('leader', $snapshot['debug']['role']);

        $this->assertSame('ok', $snapshot['collection']['status']);
        $this->assertSame(1420, $snapshot['collection']['num_documents']);
        $this->assertSame(2, $snapshot['collection']['fields_count']);

        $this->assertSame('ok', $snapshot['metrics']['status']);
        $this->assertSame(18.4, $snapshot['metrics']['cpu_pct']);
        $this->assertSame('ok', $snapshot['metrics']['cpu_level']);
        $this->assertSame(50.0, $snapshot['metrics']['mem_pct']);
        $this->assertSame(20.0, $snapshot['metrics']['disk_pct']);
        $this->assertSame(0.25, $snapshot['metrics']['ts_fragmentation_ratio']);

        $this->assertSame('ok', $snapshot['stats']['status']);
        $this->assertSame(4.7, $snapshot['stats']['total_rps']);
        $this->assertCount(2, $snapshot['stats']['endpoints']);
        $this->assertSame(
            'POST /collections/moodle_docs/documents/search',
            $snapshot['stats']['endpoints'][0]['endpoint']
        );
    }

    /**
     * Test graceful degradation on 403/401 restricted diagnostic endpoints and missing collection.
     */
    public function test_collect_restricted_and_missing_collection(): void {
        $client = $this->make_stub_client([
            '/health' => [
                'http_code' => 200,
                'body' => '{"ok":true}',
                'error' => '',
                'latency_ms' => 2,
            ],
            '/debug' => [
                'http_code' => 403,
                'body' => '{"message":"Forbidden"}',
                'error' => '',
                'latency_ms' => 2,
            ],
            '/collections/moodle_missing' => [
                'http_code' => 404,
                'body' => '{"message":"Not Found"}',
                'error' => '',
                'latency_ms' => 2,
            ],
            '/metrics.json' => [
                'http_code' => 401,
                'body' => '{"message":"Forbidden - a valid x-typesense-api-key header must be sent."}',
                'error' => '',
                'latency_ms' => 2,
            ],
            '/stats.json' => [
                'http_code' => 403,
                'body' => '{"message":"Forbidden"}',
                'error' => '',
                'latency_ms' => 2,
            ],
        ]);

        $snapshot = server_health::collect($client, 'moodle_missing');

        $this->assertFalse($snapshot['unreachable']);
        $this->assertTrue($snapshot['health']['ok']);
        $this->assertSame('restricted', $snapshot['debug']['status']);
        $this->assertSame('notfound', $snapshot['collection']['status']);
        $this->assertSame('restricted', $snapshot['metrics']['status']);
        $this->assertSame('restricted', $snapshot['stats']['status']);
    }

    /**
     * Test HTTP 503 resource error reporting on /health.
     */
    public function test_collect_resource_error_503(): void {
        $client = $this->make_stub_client([
            '/health' => [
                'http_code' => 503,
                'body' => '{"ok":false,"resource_error":"OUT_OF_DISK"}',
                'error' => '',
                'latency_ms' => 5,
            ],
            '/debug' => [
                'http_code' => 200,
                'body' => '{"state":4,"version":"27.1"}',
                'error' => '',
                'latency_ms' => 3,
            ],
        ]);

        $snapshot = server_health::collect($client, 'moodle_docs');

        $this->assertFalse($snapshot['unreachable']);
        $this->assertFalse($snapshot['health']['ok']);
        $this->assertSame(503, $snapshot['health']['http_code']);
        $this->assertSame('OUT_OF_DISK', $snapshot['health']['resource_error']);
        $this->assertSame('follower', $snapshot['debug']['role']);
    }

    /**
     * Test unreachable node short-circuits secondary diagnostic calls.
     */
    public function test_collect_unreachable_short_circuits(): void {
        $client = $this->make_stub_client([
            '/health' => [
                'http_code' => 0,
                'body' => '',
                'error' => 'Failed to connect to typesense.test port 8108',
                'latency_ms' => 2001,
            ],
        ]);

        $snapshot = server_health::collect($client, 'moodle_docs');

        $this->assertTrue($snapshot['unreachable']);
        $this->assertSame(['/health'], $client->calledpaths);
        $this->assertSame('unreachable', $snapshot['debug']['status']);
        $this->assertSame('unreachable', $snapshot['collection']['status']);
        $this->assertSame('unreachable', $snapshot['metrics']['status']);
        $this->assertSame('unreachable', $snapshot['stats']['status']);
    }

    /**
     * Test resource usage threshold classification.
     */
    public function test_evaluate_usage_level_thresholds(): void {
        $this->assertSame('ok', server_health::evaluate_usage_level(0.0));
        $this->assertSame('ok', server_health::evaluate_usage_level(74.9));
        $this->assertSame('warning', server_health::evaluate_usage_level(75.0));
        $this->assertSame('warning', server_health::evaluate_usage_level(89.9));
        $this->assertSame('danger', server_health::evaluate_usage_level(90.0));
        $this->assertSame('danger', server_health::evaluate_usage_level(99.5));
    }

    /**
     * Test byte formatting edge cases.
     */
    public function test_format_bytes(): void {
        $this->assertSame('—', server_health::format_bytes(null));
        $this->assertSame('—', server_health::format_bytes(-1.0));
        $formatted = server_health::format_bytes(1024.0);
        $this->assertNotSame('—', $formatted);
        $this->assertNotEmpty($formatted);
    }
}
