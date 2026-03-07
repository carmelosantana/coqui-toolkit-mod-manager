<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\SpaceManager\SpaceManagerToolkit;

test('implements ToolkitInterface', function () {
    $toolkit = SpaceManagerToolkit::fromEnv(sys_get_temp_dir() . '/coqui-test-' . uniqid());

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('exposes exactly three tools', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = SpaceManagerToolkit::fromEnv($dir);

    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(3);
    expect($tools[0])->toBeInstanceOf(ToolInterface::class);
    expect($tools[1])->toBeInstanceOf(ToolInterface::class);
    expect($tools[2])->toBeInstanceOf(ToolInterface::class);
});

test('tool names are unique', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = SpaceManagerToolkit::fromEnv($dir);

    $names = array_map(
        fn(ToolInterface $tool) => $tool->name(),
        $toolkit->tools(),
    );

    expect($names)->toEqual(['space_skills', 'space_toolkits', 'space']);
    expect(count(array_unique($names)))->toBe(3);
});

test('all tools produce valid function schemas', function () {
    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = SpaceManagerToolkit::fromEnv($dir);

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
    $toolkit = SpaceManagerToolkit::fromEnv($dir);

    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString()
        ->and($guidelines)->toContain('space_skills')
        ->and($guidelines)->toContain('space_toolkits')
        ->and($guidelines)->toContain('space');
});

test('fromEnv resolves closures on each call', function () {
    putenv('COQUI_SPACE_URL=https://custom.example.com');
    putenv('COQUI_SPACE_API_TOKEN=cqs_test_token_123');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = SpaceManagerToolkit::fromEnv($dir);

    // Guidelines should reflect authenticated status
    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('authenticated');

    // Cleanup
    putenv('COQUI_SPACE_URL');
    putenv('COQUI_SPACE_API_TOKEN');
});

test('fromEnv defaults to anonymous when no token set', function () {
    putenv('COQUI_SPACE_API_TOKEN');

    $dir = sys_get_temp_dir() . '/coqui-test-' . uniqid();
    $toolkit = SpaceManagerToolkit::fromEnv($dir);

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('anonymous');

    putenv('COQUI_SPACE_API_TOKEN');
});
