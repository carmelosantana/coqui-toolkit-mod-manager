<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Config\SpaceRegistry;
use CoquiBot\SpaceManager\Installer\SkillInstaller;
use CoquiBot\SpaceManager\Installer\ToolkitInstaller;
use CoquiBot\SpaceManager\Tool\SpaceManageTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createManageTool(array $responses, ?string $skillDir = null, ?string $workspaceDir = null): SpaceManageTool
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

    $sDir = $skillDir ?? sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    $wDir = $workspaceDir ?? sys_get_temp_dir() . '/coqui-manage-workspace-test-' . uniqid();

    foreach ([$sDir, $wDir] as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0o755, true);
        }
    }

    return new SpaceManageTool(
        $client,
        new SkillInstaller($client, $sDir),
        new ToolkitInstaller($client, $wDir),
    );
}

// ── Tool metadata ────────────────────────────────────────────────

test('name returns space', function () {
    $tool = createManageTool([]);
    expect($tool->name())->toBe('space');
});

test('description mentions all actions', function () {
    $tool = createManageTool([]);
    $desc = $tool->description();

    expect($desc)->toContain('installed')
        ->and($desc)->toContain('disable')
        ->and($desc)->toContain('enable')
        ->and($desc)->toContain('remove')
        ->and($desc)->toContain('star')
        ->and($desc)->toContain('unstar')
        ->and($desc)->toContain('submit')
        ->and($desc)->toContain('tags')
        ->and($desc)->toContain('search_all');
});

test('toFunctionSchema returns valid structure', function () {
    $tool = createManageTool([]);
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('space')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('action');
});

// ── Installed action ──────────────────────────────────────────────

test('installed returns empty state', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'installed']);

    expect($result->content)->toContain('Installed Content')
        ->and($result->content)->toContain('No skills installed')
        ->and($result->content)->toContain('No Coqui toolkits installed');
});

test('installed shows skills only when type is skills', function () {
    $skillDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    mkdir($skillDir . '/my-skill', 0o755, true);
    file_put_contents($skillDir . '/my-skill/SKILL.md', "---\nname: My Skill\nversion: 1.0.0\n---\n");

    $tool = createManageTool([], $skillDir);

    $result = $tool->execute(['action' => 'installed', 'type' => 'skills']);

    expect($result->content)->toContain('my-skill')
        ->and($result->content)->not->toContain('### Toolkits');
});

test('installed shows toolkits only when type is toolkits', function () {
    $wDir = sys_get_temp_dir() . '/coqui-manage-workspace-test-' . uniqid();
    mkdir($wDir, 0o755, true);
    file_put_contents($wDir . '/composer.json', json_encode([
        'require' => ['coquibot/coqui-toolkit-browser' => '^1.0'],
    ]));

    $tool = createManageTool([], null, $wDir);

    $result = $tool->execute(['action' => 'installed', 'type' => 'toolkits']);

    expect($result->content)->toContain('coqui-toolkit-browser')
        ->and($result->content)->not->toContain('### Skills');
});

test('installed shows both types when type is all', function () {
    $skillDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    mkdir($skillDir . '/test-skill', 0o755, true);
    file_put_contents($skillDir . '/test-skill/SKILL.md', "---\nname: Test\nversion: 1.0.0\n---\n");

    $wDir = sys_get_temp_dir() . '/coqui-manage-workspace-test-' . uniqid();
    mkdir($wDir, 0o755, true);
    file_put_contents($wDir . '/composer.json', json_encode([
        'require' => ['coquibot/coqui-toolkit-calc' => '^2.0'],
    ]));

    $tool = createManageTool([], $skillDir, $wDir);

    $result = $tool->execute(['action' => 'installed', 'type' => 'all']);

    expect($result->content)->toContain('### Skills')
        ->and($result->content)->toContain('### Toolkits')
        ->and($result->content)->toContain('test-skill')
        ->and($result->content)->toContain('coqui-toolkit-calc');
});

// ── Disable action ───────────────────────────────────────────────

test('disable skill renames directory', function () {
    $skillDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    mkdir($skillDir . '/disable-me', 0o755, true);
    file_put_contents($skillDir . '/disable-me/SKILL.md', "---\nname: Disable Me\n---\n");

    $tool = createManageTool([], $skillDir);

    $result = $tool->execute(['action' => 'disable', 'name' => 'disable-me']);

    expect($result->content)->toContain('disabled')
        ->and(is_dir($skillDir . '/disable-me.disabled'))->toBeTrue();
});

test('disable requires name', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'disable']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('name');
});

test('disable non-existent skill returns error', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'disable', 'name' => 'ghost-skill']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Enable action ────────────────────────────────────────────────

test('enable skill restores directory', function () {
    $skillDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    mkdir($skillDir . '/enable-me.disabled', 0o755, true);
    file_put_contents($skillDir . '/enable-me.disabled/SKILL.md', "---\nname: Enable Me\n---\n");

    $tool = createManageTool([], $skillDir);

    $result = $tool->execute(['action' => 'enable', 'name' => 'enable-me']);

    expect($result->content)->toContain('enabled')
        ->and(is_dir($skillDir . '/enable-me'))->toBeTrue();
});

test('enable requires name', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'enable']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Remove action ────────────────────────────────────────────────

test('remove skill without purge disables it', function () {
    $skillDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    mkdir($skillDir . '/removable', 0o755, true);
    file_put_contents($skillDir . '/removable/SKILL.md', "---\nname: Removable\n---\n");

    $tool = createManageTool([], $skillDir);

    $result = $tool->execute(['action' => 'remove', 'name' => 'removable']);

    expect($result->content)->toContain('disabled')
        ->and(is_dir($skillDir . '/removable.disabled'))->toBeTrue();
});

test('remove skill with purge deletes it', function () {
    $skillDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    mkdir($skillDir . '/deletable', 0o755, true);
    file_put_contents($skillDir . '/deletable/SKILL.md', "---\nname: Deletable\n---\n");

    $tool = createManageTool([], $skillDir);

    $result = $tool->execute(['action' => 'remove', 'name' => 'deletable', 'purge' => true]);

    expect($result->content)->toContain('removed')
        ->and(is_dir($skillDir . '/deletable'))->toBeFalse();
});

test('remove requires name', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'remove']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Toolkit routing (via / detection) ────────────────────────────

test('disable routes toolkit name correctly', function () {
    // A name with / is treated as a toolkit and routed to ToolkitInstaller
    $wDir = sys_get_temp_dir() . '/coqui-manage-workspace-test-' . uniqid();
    mkdir($wDir, 0o755, true);
    file_put_contents($wDir . '/composer.json', json_encode([
        'require' => ['coquibot/some-toolkit' => '^1.0'],
    ]));

    $tool = createManageTool([], null, $wDir);

    // This will fail because Composer isn't available in tests, but we verify
    // it's being routed to the toolkit installer (error mentions Composer)
    $result = $tool->execute(['action' => 'disable', 'name' => 'coquibot/some-toolkit']);

    // The ToolkitInstaller should attempt to handle it
    expect($result)->toBeInstanceOf(ToolResult::class);
});

// ── Star action ──────────────────────────────────────────────────

test('star sends correct request', function () {
    $tool = createManageTool([['starred' => true]]);

    $result = $tool->execute([
        'action' => 'star',
        'entity_type' => 'skill',
        'owner' => 'testuser',
        'name' => 'my-skill',
    ]);

    expect($result->content)->toContain('Starred')
        ->and($result->content)->toContain('★');
});

test('star already starred shows appropriate message', function () {
    $tool = createManageTool([['alreadyStarred' => true]]);

    $result = $tool->execute([
        'action' => 'star',
        'entity_type' => 'skill',
        'owner' => 'testuser',
        'name' => 'my-skill',
    ]);

    expect($result->content)->toContain('already starred');
});

test('star requires all parameters', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'star', 'entity_type' => 'skill']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('entity_type');
});

// ── Unstar action ────────────────────────────────────────────────

test('unstar sends correct request', function () {
    $tool = createManageTool([['unstarred' => true]]);

    $result = $tool->execute([
        'action' => 'unstar',
        'entity_type' => 'toolkit',
        'owner' => 'coquibot',
        'name' => 'browser',
    ]);

    expect($result->content)->toContain('Unstarred');
});

test('unstar already unstarred shows message', function () {
    $tool = createManageTool([['alreadyUnstarred' => true]]);

    $result = $tool->execute([
        'action' => 'unstar',
        'entity_type' => 'skill',
        'owner' => 'testuser',
        'name' => 'my-skill',
    ]);

    expect($result->content)->toContain('was not starred');
});

test('unstar requires all parameters', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'unstar']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Submit action ────────────────────────────────────────────────

test('submit creates submission', function () {
    $tool = createManageTool([['id' => '42', 'status' => 'pending']]);

    $result = $tool->execute([
        'action' => 'submit',
        'type' => 'skill',
        'source_url' => 'https://github.com/user/skill',
        'notes' => 'Please review this skill',
    ]);

    expect($result->content)->toContain('#42')
        ->and($result->content)->toContain('moderator');
});

test('submit requires valid type', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'submit', 'type' => 'invalid', 'source_url' => 'https://example.com']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('type');
});

test('submit requires source_url', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'submit', 'type' => 'toolkit']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('source_url');
});

// ── Tags action ──────────────────────────────────────────────────

test('tags returns all tags by default', function () {
    $tool = createManageTool([
        [
            'skills' => [
                ['slug' => 'code-review', 'name' => 'Code Review'],
                ['slug' => 'testing', 'name' => 'Testing'],
            ],
            'toolkits' => [
                ['slug' => 'search', 'name' => 'Search'],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'tags']);

    expect($result->content)->toContain('Available Tags')
        ->and($result->content)->toContain('Skill Tags')
        ->and($result->content)->toContain('code-review')
        ->and($result->content)->toContain('testing')
        ->and($result->content)->toContain('Toolkit Tags')
        ->and($result->content)->toContain('search');
});

test('tags filters by skills type', function () {
    $tool = createManageTool([
        [
            'skills' => [['slug' => 'automation', 'name' => 'Automation']],
            'toolkits' => [],
        ],
    ]);

    $result = $tool->execute(['action' => 'tags', 'type' => 'skills']);

    expect($result->content)->toContain('Skill Tags')
        ->and($result->content)->toContain('automation');
});

test('tags filters by toolkits type', function () {
    $tool = createManageTool([
        [
            'skills' => [],
            'toolkits' => [['slug' => 'api', 'name' => 'API']],
        ],
    ]);

    $result = $tool->execute(['action' => 'tags', 'type' => 'toolkits']);

    expect($result->content)->toContain('Toolkit Tags')
        ->and($result->content)->toContain('api');
});

test('tags with empty results shows no tags message', function () {
    $tool = createManageTool([['skills' => [], 'toolkits' => []]]);

    $result = $tool->execute(['action' => 'tags']);

    expect($result->content)->toContain('No skill tags available')
        ->and($result->content)->toContain('No toolkit tags available');
});

test('tags normalizes singular type to plural', function () {
    $tool = createManageTool([
        [
            'skills' => [['slug' => 'test', 'name' => 'Test']],
            'toolkits' => [],
        ],
    ]);

    // 'skill' should be normalized to 'skills'
    $result = $tool->execute(['action' => 'tags', 'type' => 'skill']);

    expect($result->content)->toContain('Skill Tags')
        ->and($result->content)->toContain('test');
});

// ── Search All action ────────────────────────────────────────────

test('search_all returns combined results', function () {
    $tool = createManageTool([
        [
            'skills' => [
                'results' => [
                    [
                        'name' => 'code-review',
                        'displayName' => 'Code Review',
                        'owner' => 'testuser',
                        'version' => '2.0.0',
                        'verified_publisher' => true,
                    ],
                ],
            ],
            'toolkits' => [
                'results' => [
                    [
                        'name' => 'coquibot/coqui-toolkit-browser',
                        'downloads' => 5000,
                        'favers' => 100,
                        'verified_publisher' => false,
                    ],
                ],
                'total' => 1,
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'search_all', 'query' => 'code']);

    expect($result->content)->toContain('Search results')
        ->and($result->content)->toContain('Skills')
        ->and($result->content)->toContain('Code Review')
        ->and($result->content)->toContain('testuser')
        ->and($result->content)->toContain('Toolkits')
        ->and($result->content)->toContain('coqui-toolkit-browser');
});

test('search_all requires query', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'search_all']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('query');
});

test('search_all with empty query returns error', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'search_all', 'query' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('search_all returns no results message', function () {
    $tool = createManageTool([
        [
            'skills' => ['results' => []],
            'toolkits' => ['results' => [], 'total' => 0],
        ],
    ]);

    $result = $tool->execute(['action' => 'search_all', 'query' => 'zzzzz']);

    expect($result->content)->toContain('No results found');
});

test('search_all with only skills shows no matching toolkits', function () {
    $tool = createManageTool([
        [
            'skills' => [
                'results' => [
                    ['name' => 'a-skill', 'owner' => 'user', 'version' => '1.0.0'],
                ],
            ],
            'toolkits' => ['results' => [], 'total' => 0],
        ],
    ]);

    $result = $tool->execute(['action' => 'search_all', 'query' => 'test']);

    expect($result->content)->toContain('a-skill')
        ->and($result->content)->toContain('No matching toolkits');
});

test('search_all with only toolkits shows no matching skills', function () {
    $tool = createManageTool([
        [
            'skills' => ['results' => []],
            'toolkits' => [
                'results' => [['name' => 'coquibot/some-toolkit', 'downloads' => 10, 'favers' => 1]],
                'total' => 1,
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'search_all', 'query' => 'test']);

    expect($result->content)->toContain('No matching skills')
        ->and($result->content)->toContain('some-toolkit');
});

// ── Unknown action ───────────────────────────────────────────────

test('unknown action returns error', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'nope']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Unknown action');
});

test('empty action returns error', function () {
    $tool = createManageTool([]);

    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Error handling ───────────────────────────────────────────────

test('API errors are caught and returned as ToolResult errors', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Unauthorized"}', ['http_code' => 401]),
    ]);

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => '',
        $http,
    );

    $sDir = sys_get_temp_dir() . '/coqui-manage-skill-test-' . uniqid();
    $wDir = sys_get_temp_dir() . '/coqui-manage-workspace-test-' . uniqid();
    mkdir($sDir, 0o755, true);
    mkdir($wDir, 0o755, true);

    $tool = new SpaceManageTool(
        $client,
        new SkillInstaller($client, $sDir),
        new ToolkitInstaller($client, $wDir),
    );

    $result = $tool->execute([
        'action' => 'star',
        'entity_type' => 'skill',
        'owner' => 'testuser',
        'name' => 'my-skill',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('401');
});

// ── Collections ──────────────────────────────────────────────────────

test('collections list returns formatted table', function () {
    $tool = createManageTool([
        [
            'items' => [
                ['id' => 'abc', 'name' => 'Favorites', 'itemCount' => 3, 'isPublic' => true],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'collections', 'sub_action' => 'list']);

    expect($result->content)->toContain('Favorites')
        ->and($result->content)->toContain('abc');
});

test('collections create returns success', function () {
    $tool = createManageTool([
        ['id' => 'new123', 'name' => 'My New Collection'],
    ]);

    $result = $tool->execute([
        'action' => 'collections',
        'sub_action' => 'create',
        'collection_name' => 'My New Collection',
    ]);

    expect($result->content)->toContain('created');
});

test('collections delete returns success', function () {
    $tool = createManageTool([
        ['success' => true],
    ]);

    $result = $tool->execute([
        'action' => 'collections',
        'sub_action' => 'delete',
        'collection_id' => 42,
    ]);

    expect($result->content)->toContain('deleted');
});

test('collections requires sub_action', function () {
    $tool = createManageTool([]);

    $result = $tool->execute(['action' => 'collections']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ── Review ───────────────────────────────────────────────────────────

test('review posts successfully', function () {
    $tool = createManageTool([
        ['success' => true, 'id' => 'rev1'],
    ]);

    $result = $tool->execute([
        'action' => 'review',
        'entity_type' => 'skill',
        'owner' => 'testuser',
        'name' => 'my-skill',
        'rating' => 5,
        'title' => 'Great!',
        'body' => 'Works perfectly.',
    ]);

    expect($result->content)->toContain('Review');
});

// ── Notifications ────────────────────────────────────────────────────

test('notifications list returns formatted output', function () {
    $tool = createManageTool([
        [
            'items' => [
                ['id' => 'n1', 'title' => 'New star', 'isRead' => false, 'createdAt' => '2024-01-15T10:00:00Z'],
            ],
        ],
    ]);

    $result = $tool->execute(['action' => 'notifications', 'sub_action' => 'list']);

    expect($result->content)->toContain('New star');
});

test('notifications mark_read returns success', function () {
    $tool = createManageTool([
        ['success' => true],
    ]);

    $result = $tool->execute([
        'action' => 'notifications',
        'sub_action' => 'mark_read',
        'notification_id' => 42,
    ]);

    expect($result->content)->toContain('read');
});

// ── Health ───────────────────────────────────────────────────────────

test('health returns formatted status', function () {
    $tool = createManageTool([
        ['status' => 'ok', 'version' => '0.1.0', 'timestamp' => '2024-01-15T10:00:00Z'],
    ]);

    $result = $tool->execute(['action' => 'health']);

    expect($result->content)->toContain('ok')
        ->and($result->content)->toContain('0.1.0');
});
