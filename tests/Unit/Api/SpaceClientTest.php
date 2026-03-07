<?php

declare(strict_types=1);

use CoquiBot\SpaceManager\Api\SpaceClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function createClient(MockHttpClient $http): SpaceClient
{
    return new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => 'cqs_test_token',
        $http,
    );
}

// ── Skills ───────────────────────────────────────────────────────

test('searchSkills sends correct request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'results' => [['name' => 'test-skill', 'slug' => 'test-skill']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->searchSkills('test', 5);

    expect($result)->toHaveKey('results')
        ->and($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['name'])->toBe('test-skill');
});

test('skillDetails fetches by owner and name', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'name' => 'my-skill',
            'slug' => 'my-skill',
            'owner' => 'testuser',
        ])),
    ]);

    $client = createClient($http);
    $result = $client->skillDetails('testuser', 'my-skill');

    expect($result)->toHaveKey('name')
        ->and($result['name'])->toBe('my-skill');
});

test('skillVersions returns version list', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'versions' => [['version' => '1.0.0', 'createdAt' => '2024-01-01']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->skillVersions('testuser', 'my-skill');

    expect($result)->toHaveKey('versions')
        ->and($result['versions'])->toHaveCount(1);
});

// ── Toolkits ─────────────────────────────────────────────────────

test('searchToolkits sends query', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'results' => [['name' => 'brave-search', 'package' => 'coquibot/coqui-toolkit-brave-search']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->searchToolkits('brave');

    expect($result)->toHaveKey('results')
        ->and($result['results'])->toHaveCount(1);
});

test('toolkitDetails fetches by owner and name', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'name' => 'brave-search',
            'package' => 'coquibot/coqui-toolkit-brave-search',
        ])),
    ]);

    $client = createClient($http);
    $result = $client->toolkitDetails('coquibot', 'brave-search');

    expect($result)->toHaveKey('name')
        ->and($result['name'])->toBe('brave-search');
});

test('popularToolkits returns results', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'results' => [['name' => 'top-toolkit']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->popularToolkits(5);

    expect($result)->toHaveKey('results')
        ->and($result['results'])->toHaveCount(1);
});

// ── Social ───────────────────────────────────────────────────────

test('star sends POST request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['starred' => true])),
    ]);

    $client = createClient($http);
    $result = $client->star('skill', 'testuser', 'my-skill');

    expect($result)->toHaveKey('starred');
});

test('unstar sends DELETE request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['unstarred' => true])),
    ]);

    $client = createClient($http);
    $result = $client->unstar('skill', 'testuser', 'my-skill');

    expect($result)->toHaveKey('unstarred');
});

// ── Error Handling ───────────────────────────────────────────────

test('API error throws RuntimeException', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Not found"}', ['http_code' => 404]),
    ]);

    $client = createClient($http);
    $client->skillDetails('testuser', 'nonexistent');
})->throws(\RuntimeException::class);

test('anonymous client omits auth header', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['results' => []])),
    ]);

    $client = new SpaceClient(
        static fn(): string => 'https://coqui.space/api/v1',
        static fn(): string => '',
        $http,
    );

    $result = $client->searchSkills('test');
    expect($result)->toHaveKey('results');
});
