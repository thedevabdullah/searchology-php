<?php

namespace Searchology;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

class Searchology
{
    private string $baseUrl;
    private ?string $apiKey;
    private Client $http;

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
     * Call this once, save the returned key, use it for extract().
     *
     * @param  string $name  A label for this key
     * @return array         ['id', 'key', 'name', 'expires_at', 'created_at']
     * @throws SearchologyException
     */
    public function createApiKey(string $name): array
    {
        if (empty(trim($name))) {
            throw new SearchologyException('name is required');
        }
        if (strlen(trim($name)) > 64) {
            throw new SearchologyException('name must be 64 characters or less');
        }

        return $this->request('POST', '/register', ['name' => trim($name)]);
    }

    /**
     * Extract structured attributes from a natural language query.
     *
     * @param  string $query  Plain English search query. Max 500 chars.
     * @return array          ['query', 'result', 'keys_found', 'latency_ms']
     * @throws SearchologyException
     */
    public function extract(string $query): array
    {
        if (empty($this->apiKey)) {
            throw new SearchologyException(
                'No API key set. Pass api_key in constructor or call createApiKey() first.'
            );
        }
        if (empty(trim($query))) {
            throw new SearchologyException('query must be a non-empty string');
        }
        if (strlen($query) > 500) {
            throw new SearchologyException(
                sprintf('query must be 500 characters or less (got %d)', strlen($query))
            );
        }

        return $this->request('POST', '/extract', ['query' => $query], [
            'Authorization' => 'Bearer ' . $this->apiKey,
        ]);
    }

    // ── private ────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = [], array $headers = []): array
    {
        try {
            $response = $this->http->request($method, $path, [
                'json'    => $body,
                'headers' => $headers,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new SearchologyException('Invalid JSON response from API');
            }

            // auto-set api key after createApiKey so extract() works immediately
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