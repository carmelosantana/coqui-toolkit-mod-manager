<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Installer\ToolkitInstaller;
use CoquiBot\SpaceManager\Tool\SpaceToolkitsTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createToolkitsTool(array $responses): SpaceToolkitsTool
{
    $http = new MockHttpClient(array_map(
        static fn(array|string $r): MockResponse => is_string($r)
            ? new MockResponse($r)
            : new MockResponse(json_encode($r)),
        $responses,
    ));

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );

    $dir = sys_get_temp_dir() . '/coqui-toolkits-tool-test-' . uniqid();
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    $installer = new ToolkitInstaller($client, $dir);

    return new SpaceToolkitsTool($client, $installer);
}

// ── Tool metadata ────────────────────────────────────────────────

test('name returns space_toolkits', function () {
    $tool = createToolkitsTool([]);
    expect($tool->name())->toBe('space_toolkits');
});

test('description mentions all actions', function () {
    $tool = createToolkitsTool([]);
    $desc = $tool->description();

    expect($desc)->toContain('search')
        ->and($desc)->toContain('list')
        ->and($desc)->toContain('popular')
        ->and($desc)->toContain('details')
        ->and($desc)->toContain('reviews')
        ->and($desc)->toContain('install')
        ->and($desc)->toContain('publish');
});

test('toFunctionSchema returns valid structure', function () {
    $tool = createToolkitsTool([]);
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('space_toolkits')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('action')
        ->and($schema['function']['parameters'])->toHaveKey('required');
});

// ── Search action ────────────────────────────────────────────────

test('search returns formatted results', function () {
    $tool = createToolkitsTool([
        [
            'results' => [
                [
                    'name' => 'coquibot/coqui-toolkit-brave-search',
                    'downloads' => 5000,
                    'favers' => 120,
                    'verified_publisher' => true,
                    'description' => 'Brave Search integration',
                ],
            ],
            'total' => 1,
        ],
    ]);

    $result = $tool->execute(['action' => 'search', 'query' => 'brave']);

    expect($result->content)->toContain('brave-search')
        ->and($result->content)->toContain('5.0K')
        ->and($result->content)->toContain('✓')
        ->and($result->content)->toContain('1 total');
});

test('search requires query', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'search']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('query');
});

test('search returns message when empty', function () {
    $tool = createToolkitsTool([['results' => [], 'total' => 0]]);

    $result = $tool->execute(['action' => 'search', 'query' => 'nonexistent']);

    expect($result->content)->toContain('No toolkits found');
});

test('search shows next page hint when available', function () {
    $tool = createToolkitsTool([
        [
            'results' => [['name' => 'coquibot/something', 'downloads' => 10, 'favers' => 1, 'description' => 'Test']],
            'total' => 50,
            'next' => 'https://coqui.space/api/v1/search.json?q=test&page=2',
        ],
    ]);

    $result = $tool->execute(['action' => 'search', 'query' => 'test', 'page' => 1]);

    expect($result->content)->toContain('page: 2');
});

// ── List action (cursor-paginated) ───────────────────────────────

test('list returns formatted toolkit list', function () {
    $tool = createToolkitsTool([
        [
            'items' => [
                [
                    'packageName' => 'coquibot/coqui-toolkit-browser',
                    'owner' => ['handle' => 'coquibot'],
                    'downloads' => 2500,
                    'favers' => 50,
                    'verified_publisher' => true,
                    'tags' => ['browser', 'web', 'automation'],
                ],
            ],
            'nextCursor' => 'cursor_xyz',
        ],
    ]);

    $result = $tool->execute(['action' => 'list', 'sort' => 'downloads']);

    expect($result->content)->toContain('coqui-toolkit-browser')
        ->and($result->content)->toContain('2.5K')
        ->and($result->content)->toContain('browser, web, automation')
        ->and($result->content)->toContain('cursor_xyz');
});

test('list returns message when empty', function () {
    $tool = createToolkitsTool([['items' => []]]);

    $result = $tool->execute(['action' => 'list']);

    expect($result->content)->toContain('No toolkits found');
});

test('list without nextCursor does not show pagination hint', function () {
    $tool = createToolkitsTool([
        [
            'items' => [['packageName' => 'coquibot/test', 'downloads' => 10, 'favers' => 1, 'tags' => []]],
            'nextCursor' => null,
        ],
    ]);

    $result = $tool->execute(['action' => 'list']);

    expect($result->content)->not->toContain('More results available');
});

// ── Popular action ───────────────────────────────────────────────

test('popular returns ranked list', function () {
    $tool = createToolkitsTool([
        [
            'packages' => [
                [
                    'name' => 'coquibot/coqui-toolkit-browser',
                    'downloads' => 10000,
                    'favers' => 250,
                    'description' => 'A web browser toolkit',
                ],
                [
                    'name' => 'coquibot/coqui-toolkit-calculator',
                    'downloads' => 8000,
                    'favers' => 200,
                    'description' => 'Calculator toolkit',
                ],
            ],
            'total' => 2,
        ],
    ]);

    $result = $tool->execute(['action' => 'popular']);

    expect($result->content)->toContain('Popular Toolkits')
        ->and($result->content)->toContain('10.0K')
        ->and($result->content)->toContain('coqui-toolkit-browser');
});

test('popular returns message when empty', function () {
    $tool = createToolkitsTool([['packages' => [], 'total' => 0]]);

    $result = $tool->execute(['action' => 'popular']);

    expect($result->content)->toContain('No popular toolkits found');
});

// ── Details action ───────────────────────────────────────────────

test('details by package returns Packagist metadata', function () {
    $tool = createToolkitsTool([
        [
            'package' => [
                'name' => 'coquibot/coqui-toolkit-browser',
                'description' => 'Browser automation toolkit',
                'repository' => 'https://github.com/coquibot/coqui-toolkit-browser',
                'type' => 'library',
                'verified_publisher' => true,
                'favers' => 250,
                'downloads' => ['total' => 10000, 'monthly' => 500, 'daily' => 20],
                'versions' => ['1.2.0' => [], '1.1.0' => [], '1.0.0' => []],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'details', 'package' => 'coquibot/coqui-toolkit-browser']);

    expect($result->content)->toContain('coqui-toolkit-browser')
        ->and($result->content)->toContain('10.0K')
        ->and($result->content)->toContain('Yes ✓')
        ->and($result->content)->toContain('1.2.0');
});

test('details by owner/name returns toolkit info', function () {
    $tool = createToolkitsTool([
        [
            'toolkit' => [
                'packageName' => 'coquibot/coqui-toolkit-browser',
                'displayName' => 'Browser Toolkit',
                'description' => 'Browser automation',
                'repository' => 'https://github.com/coquibot/browser',
                'downloads' => 5000,
                'favers' => 100,
                'featured' => true,
                'verified_publisher' => true,
                'tags' => ['browser', 'web'],
                'owner' => ['displayName' => 'Coqui Bot', 'handle' => 'coquibot'],
                'credentials' => [
                    'BROWSER_API_KEY' => 'API key for browser automation',
                ],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'details', 'owner' => 'coquibot', 'name' => 'browser']);

    expect($result->content)->toContain('Browser Toolkit')
        ->and($result->content)->toContain('Featured')
        ->and($result->content)->toContain('browser, web')
        ->and($result->content)->toContain('BROWSER_API_KEY');
});

test('details requires either package or owner+name', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'details']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('package');
});

test('details with abandoned package shows warning', function () {
    $tool = createToolkitsTool([
        [
            'package' => [
                'name' => 'old/package',
                'description' => 'Old package',
                'repository' => '-',
                'type' => 'library',
                'abandoned' => 'new/package',
                'favers' => 0,
                'downloads' => ['total' => 100, 'monthly' => 0, 'daily' => 0],
                'versions' => [],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'details', 'package' => 'old/package']);

    expect($result->content)->toContain('abandoned')
        ->and($result->content)->toContain('new/package');
});

// ── Reviews action ───────────────────────────────────────────────

test('reviews returns formatted list', function () {
    $tool = createToolkitsTool([
        [
            'items' => [
                ['rating' => 5, 'title' => 'Amazing!', 'body' => 'Works perfectly'],
            ],
            'stats' => ['average' => 5.0, 'count' => 1],
        ],
    ]);

    $result = $tool->execute(['action' => 'reviews', 'owner' => 'coquibot', 'name' => 'browser']);

    expect($result->content)->toContain('5.0/5')
        ->and($result->content)->toContain('★★★★★')
        ->and($result->content)->toContain('Amazing!');
});

test('reviews requires owner and name', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'reviews']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('reviews with no reviews shows message', function () {
    $tool = createToolkitsTool([['items' => [], 'stats' => []]]);

    $result = $tool->execute(['action' => 'reviews', 'owner' => 'coquibot', 'name' => 'new']);

    expect($result->content)->toContain('No reviews yet');
});

// ── Install action ───────────────────────────────────────────────

test('install requires package in vendor/name format', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'install', 'package' => 'invalid']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('vendor/package');
});

test('install with empty package returns error', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'install']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Update action ────────────────────────────────────────────────

test('update requires package in vendor/name format', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'update', 'package' => 'no-vendor']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Publish action ───────────────────────────────────────────────

test('publish requires package in vendor/name format', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'publish']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('package');
});

test('publish sends correct data to API', function () {
    $tool = createToolkitsTool([
        [
            'status' => 'published',
            'verified_publisher' => false,
        ],
    ]);

    $result = $tool->execute([
        'action' => 'publish',
        'package' => 'coquibot/my-toolkit',
        'display_name' => 'My Toolkit',
        'description' => 'A new toolkit',
        'tags' => 'api, search',
    ]);

    expect($result->content)->toContain('coquibot/my-toolkit')
        ->and($result->content)->toContain('published');
});

// ── Unknown action ───────────────────────────────────────────────

test('unknown action returns error', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'invalid_action']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('empty action returns error', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Error handling ───────────────────────────────────────────────

test('API errors are caught and returned as ToolResult errors', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Server error"}', ['http_code' => 500]),
    ]);

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );

    $dir = sys_get_temp_dir() . '/coqui-toolkits-tool-test-' . uniqid();
    mkdir($dir, 0o755, true);

    $tool = new SpaceToolkitsTool($client, new ToolkitInstaller($client, $dir));

    $result = $tool->execute(['action' => 'search', 'query' => 'test']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Delete ───────────────────────────────────────────────────────────

test('delete returns success on valid response', function () {
    $tool = createToolkitsTool([
        ['success' => true, 'message' => 'Toolkit deleted'],
    ]);

    $result = $tool->execute(['action' => 'delete', 'owner' => 'testuser', 'name' => 'my-toolkit']);

    expect($result->content)->toContain('deleted');
});

test('delete requires owner and name', function () {
    $tool = createToolkitsTool([]);

    $result = $tool->execute(['action' => 'delete']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
