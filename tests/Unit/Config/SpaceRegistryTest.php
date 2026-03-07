<?php

declare(strict_types=1);

use CoquiBot\SpaceManager\Config\SpaceRegistry;

// ── Constants ────────────────────────────────────────────────────

test('DEFAULT_BASE_URL is coqui.space', function () {
    expect(SpaceRegistry::DEFAULT_BASE_URL)->toBe('https://coqui.space/api/v1');
});

test('ORIGIN_FILE is .space-origin.json', function () {
    expect(SpaceRegistry::ORIGIN_FILE)->toBe('.space-origin.json');
});

test('STATE_FILE is .space-state.json', function () {
    expect(SpaceRegistry::STATE_FILE)->toBe('.space-state.json');
});

// ── isExcluded ───────────────────────────────────────────────────

test('isExcluded returns true for core packages', function () {
    expect(SpaceRegistry::isExcluded('coquibot/coqui-space-manager'))->toBeTrue()
        ->and(SpaceRegistry::isExcluded('coquibot/coqui-toolkit-composer'))->toBeTrue()
        ->and(SpaceRegistry::isExcluded('carmelosantana/php-agents'))->toBeTrue();
});

test('isExcluded is case insensitive', function () {
    expect(SpaceRegistry::isExcluded('CoquiBot/Coqui-Space-Manager'))->toBeTrue()
        ->and(SpaceRegistry::isExcluded('CARMELOSANTANA/PHP-AGENTS'))->toBeTrue();
});

test('isExcluded trims whitespace', function () {
    expect(SpaceRegistry::isExcluded('  coquibot/coqui-space-manager  '))->toBeTrue();
});

test('isExcluded returns false for non-core packages', function () {
    expect(SpaceRegistry::isExcluded('coquibot/coqui-toolkit-browser'))->toBeFalse()
        ->and(SpaceRegistry::isExcluded('acme/some-package'))->toBeFalse()
        ->and(SpaceRegistry::isExcluded('symfony/http-client'))->toBeFalse();
});

// ── filterExcluded ───────────────────────────────────────────────

test('filterExcluded removes excluded strings', function () {
    $items = [
        'coquibot/coqui-toolkit-browser',
        'coquibot/coqui-space-manager',
        'acme/cool-toolkit',
    ];

    $result = SpaceRegistry::filterExcluded($items);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toBe('coquibot/coqui-toolkit-browser')
        ->and($result[1])->toBe('acme/cool-toolkit');
});

test('filterExcluded removes excluded arrays with package key', function () {
    $items = [
        ['package' => 'coquibot/coqui-toolkit-browser', 'status' => 'enabled'],
        ['package' => 'carmelosantana/php-agents', 'status' => 'enabled'],
    ];

    $result = SpaceRegistry::filterExcluded($items);

    expect($result)->toHaveCount(1)
        ->and($result[0]['package'])->toBe('coquibot/coqui-toolkit-browser');
});

test('filterExcluded removes excluded arrays with name key', function () {
    $items = [
        ['name' => 'coquibot/coqui-toolkit-browser'],
        ['name' => 'coquibot/coqui-toolkit-composer'],
    ];

    $result = SpaceRegistry::filterExcluded($items);

    expect($result)->toHaveCount(1);
});

test('filterExcluded returns empty for all excluded', function () {
    $items = [
        'coquibot/coqui-space-manager',
        'carmelosantana/php-agents',
    ];

    $result = SpaceRegistry::filterExcluded($items);

    expect($result)->toBe([]);
});

test('filterExcluded re-indexes array values', function () {
    $items = [
        'coquibot/coqui-space-manager',
        'coquibot/coqui-toolkit-browser',
    ];

    $result = SpaceRegistry::filterExcluded($items);

    expect(array_keys($result))->toBe([0]);
});

// ── looksLikeCoquiPackage ────────────────────────────────────────

test('looksLikeCoquiPackage matches coquibot/ prefix', function () {
    expect(SpaceRegistry::looksLikeCoquiPackage('coquibot/coqui-toolkit-browser'))->toBeTrue()
        ->and(SpaceRegistry::looksLikeCoquiPackage('coquibot/anything'))->toBeTrue();
});

test('looksLikeCoquiPackage matches coqui-bot/ prefix', function () {
    expect(SpaceRegistry::looksLikeCoquiPackage('coqui-bot/some-package'))->toBeTrue();
});

test('looksLikeCoquiPackage matches coqui-toolkit- anywhere', function () {
    expect(SpaceRegistry::looksLikeCoquiPackage('acme/coqui-toolkit-custom'))->toBeTrue();
});

test('looksLikeCoquiPackage matches coqui-space- anywhere', function () {
    expect(SpaceRegistry::looksLikeCoquiPackage('acme/coqui-space-admin'))->toBeTrue();
});

test('looksLikeCoquiPackage is case insensitive', function () {
    expect(SpaceRegistry::looksLikeCoquiPackage('CoquiBot/Something'))->toBeTrue()
        ->and(SpaceRegistry::looksLikeCoquiPackage('COQUIBOT/TEST'))->toBeTrue();
});

test('looksLikeCoquiPackage rejects non-Coqui packages', function () {
    expect(SpaceRegistry::looksLikeCoquiPackage('symfony/http-client'))->toBeFalse()
        ->and(SpaceRegistry::looksLikeCoquiPackage('monolog/monolog'))->toBeFalse()
        ->and(SpaceRegistry::looksLikeCoquiPackage('php'))->toBeFalse();
});

// ── extractOwner ─────────────────────────────────────────────────

test('extractOwner handles string owner', function () {
    $item = ['owner' => 'testuser'];

    expect(SpaceRegistry::extractOwner($item))->toBe('testuser');
});

test('extractOwner handles object owner with handle', function () {
    $item = ['owner' => ['handle' => 'testuser', 'displayName' => 'Test User']];

    expect(SpaceRegistry::extractOwner($item))->toBe('testuser');
});

test('extractOwner returns empty for missing owner', function () {
    $item = ['name' => 'some-skill'];

    expect(SpaceRegistry::extractOwner($item))->toBe('');
});

test('extractOwner handles object owner without handle', function () {
    $item = ['owner' => ['displayName' => 'Test User']];

    expect(SpaceRegistry::extractOwner($item))->toBe('');
});

test('extractOwner handles empty array owner', function () {
    $item = ['owner' => []];

    expect(SpaceRegistry::extractOwner($item))->toBe('');
});
