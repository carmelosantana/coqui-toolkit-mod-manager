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

test('searchSkills passes cursor parameter', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'results' => [],
            'nextCursor' => null,
        ])),
    ]);

    $client = createClient($http);
    $result = $client->searchSkills('test', 5, 'abc123');

    expect($result)->toHaveKey('results');
});

test('listSkills sends sort and tags', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['name' => 'trending-skill']],
            'nextCursor' => 'next123',
        ])),
    ]);

    $client = createClient($http);
    $result = $client->listSkills('downloads', 'code-review,testing', 10, null);

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['nextCursor'])->toBe('next123');
});

test('listSkills works without optional params', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['items' => []])),
    ]);

    $client = createClient($http);
    $result = $client->listSkills();

    expect($result)->toHaveKey('items');
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

test('skillReviews returns reviews with stats', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['rating' => 5, 'body' => 'Great skill!']],
            'stats' => ['average' => 4.5, 'count' => 10],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->skillReviews('testuser', 'my-skill', 5);

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['stats']['average'])->toBe(4.5);
});

test('skillFile returns raw content', function () {
    $http = new MockHttpClient([
        new MockResponse("---\nname: Test\n---\nA skill."),
    ]);

    $client = createClient($http);
    $result = $client->skillFile('testuser', 'my-skill');

    expect($result)->toContain('name: Test');
});

test('createSkill sends POST with data', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['id' => 'abc', 'status' => 'pending'])),
    ]);

    $client = createClient($http);
    $result = $client->createSkill(['name' => 'new-skill', 'description' => 'A new skill']);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('pending');
});

test('updateSkill sends PUT', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['updated' => true])),
    ]);

    $client = createClient($http);
    $result = $client->updateSkill('testuser', 'my-skill', ['description' => 'Updated']);

    expect($result)->toHaveKey('updated');
});

// ── Toolkits ─────────────────────────────────────────────────────

test('listToolkits sends cursor-paginated request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [
                ['packageName' => 'coquibot/coqui-toolkit-brave-search', 'downloads' => 500],
            ],
            'nextCursor' => 'cursor_abc',
        ])),
    ]);

    $client = createClient($http);
    $result = $client->listToolkits('downloads', 'search', 10, null);

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['nextCursor'])->toBe('cursor_abc');
});

test('listToolkits works with defaults', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['items' => [], 'nextCursor' => null])),
    ]);

    $client = createClient($http);
    $result = $client->listToolkits();

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toBe([]);
});

test('listToolkits passes cursor for pagination', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['items' => [], 'nextCursor' => null])),
    ]);

    $client = createClient($http);
    $result = $client->listToolkits('name', null, 20, 'prev_cursor');

    expect($result)->toHaveKey('items');
});

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

test('toolkitPackage fetches Packagist metadata', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'package' => [
                'name' => 'coquibot/coqui-toolkit-browser',
                'versions' => ['1.0.0' => []],
            ],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->toolkitPackage('coquibot', 'coqui-toolkit-browser');

    expect($result)->toHaveKey('package')
        ->and($result['package']['name'])->toBe('coquibot/coqui-toolkit-browser');
});

test('toolkitReviews returns reviews', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['rating' => 4, 'title' => 'Good toolkit']],
            'stats' => ['average' => 4.0, 'count' => 5],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->toolkitReviews('coquibot', 'browser', 10, null);

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1);
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

test('createToolkit sends POST', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'status' => 'published',
            'verified_publisher' => true,
        ])),
    ]);

    $client = createClient($http);
    $result = $client->createToolkit(['packageName' => 'coquibot/coqui-toolkit-test']);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('published');
});

test('logToolkitInstall sends POST with client version', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['ok' => true])),
    ]);

    $client = createClient($http);
    $result = $client->logToolkitInstall('coquibot', 'browser', 'coqui-space-manager/0.1.0');

    expect($result)->toHaveKey('ok');
});

test('logToolkitInstall works without client version', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['ok' => true])),
    ]);

    $client = createClient($http);
    $result = $client->logToolkitInstall('coquibot', 'browser');

    expect($result)->toHaveKey('ok');
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

test('me returns user profile', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'handle' => 'testuser',
            'displayName' => 'Test User',
        ])),
    ]);

    $client = createClient($http);
    $result = $client->me();

    expect($result)->toHaveKey('handle')
        ->and($result['handle'])->toBe('testuser');
});

// ── Submissions ──────────────────────────────────────────────────

test('createSubmission sends POST', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['id' => '42', 'status' => 'pending'])),
    ]);

    $client = createClient($http);
    $result = $client->createSubmission('skill', 'https://github.com/user/repo', 'Please review');

    expect($result)->toHaveKey('id')
        ->and($result['status'])->toBe('pending');
});

test('createSubmission works without notes', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['id' => '43'])),
    ]);

    $client = createClient($http);
    $result = $client->createSubmission('toolkit', 'https://github.com/user/package');

    expect($result)->toHaveKey('id');
});

// ── Tags ─────────────────────────────────────────────────────────

test('getTags returns all tags by default', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'skills' => [
                ['slug' => 'code-review', 'name' => 'Code Review'],
            ],
            'toolkits' => [
                ['slug' => 'search', 'name' => 'Search'],
            ],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->getTags();

    expect($result)->toHaveKey('skills')
        ->and($result)->toHaveKey('toolkits')
        ->and($result['skills'])->toHaveCount(1)
        ->and($result['toolkits'])->toHaveCount(1);
});

test('getTags filters by type', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'skills' => [['slug' => 'testing']],
            'toolkits' => [],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->getTags('skills');

    expect($result)->toHaveKey('skills');
});

// ── Unified Search ───────────────────────────────────────────────

test('searchAll returns combined results', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'skills' => [
                'results' => [['name' => 'code-review', 'owner' => 'testuser']],
            ],
            'toolkits' => [
                'results' => [['name' => 'coquibot/coqui-toolkit-browser']],
                'total' => 1,
            ],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->searchAll('code');

    expect($result)->toHaveKey('skills')
        ->and($result)->toHaveKey('toolkits')
        ->and($result['skills']['results'])->toHaveCount(1)
        ->and($result['toolkits']['results'])->toHaveCount(1);
});

test('searchAll passes cursor parameter', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'skills' => ['results' => []],
            'toolkits' => ['results' => [], 'total' => 0],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->searchAll('test', 5, 'cursor_xyz');

    expect($result)->toHaveKey('skills')
        ->and($result)->toHaveKey('toolkits');
});

// ── Error Handling ───────────────────────────────────────────────

test('API error throws RuntimeException', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Not found"}', ['http_code' => 404]),
    ]);

    $client = createClient($http);
    $client->skillDetails('testuser', 'nonexistent');
})->throws(\RuntimeException::class);

test('POST error throws RuntimeException', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Unauthorized"}', ['http_code' => 401]),
    ]);

    $client = createClient($http);
    $client->star('skill', 'testuser', 'my-skill');
})->throws(\RuntimeException::class);

test('PUT error throws RuntimeException', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Forbidden"}', ['http_code' => 403]),
    ]);

    $client = createClient($http);
    $client->updateSkill('testuser', 'my-skill', ['description' => 'Updated']);
})->throws(\RuntimeException::class);

test('DELETE error throws RuntimeException', function () {
    $http = new MockHttpClient([
        new MockResponse('{"error": "Not found"}', ['http_code' => 404]),
    ]);

    $client = createClient($http);
    $client->unstar('skill', 'testuser', 'nonexistent');
})->throws(\RuntimeException::class);

test('invalid JSON response throws RuntimeException', function () {
    $http = new MockHttpClient([
        new MockResponse('not json at all'),
    ]);

    $client = createClient($http);
    $client->searchSkills('test');
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

test('url resolver is called for each request', function () {
    $callCount = 0;
    $http = new MockHttpClient([
        new MockResponse(json_encode(['results' => []])),
        new MockResponse(json_encode(['results' => []])),
    ]);

    $client = new SpaceClient(
        static function () use (&$callCount): string {
            $callCount++;
            return 'https://coqui.space/api/v1';
        },
        static fn(): string => '',
        $http,
    );

    $client->searchSkills('first');
    $client->searchSkills('second');

    expect($callCount)->toBe(2);
});

// ── New API Methods ──────────────────────────────────────────────────

test('deleteSkill sends delete request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['success' => true])),
    ]);

    $client = createClient($http);
    $result = $client->deleteSkill('testuser', 'my-skill');

    expect($result)->toHaveKey('success')
        ->and($result['success'])->toBeTrue();
});

test('logSkillInstall sends post request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['logged' => true])),
    ]);

    $client = createClient($http);
    $result = $client->logSkillInstall('testuser', 'my-skill');

    expect($result)->toHaveKey('logged');
});

test('deleteToolkit sends delete request', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['success' => true])),
    ]);

    $client = createClient($http);
    $result = $client->deleteToolkit('testuser', 'my-toolkit');

    expect($result)->toHaveKey('success');
});

test('createReview sends review data', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['id' => 'rev1', 'rating' => 5])),
    ]);

    $client = createClient($http);
    $result = $client->createReview('skill', 'testuser', 'my-skill', 5, 'Great!', 'Works perfectly.');

    expect($result)->toHaveKey('id')
        ->and($result['rating'])->toBe(5);
});

test('listCollections returns items', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['id' => 'c1', 'name' => 'Favorites']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->listCollections();

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1);
});

test('createCollection returns new collection', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['id' => 'new1', 'name' => 'Test Collection'])),
    ]);

    $client = createClient($http);
    $result = $client->createCollection('Test Collection', 'A test', true);

    expect($result)->toHaveKey('id')
        ->and($result['name'])->toBe('Test Collection');
});

test('getCollection returns collection with items', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'id' => 1,
            'name' => 'Favorites',
            'items' => [['entityType' => 'skill', 'name' => 'code-review']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->getCollection(1);

    expect($result)->toHaveKey('items')
        ->and($result['name'])->toBe('Favorites');
});

test('healthCheck returns status', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['status' => 'ok', 'version' => '0.1.0'])),
    ]);

    $client = createClient($http);
    $result = $client->healthCheck();

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('ok');
});

test('mySkills returns user skills', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['name' => 'my-skill', 'status' => 'published']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->mySkills();

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1);
});

test('myCollections returns user collections', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['id' => 'c1', 'name' => 'Favorites']],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->myCollections();

    expect($result)->toHaveKey('items');
});

test('myAnalytics returns analytics data', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'totalDownloads' => 500,
            'totalStars' => 20,
        ])),
    ]);

    $client = createClient($http);
    $result = $client->myAnalytics(30);

    expect($result)->toHaveKey('totalDownloads')
        ->and($result['totalDownloads'])->toBe(500);
});

test('myNotifications returns notifications', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode([
            'items' => [['id' => 'n1', 'title' => 'New star', 'isRead' => false]],
        ])),
    ]);

    $client = createClient($http);
    $result = $client->myNotifications();

    expect($result)->toHaveKey('items')
        ->and($result['items'])->toHaveCount(1);
});

test('patch method works for updates', function () {
    $http = new MockHttpClient([
        new MockResponse(json_encode(['id' => 1, 'name' => 'Updated'])),
    ]);

    $client = createClient($http);
    $result = $client->updateCollection(1, ['name' => 'Updated', 'description' => 'New desc']);

    expect($result)->toHaveKey('name')
        ->and($result['name'])->toBe('Updated');
});
