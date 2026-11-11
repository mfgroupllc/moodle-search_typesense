<?php
// This file is part of Moodle - https://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Thin Typesense HTTP client backed by Moodle's curl wrapper.
 *
 * Targets the Typesense REST API (v0.25+ / v1.0). Kept dependency-free so the
 * plugin stays cleanly GPL and needs no composer/vendored libraries.
 *
 * @package    search_typesense
 * @copyright  2026 Christopher Murad <chrismurad@mfgrpllc.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_typesense;

defined('MOODLE_INTERNAL') || die();

/**
 * Typesense API client.
 */
class client {
    /** @var string $host */
    protected $host;
    /** @var int $port */
    protected $port;
    /** @var string $protocol */
    protected $protocol;
    /** @var string $apikey */
    protected $apikey;
    /** @var \curl $curl */
    protected $curl;
    /** @var bool $verifytls Whether to verify the TLS certificate (off for self-signed). */
    protected $verifytls;

    /**
     * Constructor.
     *
     * @param string $host Typesense host.
     * @param int $port Typesense port.
     * @param string $protocol http|https.
     * @param string $apikey API key.
     * @param bool $verifytls Whether to verify the TLS certificate. Set false for a self-signed cert.
     */
    public function __construct($host, $port, $protocol, $apikey, $verifytls = true) {
        $this->host = $host;
        $this->port = (int)$port;
        $this->protocol = $protocol;
        $this->apikey = $apikey;
        $this->verifytls = (bool)$verifytls;
        // \curl is defined in lib/filelib.php. The web bootstrap loads it, but
        // the CLI bootstrap (e.g. the search indexer) does not, so ensure it is
        // available in both contexts.
        global $CFG;
        if (!class_exists('curl')) {
            require_once($CFG->libdir . '/filelib.php');
        }
        // Typesense is reached over the internal VPC via a private IP
        // (e.g. 10.x), which Moodle's curl security helper blocks by default.
        // ignoresecurity lets our requests through (we still authenticate with
        // the API key and, optionally, TLS-encrypt the channel).
        $this->curl = new \curl(['ignoresecurity' => true]);
        $this->curl->setHeader('X-TYPESENSE-API-KEY: ' . $this->apikey);
        $this->curl->setHeader('Content-Type: application/json');
        $this->curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 2,
            'CURLOPT_TIMEOUT' => 30,
        ]);

        // When talking https to a Typesense instance using a self-signed
        // certificate (the default for this plugin's CloudFormation template),
        // peer/host verification must be disabled or every request fails.
        // Moodle's \curl expects string option names, not PHP constants.
        if ($this->protocol === 'https' && !$this->verifytls) {
            $this->curl->setopt([
                'CURLOPT_SSL_VERIFYPEER' => false,
                'CURLOPT_SSL_VERIFYHOST' => 0,
            ]);
        }
    }

    /**
     * Build the base URL.
     *
     * @return string
     */
    protected function base_url() {
        return $this->protocol . '://' . $this->host . ':' . $this->port;
    }

    /**
     * Perform a request and decode the JSON response, throwing on error.
     *
     * @param string $method HTTP method (get/post/delete/put/patch).
     * @param string $path API path (leading slash).
     * @param mixed $body Request body (array is JSON-encoded, string sent raw).
     * @param array $query Query parameters for GET.
     * @return mixed Decoded JSON (array) or raw string for NDJSON endpoints.
     * @throws \moodle_exception
     */
    protected function request($method, $path, $body = null, array $query = []) {
        $url = $this->base_url() . $path;
        $options = [];
        $data = null;

        if ($body !== null) {
            if (is_array($body)) {
                $data = json_encode($body);
            } else {
                $data = $body; // Already a string (e.g. NDJSON).
            }
        }

        if ($method === 'get') {
            $response = $this->curl->get($url, $query);
        } else if ($method === 'post') {
            $response = $this->curl->post($url, $data, $options);
        } else if ($method === 'put') {
            $response = $this->curl->put($url, $data, $options);
        } else if ($method === 'patch') {
            $response = $this->curl->patch($url, $data, $options);
        } else if ($method === 'delete') {
            $response = $this->curl->delete($url, $data, $options);
        } else {
            throw new \moodle_exception('typesense:unsupportedmethod', 'search_typesense', '', $method);
        }

        $info = $this->curl->get_info();
        $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        if ($httpcode >= 400) {
            $this->throw_error($httpcode, $response);
        }
        return $response;
    }

    /**
     * Parse a Typesense error response and throw.
     *
     * @param int $httpcode
     * @param string $response
     * @throws \moodle_exception
     */
    protected function throw_error($httpcode, $response) {
        $message = $response;
        if ($decoded = json_decode($response, true)) {
            if (isset($decoded['error'])) {
                $message = $decoded['error'];
            }
        }
        throw new \moodle_exception('typesense:servererror', 'search_typesense', '', $message, "HTTP $httpcode");
    }

    /**
     * Check server health.
     *
     * @return bool
     */
    public function health() {
        try {
            $response = $this->request('get', '/health');
            $decoded = json_decode($response, true);
            return (isset($decoded['ok']) && $decoded['ok'] == 1);
        } catch (\moodle_exception $e) {
            return false;
        }
    }

    /**
     * List collection names.
     *
     * @return array
     */
    public function list_collections() {
        $response = $this->request('get', '/collections');
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [];
        }
        $names = [];
        foreach ($decoded as $coll) {
            $names[] = $coll['name'];
        }
        return $names;
    }

    /**
     * Does a collection exist?
     *
     * @param string $name
     * @return bool
     */
    public function collection_exists($name) {
        return in_array($name, $this->list_collections(), true);
    }

    /**
     * Create a collection.
     *
     * @param string $name
     * @param array $fields Field definitions.
     * @param string $defaultsortingfield
     * @return mixed
     */
    public function create_collection($name, array $fields, $defaultsortingfield = 'modified') {
        $body = [
            'name' => $name,
            'fields' => $fields,
            'default_sorting_field' => $defaultsortingfield,
            'token_separators' => ['_', '-', '/', '.'],
        ];
        return json_decode($this->request('post', '/collections', $body), true);
    }

    /**
     * Delete a collection.
     *
     * @param string $name
     */
    public function delete_collection($name) {
        $this->request('delete', '/collections/' . rawurlencode($name));
    }

    /**
     * Retrieve a collection's schema definition.
     *
     * @param string $name Collection name.
     * @return array
     */
    public function get_collection(string $name): array {
        $response = $this->request('get', '/collections/' . rawurlencode($name));
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Update a collection schema (e.g. adding new optional fields).
     *
     * @param string $name Collection name.
     * @param array $fields Field additions/drops.
     * @return array
     */
    public function update_collection(string $name, array $fields): array {
        $body = ['fields' => $fields];
        $response = $this->request('patch', '/collections/' . rawurlencode($name), $body);
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Add or update a single document.
     *
     * Uses Typesense's upsert action so that re-indexing (or incremental
     * updates of an existing document id) replaces the document instead of
     * failing with a 409 "already exists" conflict. get_info() does not report
     * a body on the redirect-free upsert, so we send as a plain POST.
     *
     * @param string $collection
     * @param array $document
     * @return mixed
     */
    public function add_document($collection, array $document) {
        $path = '/collections/' . rawurlencode($collection) . '/documents?action=upsert';
        return json_decode($this->request('post', $path, $document), true);
    }

    /**
     * Bulk import documents (upsert/update). Returns per-line results.
     *
     * @param string $collection
     * @param array $documents
     * @param string $action Import action ('upsert', 'update', 'create').
     * @return array
     */
    public function import_documents($collection, array $documents, string $action = 'upsert') {
        $path = '/collections/' . rawurlencode($collection) . '/documents/import?action=' . rawurlencode($action);
        $ndjson = '';
        foreach ($documents as $doc) {
            $ndjson .= json_encode($doc) . "\n";
        }
        $response = $this->request('post', $path, $ndjson);
        $results = [];
        foreach (explode("\n", trim($response)) as $line) {
            if ($line === '') {
                continue;
            }
            $results[] = json_decode($line, true);
        }
        return $results;
    }

    /**
     * Delete a single document by id.
     *
     * @param string $collection
     * @param string $id
     */
    public function delete_document($collection, $id) {
        $path = '/collections/' . rawurlencode($collection) . '/documents/' . rawurlencode($id);
        $this->request('delete', $path);
    }

    /**
     * Delete documents matching a filter_by expression (best-effort).
     *
     * Uses the delete-by-query endpoint when available, otherwise falls back
     * to searching for ids and deleting them one by one.
     *
     * @param string $collection
     * @param string $filterby
     */
    public function delete_by_filter($collection, $filterby) {
        $path = '/collections/' . rawurlencode($collection) . '/documents';
        try {
            // Supported in recent Typesense versions.
            $this->request('delete', $path, null, ['filter_by' => $filterby]);
            return;
        } catch (\moodle_exception $e) {
            unset($e);
        }
        $results = $this->search($collection, [
            'q' => '*',
            'query_by' => 'title',
            'filter_by' => $filterby,
            'per_page' => 250,
            'page' => 1,
        ]);
        if (empty($results['hits'])) {
            return;
        }
        foreach ($results['hits'] as $hit) {
            $this->delete_document($collection, $hit['document']['id']);
        }
    }

    /**
     * Search a collection.
     *
     * @param string $collection
     * @param array $params Typesense search parameters.
     * @return array
     */
    public function search($collection, array $params) {
        // Put search parameters in the POST body. Moodle can generate a long
        // filter_by expression for users with access to many contexts, which
        // can exceed Typesense's 4,000 character query-string limit when sent
        // to /collections/:collection/documents/search as a GET request.
        $body = [
            'searches' => [array_merge(['collection' => $collection], $params)],
        ];
        $response = $this->request('post', '/multi_search', $body);
        $decoded = json_decode($response, true);
        return $decoded['results'][0] ?? [];
    }
}
