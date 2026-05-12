<?php

declare(strict_types=1);

use CoquiBot\ModManager\Installer\SkillInstaller;
use CoquiBot\ModManager\Api\ModClient;
use CoquiBot\ModManager\Config\ModRegistry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createSkillInstaller(MockHttpClient $http, string $dir): SkillInstaller
{
    $client = new ModClient(
        static fn(): string => 'https://agentcoqui.com/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );

    return new SkillInstaller($client, $dir);
}

function createTestSkillDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
}

// ── List ─────────────────────────────────────────────────────────

test('list returns empty when no skills installed', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->list();

    expect($result)->toBe([]);
});

test('list detects installed skills', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    // Create a fake installed skill
    $skillDir = $dir . '/my-cool-skill';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: My Cool Skill\nversion: 1.0.0\n---\nA test skill.");

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->list();

    expect($result)->toHaveCount(1)
        ->and($result[0]['name'])->toBe('my-cool-skill')
        ->and($result[0]['status'])->toBe('enabled');
});

test('list detects disabled skills', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    // Create a disabled skill
    $skillDir = $dir . '/old-skill.disabled';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: Old Skill\nversion: 0.5.0\n---\nDeprecated.");

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->list();

    expect($result)->toHaveCount(1)
        ->and($result[0]['name'])->toBe('old-skill')
        ->and($result[0]['status'])->toBe('disabled');
});

// ── Disable / Enable ─────────────────────────────────────────────

test('disable renames directory with .disabled suffix', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $skillDir = $dir . '/test-skill';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: Test\n---\n");

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->disable('test-skill');

    expect($result)->toContain('disabled')
        ->and(is_dir($dir . '/test-skill.disabled'))->toBeTrue()
        ->and(is_dir($dir . '/test-skill'))->toBeFalse();
});

test('enable restores disabled directory', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $skillDir = $dir . '/test-skill.disabled';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: Test\n---\n");

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->enable('test-skill');

    expect($result)->toContain('enabled')
        ->and(is_dir($dir . '/test-skill'))->toBeTrue()
        ->and(is_dir($dir . '/test-skill.disabled'))->toBeFalse();
});

// ── Remove ───────────────────────────────────────────────────────

test('remove without purge disables the skill', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $skillDir = $dir . '/removable-skill';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: Removable\n---\n");

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->remove('removable-skill', false);

    expect($result)->toContain('disabled')
        ->and(is_dir($dir . '/removable-skill.disabled'))->toBeTrue();
});

test('remove with purge deletes the directory', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $skillDir = $dir . '/deletable-skill';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: Deletable\n---\n");

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->remove('deletable-skill', true);

    expect($result)->toContain('removed')
        ->and(is_dir($dir . '/deletable-skill'))->toBeFalse();
});

test('disable fails for non-existent skill', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $installer->disable('does-not-exist');
})->throws(\RuntimeException::class);

// ── Origin tracking ──────────────────────────────────────────────

test('list reads origin file when present', function () {
    $dir = sys_get_temp_dir() . '/coqui-skill-test-' . uniqid();
    createTestSkillDir($dir);

    $skillDir = $dir . '/tracked-skill';
    mkdir($skillDir, 0o755, true);
    file_put_contents($skillDir . '/SKILL.md', "---\nname: Tracked\nversion: 2.0.0\n---\n");
    file_put_contents(
        $skillDir . '/' . ModRegistry::ORIGIN_FILE,
        json_encode([
            'source' => 'coqui.mods',
            'owner' => 'testuser',
            'slug' => 'tracked-skill',
            'version' => '2.0.0',
            'installedAt' => '2024-01-01T00:00:00Z',
        ]),
    );

    $http = new MockHttpClient([]);
    $installer = createSkillInstaller($http, $dir);

    $result = $installer->list();

    expect($result)->toHaveCount(1)
        ->and($result[0]['source'])->toBe('coqui.mods')
        ->and($result[0]['owner'])->toBe('testuser');
});
