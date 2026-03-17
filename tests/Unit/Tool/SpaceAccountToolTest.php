<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Tool\SpaceAccountTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createAccountTool(array $responses): SpaceAccountTool
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

    return new SpaceAccountTool($client);
}

// ── Profile ──────────────────────────────────────────────────────────

test('profile returns formatted user info', function () {
    $tool = createAccountTool([
        [
            'handle' => 'testuser',
            'displayName' => 'Test User',
            'role' => 'admin',
            'verified' => true,
            'canPublish' => true,
            'canModerate' => true,
        ],
    ]);

    $result = $tool->execute(['action' => 'profile']);

    expect($result->content)->toContain('Test User')
        ->and($result->content)->toContain('@testuser')
        ->and($result->content)->toContain('admin')
        ->and($result->content)->toContain('Yes ✓');
});

// ── My Skills ────────────────────────────────────────────────────────

test('my_skills returns table with formatted numbers', function () {
    $tool = createAccountTool([
        [
            'items' => [
                [
                    'displayName' => 'Code Review',
                    'status' => 'published',
                    'stats' => ['downloads' => 2500, 'stars' => 100],
                ],
                [
                    'displayName' => 'Linter',
                    'status' => 'draft',
                    'stats' => ['downloads' => 50, 'stars' => 3],
                ],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_skills']);

    expect($result->content)->toContain('Your Skills')
        ->and($result->content)->toContain('Code Review')
        ->and($result->content)->toContain('2.5K')
        ->and($result->content)->toContain('Linter')
        ->and($result->content)->toContain('draft');
});

test('my_skills shows empty message when none exist', function () {
    $tool = createAccountTool([['items' => []]]);

    $result = $tool->execute(['action' => 'my_skills']);

    expect($result->content)->toContain('no skills');
});

// ── My Toolkits ──────────────────────────────────────────────────────

test('my_toolkits returns table', function () {
    $tool = createAccountTool([
        [
            'items' => [
                [
                    'packageName' => 'coquibot/brave-search',
                    'status' => 'published',
                    'stats' => ['downloads' => 15000, 'favers' => 200],
                ],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_toolkits']);

    expect($result->content)->toContain('Your Toolkits')
        ->and($result->content)->toContain('coquibot/brave-search')
        ->and($result->content)->toContain('15.0K')
        ->and($result->content)->toContain('200');
});

// ── My Collections ───────────────────────────────────────────────────

test('my_collections returns bullet list', function () {
    $tool = createAccountTool([
        [
            'items' => [
                ['id' => 'abc123', 'name' => 'Favorites', 'itemCount' => 5, 'isPublic' => true],
                ['id' => 'def456', 'name' => 'Private Tools', 'itemCount' => 2, 'isPublic' => false],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_collections']);

    expect($result->content)->toContain('Favorites')
        ->and($result->content)->toContain('#abc123')
        ->and($result->content)->toContain('5 items')
        ->and($result->content)->toContain('public')
        ->and($result->content)->toContain('Private Tools')
        ->and($result->content)->toContain('private');
});

// ── My Submissions ───────────────────────────────────────────────────

test('my_submissions returns table', function () {
    $tool = createAccountTool([
        [
            'items' => [
                ['id' => '1', 'type' => 'skill', 'status' => 'pending', 'sourceUrl' => 'https://github.com/user/repo'],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_submissions']);

    expect($result->content)->toContain('Your Submissions')
        ->and($result->content)->toContain('skill')
        ->and($result->content)->toContain('pending')
        ->and($result->content)->toContain('https://github.com/user/repo');
});

// ── My Installs ──────────────────────────────────────────────────────

test('my_installs returns table with dates', function () {
    $tool = createAccountTool([
        [
            'items' => [
                ['name' => 'code-review', 'type' => 'skill', 'createdAt' => '2024-01-15T10:00:00Z'],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_installs']);

    expect($result->content)->toContain('Install Activity')
        ->and($result->content)->toContain('code-review')
        ->and($result->content)->toContain('skill')
        ->and($result->content)->toContain('2024-01-15');
});

// ── My Analytics ─────────────────────────────────────────────────────

test('my_analytics returns formatted stats with bar chart', function () {
    $tool = createAccountTool([
        [
            'totalDownloads' => 5000,
            'totalStars' => 150,
            'dailyInstalls' => [
                ['date' => '2024-01-10', 'count' => 5],
                ['date' => '2024-01-11', 'count' => 3],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_analytics']);

    expect($result->content)->toContain('Analytics')
        ->and($result->content)->toContain('5.0K')
        ->and($result->content)->toContain('150')
        ->and($result->content)->toContain('Daily Installs')
        ->and($result->content)->toContain('█');
});

// ── My Stars ─────────────────────────────────────────────────────────

test('my_stars returns starred items with icons', function () {
    $tool = createAccountTool([
        [
            'items' => [
                ['entityType' => 'skill', 'name' => 'code-review', 'owner' => 'carmelosantana'],
                ['entityType' => 'toolkit', 'name' => 'brave-search', 'owner' => 'coquibot'],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'my_stars']);

    expect($result->content)->toContain('Your Stars')
        ->and($result->content)->toContain('★')
        ->and($result->content)->toContain('code-review')
        ->and($result->content)->toContain('carmelosantana');
});

test('my_stars shows empty message when none exist', function () {
    $tool = createAccountTool([['items' => []]]);

    $result = $tool->execute(['action' => 'my_stars']);

    expect($result->content)->toContain('no starred items');
});

// ── Schema & Error Handling ──────────────────────────────────────────

test('produces valid function schema', function () {
    $tool = createAccountTool([]);

    $schema = $tool->toFunctionSchema();

    expect($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('space_account')
        ->and($schema['function'])->toHaveKey('parameters');
});

test('unknown action returns error', function () {
    $tool = createAccountTool([]);

    $result = $tool->execute(['action' => 'bogus']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('api error is surfaced gracefully', function () {
    $http = new MockHttpClient([
        new MockResponse('Internal Server Error', ['http_code' => 500]),
    ]);

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );

    $tool = new SpaceAccountTool($client);
    $result = $tool->execute(['action' => 'profile']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
