<?php

declare(strict_types=1);

use CoquiBot\ModManager\Config\ModRegistry;

// ── Constants ────────────────────────────────────────────────────

test('DEFAULT_BASE_URL is agentcoqui', function () {
    expect(ModRegistry::DEFAULT_BASE_URL)->toBe('https://agentcoqui.com/api/v1');
});

test('ORIGIN_FILE is .mods-origin.json', function () {
    expect(ModRegistry::ORIGIN_FILE)->toBe('.mods-origin.json');
});

test('STATE_FILE is .mods-state.json', function () {
    expect(ModRegistry::STATE_FILE)->toBe('.mods-state.json');
});

// ── isExcluded ───────────────────────────────────────────────────

test('isExcluded returns true for core packages', function () {
    expect(ModRegistry::isExcluded('coquibot/coqui-toolkit-mod-manager'))->toBeTrue()
        ->and(ModRegistry::isExcluded('coquibot/coqui-toolkit-composer'))->toBeTrue()
        ->and(ModRegistry::isExcluded('carmelosantana/php-agents'))->toBeTrue();
});

test('isExcluded is case insensitive', function () {
    expect(ModRegistry::isExcluded('CoquiBot/Coqui-Toolkit-Mod-Manager'))->toBeTrue()
        ->and(ModRegistry::isExcluded('CARMELOSANTANA/PHP-AGENTS'))->toBeTrue();
});

test('isExcluded trims whitespace', function () {
    expect(ModRegistry::isExcluded('  coquibot/coqui-toolkit-mod-manager  '))->toBeTrue();
});

test('isExcluded returns false for non-core packages', function () {
    expect(ModRegistry::isExcluded('coquibot/coqui-toolkit-browser'))->toBeFalse()
        ->and(ModRegistry::isExcluded('acme/some-package'))->toBeFalse()
        ->and(ModRegistry::isExcluded('symfony/http-client'))->toBeFalse();
});

// ── filterExcluded ───────────────────────────────────────────────

test('filterExcluded removes excluded strings', function () {
    $items = [
        'coquibot/coqui-toolkit-browser',
        'coquibot/coqui-toolkit-mod-manager',
        'acme/cool-toolkit',
    ];

    $result = ModRegistry::filterExcluded($items);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe('coquibot/coqui-toolkit-browser')
        ->and($result[1])->toBe('acme/cool-toolkit');
});

test('filterExcluded removes excluded arrays with package key', function () {
    $items = [
        ['package' => 'coquibot/coqui-toolkit-browser', 'status' => 'enabled'],
        ['package' => 'carmelosantana/php-agents', 'status' => 'enabled'],
    ];

    $result = ModRegistry::filterExcluded($items);

    expect($result)->toHaveCount(1)
        ->and($result[0]['package'])->toBe('coquibot/coqui-toolkit-browser');
});

test('filterExcluded removes excluded arrays with name key', function () {
    $items = [
        ['name' => 'coquibot/coqui-toolkit-browser'],
        ['name' => 'coquibot/coqui-toolkit-composer'],
    ];

    $result = ModRegistry::filterExcluded($items);

    expect($result)->toHaveCount(1);
});

test('filterExcluded returns empty for all excluded', function () {
    $items = [
        'coquibot/coqui-toolkit-mod-manager',
        'carmelosantana/php-agents',
    ];

    $result = ModRegistry::filterExcluded($items);

    expect($result)->toBe([]);
});

test('filterExcluded re-indexes array values', function () {
    $items = [
        'coquibot/coqui-toolkit-mod-manager',
        'coquibot/coqui-toolkit-browser',
    ];

    $result = ModRegistry::filterExcluded($items);

    expect(array_keys($result))->toBe([0]);
});

// ── looksLikeCoquiPackage ────────────────────────────────────────

test('looksLikeCoquiPackage matches coquibot/ prefix', function () {
    expect(ModRegistry::looksLikeCoquiPackage('coquibot/coqui-toolkit-browser'))->toBeTrue()
        ->and(ModRegistry::looksLikeCoquiPackage('coquibot/anything'))->toBeTrue();
});

test('looksLikeCoquiPackage matches coqui-bot/ prefix', function () {
    expect(ModRegistry::looksLikeCoquiPackage('coqui-bot/some-package'))->toBeTrue();
});

test('looksLikeCoquiPackage matches coqui-toolkit- anywhere', function () {
    expect(ModRegistry::looksLikeCoquiPackage('acme/coqui-toolkit-custom'))->toBeTrue();
});

test('looksLikeCoquiPackage matches coqui-mod- anywhere', function () {
    expect(ModRegistry::looksLikeCoquiPackage('acme/coqui-mod-admin'))->toBeTrue();
});

test('looksLikeCoquiPackage is case insensitive', function () {
    expect(ModRegistry::looksLikeCoquiPackage('CoquiBot/Something'))->toBeTrue()
        ->and(ModRegistry::looksLikeCoquiPackage('COQUIBOT/TEST'))->toBeTrue();
});

test('looksLikeCoquiPackage rejects non-Coqui packages', function () {
    expect(ModRegistry::looksLikeCoquiPackage('symfony/http-client'))->toBeFalse()
        ->and(ModRegistry::looksLikeCoquiPackage('monolog/monolog'))->toBeFalse()
        ->and(ModRegistry::looksLikeCoquiPackage('php'))->toBeFalse();
});

// ── extractOwner ─────────────────────────────────────────────────

test('extractOwner handles string owner', function () {
    $item = ['owner' => 'testuser'];

    expect(ModRegistry::extractOwner($item))->toBe('testuser');
});

test('extractOwner handles object owner with handle', function () {
    $item = ['owner' => ['handle' => 'testuser', 'displayName' => 'Test User']];

    expect(ModRegistry::extractOwner($item))->toBe('testuser');
});

test('extractOwner returns empty for missing owner', function () {
    $item = ['name' => 'some-skill'];

    expect(ModRegistry::extractOwner($item))->toBe('');
});

test('extractOwner handles object owner without handle', function () {
    $item = ['owner' => ['displayName' => 'Test User']];

    expect(ModRegistry::extractOwner($item))->toBe('');
});

test('extractOwner handles empty array owner', function () {
    $item = ['owner' => []];

    expect(ModRegistry::extractOwner($item))->toBe('');
});
