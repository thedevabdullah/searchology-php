<?php

namespace Searchology;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

class Searchology
{
    private string  $baseUrl;
    private ?string $apiKey;
    private Client  $http;

    private const DEFAULT_BASE_URL = 'https://searchology.duckdns.org';
    private const TIMEOUT          = 30;

    public function __construct(array $config = [])
    {
        $this->apiKey  = $config['api_key']  ?? null;
        $this->baseUrl = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->http    = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => self::TIMEOUT,
            'headers'  => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]);
    }

    /**
     * Create a new API key. No authentication needed.
     *
     * @return array ['message', 'key', 'name', 'expires_in']
     * @throws SearchologyException
     *
     * @example
     * $client = new Searchology();
     * $result = $client->createApiKey('my-laravel-app');
     * $apiKey = $result['key']; // sgy_xxxx — save this
     */
    public function createApiKey(string $name): array
    {
        if (empty(trim($name))) throw new SearchologyException('name is required');
        if (strlen(trim($name)) > 64) throw new SearchologyException('name must be 64 characters or less');

        $result       = $this->request('POST', '/register', ['name' => trim($name)]);
        $this->apiKey = $result['key'];
        return $result;
    }

    /**
     * Get the full built-in schema — all extractable keys with descriptions.
     * No authentication needed.
     *
     * @return array ['total_keys', 'schema']
     * @throws SearchologyException
     *
     * @example
     * $client = new Searchology();
     * $schema = $client->getSchema();
     * echo $schema['total_keys']; // 50+
     */
    public function getSchema(): array
    {
        return $this->request('GET', '/schema');
    }

    /**
     * Check your API key status — expiry, request count, custom schema.
     *
     * @return array ['status', 'name', 'expires_in', 'requests', 'custom_schema']
     * @throws SearchologyException
     *
     * @example
     * $status = $client->getKeyStatus();
     * echo $status['expires_in'];    // "18 days"
     * echo $status['requests'];      // 142
     * echo $status['custom_schema']; // true/false
     */
    public function getKeyStatus(): array
    {
        $this->requireApiKey();
        return $this->request('GET', '/key/status');
    }

    /**
     * Refresh your API key expiry — resets to 30 days from today.
     *
     * @return array ['message', 'expires_in']
     * @throws SearchologyException
     *
     * @example
     * $result = $client->refreshKey();
     * echo $result['expires_in']; // "30 days"
     */
    public function refreshKey(): array
    {
        $this->requireApiKey();
        return $this->request('POST', '/key/refresh');
    }

    /**
     * Save a custom schema against your API key.
     * Keys can be built-in schema keys or completely custom ones. Max 50 keys.
     *
     * @param  array $schema  ['key' => 'description', ...]
     * @return array ['message', 'keys_saved', 'keys']
     * @throws SearchologyException
     *
     * @example
     * $client->saveSchema([
     *   'color'     => 'product color e.g. red, blue, black',
     *   'price_max' => 'maximum price as a number',
     *   'brand'     => 'brand name e.g. nike, apple',
     * ]);
     */
    public function saveSchema(array $schema): array
    {
        $this->requireApiKey();

        if (empty($schema)) {
            throw new SearchologyException('schema cannot be empty');
        }

        return $this->request('POST', '/key/schema', ['schema' => $schema]);
    }

    /**
     * Get your saved custom schema.
     *
     * @return array ['keys_count', 'schema'] or ['custom_schema' => null, 'message']
     * @throws SearchologyException
     *
     * @example
     * $result = $client->getCustomSchema();
     * print_r($result['schema']); // ['color' => '...', 'price_max' => '...']
     */
    public function getCustomSchema(): array
    {
        $this->requireApiKey();
        return $this->request('GET', '/key/schema');
    }

    /**
     * Delete your custom schema — falls back to built-in schema.
     *
     * @return array ['message']
     * @throws SearchologyException
     *
     * @example
     * $client->deleteCustomSchema();
     */
    public function deleteCustomSchema(): array
    {
        $this->requireApiKey();
        return $this->request('DELETE', '/key/schema');
    }

    /**
     * Extract structured attributes from a natural language query.
     *
     * @param  string $query          Plain English search query. Max 500 chars.
     * @param  bool   $useCustomSchema Use your saved custom schema instead of built-in.
     * @return array  ['query', 'result', 'keys_found', 'latency_ms', 'schema_used', 'suggestions?']
     * @throws SearchologyException
     *
     * @example
     * // built-in schema (default)
     * $data = $client->extract('black nike shoes under $80');
     *
     * // custom schema
     * $data = $client->extract('black nike shoes under $80', true);
     *
     * // check for suggestions when nothing found
     * if ($data['keys_found'] === 0 && isset($data['suggestions'])) {
     *     foreach ($data['suggestions'] as $suggestion) {
     *         echo "Try: $suggestion\n";
     *     }
     * }
     */
    public function extract(string $query, bool $useCustomSchema = false): array
    {
        $this->requireApiKey();

        if (empty(trim($query))) throw new SearchologyException('query must be a non-empty string');
        if (strlen($query) > 500) {
            throw new SearchologyException(sprintf('query must be 500 characters or less (got %d)', strlen($query)));
        }

        $path = $useCustomSchema ? '/extract?schema=true' : '/extract';
        return $this->request('POST', $path, ['query' => $query]);
    }

    // ── private ────────────────────────────────────────────────────────────

    private function requireApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw new SearchologyException(
                'No API key set. Pass api_key in constructor or call createApiKey() first.'
            );
        }
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $options = ['headers' => []];

        if (!empty($this->apiKey)) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        if (!empty($body)) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, $path, $options);
            $data     = json_decode((string) $response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SearchologyException('Invalid JSON response from API');
            }

            return $data;

        } catch (ClientException $e) {
            $body   = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
            $status = $e->getResponse()->getStatusCode();
            $msg    = $body['message'] ?? $body['error'] ?? "Request failed with status {$status}";
            $code   = $body['error']   ?? 'unknown_error';
            throw new SearchologyException($msg, $status, $code);

        } catch (ConnectException $e) {
            throw new SearchologyException('Could not connect to Searchology API: ' . $e->getMessage(), 0, 'connection_error');
        }
    }
}