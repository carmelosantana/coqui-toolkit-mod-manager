<?php

declare(strict_types=1);

namespace CoquiBot\SpaceManager\Api;

use CoquiBot\SpaceManager\Config\SpaceRegistry;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for the Coqui Space REST API.
 *
 * Uses closure-based URL and token resolution for hot-reload compatibility —
 * CredentialTool::set() → putenv() takes effect on the next API call.
 */
final class SpaceClient
{
    private const int TIMEOUT = 30;

    private const int MAX_ERROR_LENGTH = 500;

    /** @var \Closure(): string */
    private \Closure $urlResolver;

    /** @var \Closure(): string */
    private \Closure $tokenResolver;

    /**
     * @param \Closure(): string $urlResolver   Returns the API base URL
     * @param \Closure(): string $tokenResolver Returns the bearer token (empty = anonymous)
     */
    public function __construct(
        \Closure $urlResolver,
        \Closure $tokenResolver,
        private readonly HttpClientInterface $http,
    ) {
        $this->urlResolver = $urlResolver;
        $this->tokenResolver = $tokenResolver;
    }

    // ── Skills ───────────────────────────────────────────────────────

    /**
     * Search skills by keyword.
     *
     * @return array<string, mixed>
     */
    public function searchSkills(string $query, int $limit = 10, ?string $cursor = null): array
    {
        $params = ['q' => $query, 'limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/search', $params);
    }

    /**
     * List published skills with sorting and filtering.
     *
     * @return array<string, mixed>
     */
    public function listSkills(
        string $sort = 'updated',
        ?string $tags = null,
        int $limit = 20,
        ?string $cursor = null,
    ): array {
        $params = ['sort' => $sort, 'limit' => $limit];
        if ($tags !== null) {
            $params['tags'] = $tags;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/skills', $params);
    }

    /**
     * Get full details for a skill.
     *
     * @return array<string, mixed>
     */
    public function skillDetails(string $owner, string $name): array
    {
        return $this->get("/skills/{$owner}/{$name}");
    }

    /**
     * Get version history for a skill.
     *
     * @return array<string, mixed>
     */
    public function skillVersions(string $owner, string $name, int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/skills/{$owner}/{$name}/versions", $params);
    }

    /**
     * Get reviews for a skill.
     *
     * @return array<string, mixed>
     */
    public function skillReviews(string $owner, string $name, int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/skills/{$owner}/{$name}/reviews", $params);
    }

    /**
     * Get the raw SKILL.md content for a skill.
     */
    public function skillFile(string $owner, string $name): string
    {
        $url = $this->baseUrl() . "/skills/{$owner}/{$name}/file";

        $response = $this->http->request('GET', $url, [
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(false),
        ]);

        return $response->getContent();
    }

    /**
     * Download a skill as a ZIP archive.
     *
     * @return array{bytes: string, filename: string}
     */
    public function downloadSkill(string $owner, string $name, ?string $version = null): array
    {
        $params = ['username' => $owner, 'name' => $name];
        if ($version !== null) {
            $params['version'] = $version;
        }

        $url = $this->baseUrl() . '/download?' . http_build_query($params);

        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => array_merge($this->headers(false), [
                    'Accept' => 'application/zip',
                ]),
            ]);

            $bytes = $response->getContent();

            // Parse filename from Content-Disposition header
            $disposition = $response->getHeaders()['content-disposition'][0] ?? '';
            $filename = $this->parseFilename($disposition) ?? "{$name}.zip";

            return ['bytes' => $bytes, 'filename' => $filename];
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'Failed to download skill %s/%s: HTTP %d — %s',
                $owner,
                $name,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * Create a new skill on Coqui Space.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createSkill(array $data): array
    {
        return $this->post('/skills', $data);
    }

    /**
     * Update an existing skill.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateSkill(string $owner, string $name, array $data): array
    {
        return $this->put("/skills/{$owner}/{$name}", $data);
    }

    // ── Toolkits ─────────────────────────────────────────────────────

    /**
     * List published toolkits with cursor-based pagination.
     *
     * @return array<string, mixed>
     */
    public function listToolkits(
        string $sort = 'downloads',
        ?string $tags = null,
        int $limit = 20,
        ?string $cursor = null,
    ): array {
        $params = ['sort' => $sort, 'limit' => $limit];
        if ($tags !== null) {
            $params['tags'] = $tags;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/toolkits', $params);
    }

    /**
     * Search toolkits (Packagist-style endpoint).
     *
     * @return array<string, mixed>
     */
    public function searchToolkits(string $query, int $perPage = 15, int $page = 1): array
    {
        return $this->get('/search.json', [
            'q' => $query,
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * List popular toolkits.
     *
     * @return array<string, mixed>
     */
    public function popularToolkits(int $perPage = 15, int $page = 1): array
    {
        return $this->get('/explore/popular.json', [
            'per_page' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Get toolkit details by owner/name.
     *
     * @return array<string, mixed>
     */
    public function toolkitDetails(string $owner, string $name): array
    {
        return $this->get("/toolkits/{$owner}/{$name}");
    }

    /**
     * Get toolkit Packagist-format metadata by vendor/package name.
     *
     * @return array<string, mixed>
     */
    public function toolkitPackage(string $vendor, string $package): array
    {
        return $this->get("/packages/{$vendor}/{$package}.json");
    }

    /**
     * Get reviews for a toolkit.
     *
     * @return array<string, mixed>
     */
    public function toolkitReviews(string $owner, string $name, int $limit = 20, ?string $cursor = null): array
    {
        $params = ['limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/toolkits/{$owner}/{$name}/reviews", $params);
    }

    /**
     * Register a new toolkit on Coqui Space.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createToolkit(array $data): array
    {
        return $this->post('/toolkits', $data);
    }

    /**
     * Update an existing toolkit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateToolkit(string $owner, string $name, array $data): array
    {
        return $this->put("/toolkits/{$owner}/{$name}", $data);
    }

    /**
     * Log a toolkit install (fire-and-forget stats tracking).
     *
     * @return array<string, mixed>
     */
    public function logToolkitInstall(string $owner, string $name, ?string $clientVersion = null): array
    {
        $data = [];
        if ($clientVersion !== null) {
            $data['clientVersion'] = $clientVersion;
        }

        return $this->post("/toolkits/{$owner}/{$name}/install", $data);
    }

    // ── Social / User ────────────────────────────────────────────────

    /**
     * Star a skill or toolkit.
     *
     * @return array<string, mixed>
     */
    public function star(string $entityType, string $owner, string $name): array
    {
        return $this->post("/stars/{$entityType}/{$owner}/{$name}", []);
    }

    /**
     * Unstar a skill or toolkit.
     *
     * @return array<string, mixed>
     */
    public function unstar(string $entityType, string $owner, string $name): array
    {
        return $this->delete("/stars/{$entityType}/{$owner}/{$name}");
    }

    /**
     * Get the authenticated user's profile.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->get('/me');
    }

    // ── Submissions ──────────────────────────────────────────────────

    /**
     * Submit a skill or toolkit URL for review.
     *
     * @return array<string, mixed>
     */
    public function createSubmission(string $type, string $sourceUrl, ?string $notes = null): array
    {
        $data = ['type' => $type, 'sourceUrl' => $sourceUrl];
        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        return $this->post('/submissions', $data);
    }

    // ── Tags ─────────────────────────────────────────────────────────

    /**
     * Get available tags for skills and/or toolkits.
     *
     * @param string $type Filter: 'all', 'skills', or 'toolkits'
     * @return array<string, mixed>
     */
    public function getTags(string $type = 'all'): array
    {
        $params = [];
        if ($type !== 'all') {
            $params['type'] = $type;
        }

        return $this->get('/tags', $params);
    }

    // ── Unified Search ───────────────────────────────────────────────

    /**
     * Search both skills and toolkits in a single request.
     *
     * Note: The cursor parameter only applies to skill results on the server side.
     * Toolkit results always return page 1.
     *
     * @return array<string, mixed>
     */
    public function searchAll(string $query, int $limit = 10, ?string $cursor = null): array
    {
        $params = ['q' => $query, 'limit' => $limit];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/search/all', $params);
    }

    // ── HTTP primitives ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl() . $path;
        $options = [
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(true),
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        try {
            $response = $this->http->request('GET', $url, $options);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'GET %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = $this->http->request('POST', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
                'json' => $data,
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'POST %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function put(string $path, array $data): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = $this->http->request('PUT', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
                'json' => $data,
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'PUT %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = $this->http->request('DELETE', $url, [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(true),
            ]);

            return $this->decodeJson($response->getContent());
        } catch (HttpExceptionInterface $e) {
            throw new \RuntimeException(sprintf(
                'DELETE %s failed: HTTP %d — %s',
                $path,
                $e->getResponse()->getStatusCode(),
                $this->extractError($e),
            ));
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function baseUrl(): string
    {
        return rtrim(($this->urlResolver)(), '/');
    }

    private function token(): string
    {
        return ($this->tokenResolver)();
    }

    /**
     * Build request headers, optionally including the auth token.
     *
     * @return array<string, string>
     */
    private function headers(bool $withAuth): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'coqui-space-manager/0.1.0',
        ];

        if ($withAuth) {
            $token = $this->token();
            if ($token !== '') {
                $headers['Authorization'] = "Bearer {$token}";
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        try {
            /** @var array<string, mixed> */
            return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Failed to decode API response: {$e->getMessage()}");
        }
    }

    /**
     * Extract a human-readable error from an HTTP exception response.
     */
    private function extractError(HttpExceptionInterface $e): string
    {
        try {
            $body = $e->getResponse()->getContent(false);
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                $message = $decoded['error'] ?? $decoded['message'] ?? $body;
            } else {
                $message = $body;
            }
        } catch (\Throwable) {
            $message = $e->getMessage();
        }

        if (is_array($message)) {
            $message = json_encode($message);
        }

        $message = (string) $message;

        if (strlen($message) > self::MAX_ERROR_LENGTH) {
            return substr($message, 0, self::MAX_ERROR_LENGTH) . '…';
        }

        return $message;
    }

    /**
     * Parse filename from Content-Disposition header.
     */
    private function parseFilename(string $disposition): ?string
    {
        if (preg_match('/filename[*]?=["\']?([^"\';\s]+)/', $disposition, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
