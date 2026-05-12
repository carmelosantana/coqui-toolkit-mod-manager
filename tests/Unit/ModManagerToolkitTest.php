<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\ModManager\ModManagerToolkit;

test('implements ToolkitInterface', function () {
    $toolkit = ModManagerToolkit::fromEnv(sys_get_temp_dir() . '/coqui-test-' . uniqid());

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('exposes three tools without authentication', function () {
    putenv('COQUI_MODS_API_TOKEN');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(3);
    expect($tools[0])->toBeInstanceOf(ToolInterface::class);
    expect($tools[1])->toBeInstanceOf(ToolInterface::class);
    expect($tools[2])->toBeInstanceOf(ToolInterface::class);

    putenv('COQUI_MODS_API_TOKEN');
});

test('tool names are unique', function () {
    putenv('COQUI_MODS_API_TOKEN');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $names = array_map(
        fn(ToolInterface $tool) => $tool->name(),
        $toolkit->tools(),
    );

    expect($names)->toEqual(['mods_skills', 'mods_toolkits', 'mods']);
    expect(count(array_unique($names)))->toBe(3);

    putenv('COQUI_MODS_API_TOKEN');
});

test('exposes the same three tools when authenticated', function () {
    putenv('COQUI_MODS_API_TOKEN=cqs_test_token');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $tools = $toolkit->tools();
    $names = array_map(
        fn(ToolInterface $tool) => $tool->name(),
        $tools,
    );

    expect($tools)->toHaveCount(3)
        ->and($names)->toEqual(['mods_skills', 'mods_toolkits', 'mods']);

    putenv('COQUI_MODS_API_TOKEN');
});

test('guidelines point authenticated users to mod-publish for account actions', function () {
    putenv('COQUI_MODS_API_TOKEN=cqs_test_token');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('mod-publish toolkit')
        ->and($guidelines)->not->toContain('space_account');

    putenv('COQUI_MODS_API_TOKEN');
});

test('exposes client and installer accessors for core integration', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    expect($toolkit->client())->toBeObject()
        ->and($toolkit->skillInstaller())->toBeObject()
        ->and($toolkit->toolkitInstaller())->toBeObject();
});

test('guidelines exclude space_account tool row when anonymous', function () {
    putenv('COQUI_MODS_API_TOKEN');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->not->toContain('Your account dashboard');

    putenv('COQUI_MODS_API_TOKEN');
});

test('all tools produce valid function schemas', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toHaveKey('type')
            ->and($schema['type'])->toBe('function')
            ->and($schema['function'])->toHaveKey('name')
            ->and($schema['function'])->toHaveKey('description')
            ->and($schema['function'])->toHaveKey('parameters')
            ->and($schema['function']['parameters'])->toHaveKey('properties')
            ->and($schema['function']['parameters'])->toHaveKey('required');
    }
});

test('guidelines returns non-empty string', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString()
        ->and($guidelines)->toContain('mods_skills')
        ->and($guidelines)->toContain('mods_toolkits')
        ->and($guidelines)->toContain('mods');
});

test('fromEnv resolves closures on each call', function () {
    putenv('COQUI_MODS_URL=https://custom.example.com');
    putenv('COQUI_MODS_API_TOKEN=cqs_test_token_123');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    // Guidelines should reflect authenticated status
    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('authenticated');

    // Cleanup
    putenv('COQUI_MODS_URL');
    putenv('COQUI_MODS_API_TOKEN');
});

test('fromEnv defaults to anonymous when no token set', function () {
    putenv('COQUI_MODS_API_TOKEN');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = ModManagerToolkit::fromEnv($dir);

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('anonymous');

    putenv('COQUI_MODS_API_TOKEN');
});
