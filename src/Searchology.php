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

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => self::TIMEOUT,
            'headers'  => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ]);
    }

    /**
     * Create a new API key. No authentication needed.
     * Call this once, save the returned key, use it for all other methods.
     *
     * Response: ['message', 'key', 'name', 'expires_in']
     *
     * @param  string $name  A label for this key (your app name, etc.)
     * @return array
     * @throws SearchologyException
     *
     * @example
     * $client = new Searchology();
     * $result = $client->createApiKey('my-laravel-app');
     * $apiKey = $result['key'];        // sgy_xxxx — save this
     * $expiry = $result['expires_in']; // "30 days"
     */
    public function createApiKey(string $name): array
    {
        if (empty(trim($name))) {
            throw new SearchologyException('name is required');
        }
        if (strlen(trim($name)) > 64) {
            throw new SearchologyException('name must be 64 characters or less');
        }

        $result = $this->request('POST', '/register', ['name' => trim($name)]);

        // auto-set api key so other methods work immediately
        $this->apiKey = $result['key'];

        return $result;
    }

    /**
     * Check the status of your API key.
     * Returns active status, days remaining, and request count.
     *
     * Response: ['status', 'name', 'expires_in', 'requests']
     *
     * @return array
     * @throws SearchologyException
     *
     * @example
     * $client = new Searchology(['api_key' => 'sgy_xxx']);
     * $status = $client->getKeyStatus();
     * echo $status['expires_in']; // "18 days"
     * echo $status['requests'];   // 142
     * echo $status['status'];     // "active"
     */
    public function getKeyStatus(): array
    {
        $this->requireApiKey();
        return $this->request('GET', '/key/status');
    }

    /**
     * Refresh your API key expiry — resets to 30 days from today.
     * Same key string, same history, just extended expiry.
     *
     * Response: ['message', 'expires_in']
     *
     * @return array
     * @throws SearchologyException
     *
     * @example
     * $client = new Searchology(['api_key' => 'sgy_xxx']);
     * $result = $client->refreshKey();
     * echo $result['expires_in']; // "30 days"
     * echo $result['message'];    // "Key expiry refreshed successfully"
     */
    public function refreshKey(): array
    {
        $this->requireApiKey();
        return $this->request('POST', '/key/refresh');
    }

    /**
     * Extract structured attributes from a natural language query.
     *
     * Response: ['query', 'result', 'keys_found', 'latency_ms']
     * Each result field: ['value', 'confidence']
     *
     * @param  string $query  Plain English search query. Max 500 chars.
     * @return array
     * @throws SearchologyException
     *
     * @example
     * $client = new Searchology(['api_key' => 'sgy_xxx']);
     * $data   = $client->extract('black nike shoes under $80');
     * echo $data['result']['color']['value'];      // 'black'
     * echo $data['result']['color']['confidence']; // 1.0
     */
    public function extract(string $query): array
    {
        $this->requireApiKey();

        if (empty(trim($query))) {
            throw new SearchologyException('query must be a non-empty string');
        }
        if (strlen($query) > 500) {
            throw new SearchologyException(
                sprintf('query must be 500 characters or less (got %d)', strlen($query))
            );
        }

        return $this->request('POST', '/extract', ['query' => $query]);
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

            // auto-set api key after createApiKey
            if ($path === '/register' && isset($data['key'])) {
                $this->apiKey = $data['key'];
            }

            return $data;

        } catch (ClientException $e) {
            $body   = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
            $status = $e->getResponse()->getStatusCode();
            $msg    = $body['message'] ?? $body['error'] ?? "Request failed with status {$status}";
            $code   = $body['error']   ?? 'unknown_error';
            throw new SearchologyException($msg, $status, $code);

        } catch (ConnectException $e) {
            throw new SearchologyException(
                'Could not connect to Searchology API: ' . $e->getMessage(),
                0,
                'connection_error'
            );
        }
    }
}