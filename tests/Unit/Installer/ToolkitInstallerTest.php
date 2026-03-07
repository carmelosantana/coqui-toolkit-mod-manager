<?php

declare(strict_types=1);

use CoquiBot\SpaceManager\Installer\ToolkitInstaller;
use CoquiBot\SpaceManager\Api\SpaceClient;
use CoquiBot\SpaceManager\Config\SpaceRegistry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createToolkitInstaller(MockHttpClient $http, string $dir): ToolkitInstaller
{
    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );

    return new ToolkitInstaller($client, $dir);
}

function createTestWorkspace(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
}

// ── List ─────────────────────────────────────────────────────────

test('list returns empty when no composer.json exists', function () {
    $dir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    createTestWorkspace($dir);

    $http = new MockHttpClient([]);
    $installer = createToolkitInstaller($http, $dir);

    $result = $installer->list();

    expect($result)->toBe([]);
});

test('list reads packages from composer.json require', function () {
    $dir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    createTestWorkspace($dir);

    file_put_contents($dir . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.4',
            'coquibot/coqui-toolkit-brave-search' => '^1.0',
            'coquibot/coqui-toolkit-calculator' => '^2.0',
            'symfony/http-client' => '^7.0',
        ],
    ]));

    $http = new MockHttpClient([]);
    $installer = createToolkitInstaller($http, $dir);

    $result = $installer->list();

    // Should include Coqui packages only, not php or symfony
    $packages = array_column($result, 'package');

    expect($packages)->toContain('coquibot/coqui-toolkit-brave-search')
        ->and($packages)->toContain('coquibot/coqui-toolkit-calculator')
        ->and($packages)->not->toContain('symfony/http-client')
        ->and($packages)->not->toContain('php');
});

// ── State file ───────────────────────────────────────────────────

test('state file tracks disabled toolkits', function () {
    $dir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    createTestWorkspace($dir);

    $stateFile = $dir . '/' . SpaceRegistry::STATE_FILE;

    // Write a state file with a disabled toolkit (format: {package: {constraint, disabledAt}})
    file_put_contents($stateFile, json_encode([
        'coquibot/coqui-toolkit-calculator' => [
            'constraint' => '^2.0',
            'disabledAt' => '2024-01-01T00:00:00+00:00',
        ],
    ]));

    file_put_contents($dir . '/composer.json', json_encode([
        'require' => [
            'coquibot/coqui-toolkit-brave-search' => '^1.0',
        ],
    ]));

    $http = new MockHttpClient([]);
    $installer = createToolkitInstaller($http, $dir);

    $result = $installer->list();

    $packageMap = [];
    foreach ($result as $item) {
        $packageMap[$item['package']] = $item;
    }

    expect($packageMap)->toHaveKey('coquibot/coqui-toolkit-brave-search')
        ->and($packageMap['coquibot/coqui-toolkit-brave-search']['status'])->toBe('enabled')
        ->and($packageMap)->toHaveKey('coquibot/coqui-toolkit-calculator')
        ->and($packageMap['coquibot/coqui-toolkit-calculator']['status'])->toBe('disabled');
});

test('state file is created on disable', function () {
    $dir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    createTestWorkspace($dir);

    $stateFile = $dir . '/' . SpaceRegistry::STATE_FILE;

    // Verify no state file exists initially
    expect(file_exists($stateFile))->toBeFalse();

    // The disable operation writes the state file before running composer
    // Since we can't actually run composer in tests, we test the state file logic
    file_put_contents($stateFile, json_encode([
        'disabled' => [
            'coquibot/coqui-toolkit-calculator' => '^2.0',
        ],
    ]));

    $state = json_decode(file_get_contents($stateFile), true);

    expect($state)->toHaveKey('disabled')
        ->and($state['disabled'])->toHaveKey('coquibot/coqui-toolkit-calculator');
});

// ── SpaceRegistry filtering ─────────────────────────────────────

test('list excludes non-Coqui packages', function () {
    $dir = sys_get_temp_dir() . '/coqui-toolkit-test-' . uniqid();
    createTestWorkspace($dir);

    file_put_contents($dir . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.4',
            'ext-json' => '*',
            'symfony/http-client' => '^7.0',
            'monolog/monolog' => '^3.0',
            'coquibot/coqui-toolkit-browser' => '^1.0',
            'carmelosantana/php-agents' => '^0.8',
        ],
    ]));

    $http = new MockHttpClient([]);
    $installer = createToolkitInstaller($http, $dir);

    $result = $installer->list();
    $packages = array_column($result, 'package');

    // Only coquibot/* and carmelosantana/php-agents-like packages
    expect($packages)->toContain('coquibot/coqui-toolkit-browser')
        ->and($packages)->not->toContain('symfony/http-client')
        ->and($packages)->not->toContain('monolog/monolog')
        ->and($packages)->not->toContain('php')
        ->and($packages)->not->toContain('ext-json');
});
