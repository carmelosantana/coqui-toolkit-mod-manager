<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Installer\SkillInstaller;
use CoquiBot\SpaceManager\Tool\SpaceSkillsTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createSkillsTool(array $responses): SpaceSkillsTool
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

    $dir = sys_get_temp_dir() . '/coqui-skills-tool-test-' . uniqid();
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }

    $installer = new SkillInstaller($client, $dir);

    return new SpaceSkillsTool($client, $installer);
}

// ── Tool metadata ────────────────────────────────────────────────

test('name returns space_skills', function () {
    $tool = createSkillsTool([]);
    expect($tool->name())->toBe('space_skills');
});

test('description mentions all actions', function () {
    $tool = createSkillsTool([]);
    $desc = $tool->description();

    expect($desc)->toContain('search')
        ->and($desc)->toContain('list')
        ->and($desc)->toContain('details')
        ->and($desc)->toContain('install')
        ->and($desc)->toContain('publish');
});

test('parameters include action enum', function () {
    $tool = createSkillsTool([]);
    $params = $tool->parameters();

    $actionParam = $params[0];
    expect($actionParam->name)->toBe('action')
        ->and($actionParam->required)->toBeTrue();
});

test('toFunctionSchema returns valid schema', function () {
    $tool = createSkillsTool([]);
    $schema = $tool->toFunctionSchema();

    expect($schema)->toHaveKey('type')
        ->and($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('space_skills')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('action');
});

// ── Search action ────────────────────────────────────────────────

test('search returns formatted results', function () {
    $tool = createSkillsTool([
        [
            'results' => [
                [
                    'name' => 'code-review',
                    'displayName' => 'Code Review',
                    'owner' => 'testuser',
                    'version' => '1.0.0',
                    'score' => 9.5,
                    'verified_publisher' => true,
                ],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'search', 'query' => 'code']);

    expect($result)->toBeInstanceOf(ToolResult::class)
        ->and($result->content)->toContain('Code Review')
        ->and($result->content)->toContain('testuser')
        ->and($result->content)->toContain('1.0.0')
        ->and($result->content)->toContain('✓');
});

test('search returns message when no results', function () {
    $tool = createSkillsTool([['results' => []]]);

    $result = $tool->execute(['action' => 'search', 'query' => 'nonexistent']);

    expect($result->content)->toContain('No skills found');
});

test('search requires query parameter', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'search']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('query');
});

test('search with empty query returns error', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'search', 'query' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── List action ──────────────────────────────────────────────────

test('list returns formatted skill list', function () {
    $tool = createSkillsTool([
        [
            'items' => [
                [
                    'name' => 'code-helper',
                    'displayName' => 'Code Helper',
                    'owner' => ['handle' => 'dev', 'displayName' => 'Developer'],
                    'stats' => ['downloads' => 1500, 'stars' => 42],
                    'latestVersion' => ['version' => '2.1.0'],
                ],
            ],
            'nextCursor' => 'next_abc',
        ],
    ]);

    $result = $tool->execute(['action' => 'list', 'sort' => 'downloads']);

    expect($result->content)->toContain('Code Helper')
        ->and($result->content)->toContain('1.5K')
        ->and($result->content)->toContain('next_abc');
});

test('list returns message when empty', function () {
    $tool = createSkillsTool([['items' => []]]);

    $result = $tool->execute(['action' => 'list']);

    expect($result->content)->toContain('No skills found');
});

// ── Details action ───────────────────────────────────────────────

test('details returns formatted skill info', function () {
    $tool = createSkillsTool([
        [
            'skill' => [
                'displayName' => 'Code Review',
                'description' => 'Automated code review skill',
                'status' => 'published',
                'verified_publisher' => true,
                'starred' => true,
                'stats' => ['downloads' => 2500, 'stars' => 100, 'versions' => 5],
                'skillTags' => ['code', 'review'],
            ],
            'latestVersion' => ['version' => '3.0.0', 'changelog' => 'Major rewrite'],
            'owner' => ['displayName' => 'Test User', 'handle' => 'testuser'],
        ],
    ]);

    $result = $tool->execute(['action' => 'details', 'owner' => 'testuser', 'name' => 'code-review']);

    expect($result->content)->toContain('Code Review')
        ->and($result->content)->toContain('published')
        ->and($result->content)->toContain('Yes ✓')
        ->and($result->content)->toContain('Yes ★')
        ->and($result->content)->toContain('2.5K')
        ->and($result->content)->toContain('3.0.0')
        ->and($result->content)->toContain('Major rewrite')
        ->and($result->content)->toContain('code, review');
});

test('details requires owner and name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'details', 'owner' => 'testuser']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('owner');
});

test('details requires both owner and name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'details']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Versions action ──────────────────────────────────────────────

test('versions returns formatted list', function () {
    $tool = createSkillsTool([
        [
            'items' => [
                ['version' => '2.0.0', 'createdAt' => '2024-06-15', 'changelog' => 'New feature'],
                ['version' => '1.0.0', 'createdAt' => '2024-01-01', 'changelog' => 'Initial release'],
            ],
            'nextCursor' => null,
        ],
    ]);

    $result = $tool->execute(['action' => 'versions', 'owner' => 'testuser', 'name' => 'my-skill']);

    expect($result->content)->toContain('2.0.0')
        ->and($result->content)->toContain('1.0.0')
        ->and($result->content)->toContain('New feature');
});

test('versions with no history', function () {
    $tool = createSkillsTool([['items' => []]]);

    $result = $tool->execute(['action' => 'versions', 'owner' => 'testuser', 'name' => 'empty']);

    expect($result->content)->toContain('No version history');
});

test('versions requires owner and name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'versions', 'name' => 'my-skill']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Reviews action ───────────────────────────────────────────────

test('reviews returns formatted list', function () {
    $tool = createSkillsTool([
        [
            'items' => [
                ['rating' => 5, 'title' => 'Excellent!', 'body' => 'Best skill ever'],
                ['rating' => 3, 'title' => '', 'body' => 'Average'],
            ],
            'stats' => ['average' => 4.0, 'count' => 2],
        ],
    ]);

    $result = $tool->execute(['action' => 'reviews', 'owner' => 'testuser', 'name' => 'my-skill']);

    expect($result->content)->toContain('4.0/5')
        ->and($result->content)->toContain('★★★★★')
        ->and($result->content)->toContain('Excellent!')
        ->and($result->content)->toContain('★★★☆☆');
});

test('reviews with no reviews', function () {
    $tool = createSkillsTool([['items' => [], 'stats' => ['average' => 0, 'count' => 0]]]);

    $result = $tool->execute(['action' => 'reviews', 'owner' => 'testuser', 'name' => 'new-skill']);

    expect($result->content)->toContain('No reviews yet');
});

test('reviews requires owner and name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'reviews']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── File action ──────────────────────────────────────────────────

test('file returns raw SKILL.md content', function () {
    $tool = createSkillsTool(["---\nname: Test Skill\n---\nA test skill."]);

    $result = $tool->execute(['action' => 'file', 'owner' => 'testuser', 'name' => 'my-skill']);

    expect($result->content)->toContain('SKILL.md')
        ->and($result->content)->toContain('name: Test Skill');
});

test('file returns message when empty', function () {
    $tool = createSkillsTool(['']);

    $result = $tool->execute(['action' => 'file', 'owner' => 'testuser', 'name' => 'empty']);

    expect($result->content)->toContain('No SKILL.md content');
});

test('file requires owner and name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'file', 'owner' => '', 'name' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Update action ────────────────────────────────────────────────

test('update requires skill_name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'update']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('skill_name');
});

// ── Publish action ───────────────────────────────────────────────

test('publish requires skill_name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'publish']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('skill_name');
});

test('publish with non-existent skill returns error', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'publish', 'skill_name' => 'does-not-exist']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('not found');
});

// ── Unknown action ───────────────────────────────────────────────

test('unknown action returns error', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'bogus']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('empty action returns error', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Error handling ───────────────────────────────────────────────

test('API errors are caught and returned as ToolResult errors', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Internal server error"}', ['http_code' => 500]),
    ]);

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );

    $dir = sys_get_temp_dir() . '/coqui-skills-tool-test-' . uniqid();
    mkdir($dir, 0o755, true);

    $tool = new SpaceSkillsTool($client, new SkillInstaller($client, $dir));

    $result = $tool->execute(['action' => 'search', 'query' => 'test']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('500');
});

// ── Delete ───────────────────────────────────────────────────────────

test('delete returns success on valid response', function () {
    $tool = createSkillsTool([
        ['success' => true, 'message' => 'Skill deleted'],
    ]);

    $result = $tool->execute(['action' => 'delete', 'owner' => 'testuser', 'name' => 'my-skill']);

    expect($result->content)->toContain('deleted');
});

test('delete requires owner and name', function () {
    $tool = createSkillsTool([]);

    $result = $tool->execute(['action' => 'delete']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Log Install ──────────────────────────────────────────────────────

test('log_install returns success', function () {
    $tool = createSkillsTool([
        ['success' => true],
    ]);

    $result = $tool->execute(['action' => 'log_install', 'owner' => 'testuser', 'name' => 'my-skill']);

    expect($result->content)->toContain('Install');
});
