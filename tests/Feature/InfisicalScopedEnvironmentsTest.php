<?php

use App\Actions\Infisical\AdoptTeamSecretsIntoInfisical;
use App\Actions\Infisical\PullTeamSecrets;
use App\Actions\Infisical\PushGeneratedSecret;
use App\Jobs\InfisicalPushJob;
use App\Models\Environment;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// One team = one Infisical project, which holds every Coolify project's
// environments. Project A (envs a, b) and project B (envs x, y) must each live
// only in their own environments.

function scopedPath(string $path): string
{
    return rtrim($path, '/').'/';
}

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    InfisicalLock::resetDepthForTesting();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();

    $makeProject = function (string $name, array $envs) {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => $name]);
        $project->environments()->first()->update(['name' => $envs[0]]);
        Environment::factory()->create(['project_id' => $project->id, 'name' => $envs[1]]);

        return $project->fresh();
    };

    $this->alpha = $makeProject('alpha', ['a', 'b']);
    $this->bravo = $makeProject('bravo', ['x', 'y']);

    SharedEnvironmentVariable::create(['key' => 'ALPHA_KEY', 'value' => 'alpha-value', 'type' => 'project', 'project_id' => $this->alpha->id, 'team_id' => $this->team->id]);
    $this->bravoRow = SharedEnvironmentVariable::create(['key' => 'BRAVO_KEY', 'value' => 'bravo-value', 'type' => 'project', 'project_id' => $this->bravo->id, 'team_id' => $this->team->id]);

    $this->connection = InfisicalConnection::factory()->create([
        'team_id' => $this->team->id,
        'host' => 'https://infisical.test',
        'is_enabled' => true,
        'adopted_at' => now(),
    ]);

    // In-memory Infisical: environments, folders per env, secrets per env and path.
    $this->infisical = ['envs' => [], 'folders' => [], 'secrets' => []];
    $store = &$this->infisical;

    Http::fake(function (Request $request) use (&$store) {
        $url = parse_url($request->url(), PHP_URL_PATH);
        $data = $request->data();
        $env = $data['environment'] ?? null;

        if (str_ends_with($url, '/auth/universal-auth/login')) {
            return Http::response(['accessToken' => 'token']);
        }

        if (preg_match('#^/api/v1/workspace/[^/]+/environments/(.+)$#', $url, $m) && $request->method() === 'DELETE') {
            $slug = array_search($m[1], $store['envs'], true);
            unset($store['envs'][$slug], $store['folders'][$slug], $store['secrets'][$slug]);

            return Http::response([]);
        }

        if (preg_match('#^/api/v1/workspace/[^/]+/environments$#', $url)) {
            $store['envs'][$data['slug']] = 'id-'.$data['slug'];

            return Http::response([]);
        }

        if (str_starts_with($url, '/api/v1/workspace/')) {
            return Http::response(['workspace' => ['orgId' => 'org', 'environments' => collect($store['envs'])
                ->map(fn ($id, $slug) => ['id' => $id, 'slug' => $slug])->values()->all()]]);
        }

        if (str_starts_with($url, '/api/v1/folders/') && $request->method() === 'DELETE') {
            $prefix = scopedPath($data['path']).rawurldecode(substr($url, strlen('/api/v1/folders/'))).'/';
            $store['folders'][$env] = array_values(array_filter($store['folders'][$env] ?? [], fn ($p) => ! str_starts_with($p, $prefix)));
            $store['secrets'][$env] = array_filter($store['secrets'][$env] ?? [], fn ($p) => ! str_starts_with($p, $prefix), ARRAY_FILTER_USE_KEY);

            return Http::response(['folder' => []]);
        }

        if ($url === '/api/v1/folders' && $request->method() === 'POST') {
            $store['folders'][$env][] = scopedPath($data['path']).$data['name'].'/';

            return Http::response([]);
        }

        if ($url === '/api/v1/folders') {
            $parent = scopedPath($data['path']);
            $names = collect($store['folders'][$env] ?? [])
                ->filter(fn ($p) => str_starts_with($p, $parent) && substr_count(substr($p, strlen($parent)), '/') === 1)
                ->map(fn ($p) => ['name' => trim(substr($p, strlen($parent)), '/')])->values()->all();

            return Http::response(['folders' => $names]);
        }

        if ($url === '/api/v3/secrets/raw') {
            $recursive = ($data['recursive'] ?? null) === 'true';
            $path = scopedPath($data['secretPath']);
            $secrets = [];
            foreach ($store['secrets'][$env] ?? [] as $p => $values) {
                if ($recursive ? str_starts_with($p, $path) : $p === $path) {
                    foreach ($values as $key => $value) {
                        $secrets[] = ['secretKey' => $key, 'secretValue' => $value, 'secretPath' => rtrim($p, '/') ?: '/'];
                    }
                }
            }

            return Http::response(['secrets' => $secrets]);
        }

        if ($url === '/api/v3/secrets/batch/raw') {
            foreach ($data['secrets'] as $secret) {
                $store['secrets'][$env][scopedPath($data['secretPath'])][$secret['secretKey']] = $secret['secretValue'];
            }

            return Http::response([]);
        }

        return Http::response([]);
    });
});

/** Environments in which a root folder exists. */
function scopedEnvsWithFolder(array $store, string $folder): array
{
    return collect($store['folders'])
        ->filter(fn ($paths) => in_array("/{$folder}/", $paths, true))
        ->keys()->sort()->values()->all();
}

/** Seed the layout the old team-wide sync produced, plus a hand-made extra. */
function scopedSeedLegacyLayout(array &$store): void
{
    foreach (['a', 'b', 'x', 'y', 'zz'] as $slug) {
        $store['envs'][$slug] = "id-{$slug}";
    }

    $store['folders'] = [
        'a' => ['/alpha/', '/bravo/', '/ops/'],
        'b' => ['/alpha/', '/bravo/'],
        'x' => ['/alpha/', '/bravo/'],
        'y' => ['/alpha/', '/bravo/'],
    ];
    $store['secrets'] = [
        'a' => ['/alpha/' => ['ALPHA_KEY' => 'alpha-value'], '/bravo/' => ['BRAVO_KEY' => 'bravo-value'], '/ops/' => ['OPS' => 'hand-made']],
        'b' => ['/alpha/' => ['ALPHA_KEY' => 'alpha-value']],
        'x' => ['/bravo/' => ['BRAVO_KEY' => 'bravo-value'], '/alpha/' => ['ALPHA_KEY' => 'stale-value']],
        'y' => ['/bravo/' => ['BRAVO_KEY' => 'bravo-value']],
    ];
}

function scopedLatestPlan(): array
{
    $file = collect(Storage::disk('local')->files())->filter(fn ($f) => str_ends_with($f, '.json'))->sort()->last();

    return json_decode(Storage::disk('local')->get($file), true);
}

it('writes each project folder only into that project\'s environments', function () {
    AdoptTeamSecretsIntoInfisical::run($this->connection);
    PullTeamSecrets::run($this->connection);

    expect(array_keys($this->infisical['envs']))->toEqualCanonicalizing(['a', 'b', 'x', 'y'])
        ->and(scopedEnvsWithFolder($this->infisical, 'alpha'))->toBe(['a', 'b'])
        ->and(scopedEnvsWithFolder($this->infisical, 'bravo'))->toBe(['x', 'y'])
        ->and($this->infisical['secrets']['a']['/alpha/'])->toBe(['ALPHA_KEY' => 'alpha-value'])
        ->and($this->infisical['secrets']['x']['/bravo/'])->toBe(['BRAVO_KEY' => 'bravo-value'])
        ->and($this->infisical['secrets']['a'])->not->toHaveKey('/bravo/')
        ->and($this->infisical['secrets']['x'])->not->toHaveKey('/alpha/');
});

it('queues a project variable push only for that project\'s environments', function () {
    PushGeneratedSecret::run($this->bravoRow);

    Queue::assertPushed(InfisicalPushJob::class, 2);
    Queue::assertPushed(InfisicalPushJob::class, fn ($job) => in_array($job->environmentSlug, ['x', 'y'], true) && $job->path === '/bravo/');
});

it('never reads a project folder from a foreign environment on pull', function () {
    scopedSeedLegacyLayout($this->infisical);
    $this->infisical['secrets']['a']['/bravo/'] = ['BRAVO_KEY' => 'foreign-value'];

    PullTeamSecrets::run($this->connection);

    expect($this->bravoRow->fresh()->value)->toBe('bravo-value');

    Http::assertNotSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/secrets/raw')
        && in_array($request->data()['environment'] ?? null, ['a', 'b'], true)
        && str_starts_with((string) ($request->data()['secretPath'] ?? ''), '/bravo'));
    Http::assertNotSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/secrets/raw')
        && in_array($request->data()['environment'] ?? null, ['x', 'y'], true)
        && str_starts_with((string) ($request->data()['secretPath'] ?? ''), '/alpha'));
});

it('plans the strays without deleting anything', function () {
    scopedSeedLegacyLayout($this->infisical);
    $before = $this->infisical;

    $this->artisan('infisical:cleanup-plan', ['team' => $this->team->id])->assertSuccessful();

    $plan = scopedLatestPlan();
    $items = collect($plan['items'])->keyBy('id');

    expect($items->keys()->all())->toEqualCanonicalizing([
        'folder:a:/bravo/', 'folder:b:/bravo/', 'folder:x:/alpha/', 'folder:y:/alpha/', 'environment:zz',
    ])
        ->and($items['folder:a:/bravo/'])->toMatchArray(['secret_count' => 1, 'safe' => true, 'project' => 'bravo'])
        ->and($items['folder:x:/alpha/'])->toMatchArray(['secret_count' => 1, 'safe' => false, 'different' => ['/alpha/ALPHA_KEY']])
        ->and($items['folder:y:/alpha/'])->toMatchArray(['secret_count' => 0, 'safe' => true])
        ->and($items['environment:zz'])->toMatchArray(['secret_count' => 0, 'safe' => true])
        ->and($plan['unmatched_folders'])->toBe([['environment' => 'a', 'path' => '/ops/', 'secret_count' => 1]])
        ->and($this->infisical)->toBe($before);

    $files = Storage::disk('local')->files();
    $markdown = Storage::disk('local')->get(collect($files)->first(fn ($f) => str_ends_with($f, '.md')));
    expect($markdown)->toContain('folder:x:/alpha/')
        ->not->toContain('alpha-value')
        ->not->toContain('stale-value')
        ->not->toContain('bravo-value')
        ->and(json_encode($plan))->not->toContain('alpha-value')->not->toContain('stale-value');
});

it('applies only reviewed, safe items', function () {
    scopedSeedLegacyLayout($this->infisical);
    $this->artisan('infisical:cleanup-plan', ['team' => $this->team->id])->assertSuccessful();

    // The reviewer drops folder:b:/bravo/ from the plan; it must survive.
    $plan = scopedLatestPlan();
    $plan['items'] = array_values(array_filter($plan['items'], fn ($i) => $i['id'] !== 'folder:b:/bravo/'));
    $file = Storage::disk('local')->path('reviewed.json');
    file_put_contents($file, json_encode($plan));

    // Without --confirm nothing moves.
    $before = $this->infisical;
    $this->artisan('infisical:cleanup-apply', ['plan' => $file]);
    expect($this->infisical)->toBe($before);

    $this->artisan('infisical:cleanup-apply', ['plan' => $file, '--confirm' => true])
        ->expectsOutputToContain('refused folder:x:/alpha/')
        ->assertFailed();

    expect(scopedEnvsWithFolder($this->infisical, 'bravo'))->toBe(['b', 'x', 'y'])
        ->and(scopedEnvsWithFolder($this->infisical, 'alpha'))->toBe(['a', 'b', 'x'])
        ->and($this->infisical['envs'])->not->toHaveKey('zz')
        ->and($this->infisical['secrets']['x']['/alpha/'])->toBe(['ALPHA_KEY' => 'stale-value'])
        ->and(in_array('/ops/', $this->infisical['folders']['a'], true))->toBeTrue();
});

it('refuses an item that changed after the plan was reviewed', function () {
    scopedSeedLegacyLayout($this->infisical);
    $this->artisan('infisical:cleanup-plan', ['team' => $this->team->id])->assertSuccessful();
    $file = Storage::disk('local')->path(collect(Storage::disk('local')->files())->first(fn ($f) => str_ends_with($f, '.json')));

    $this->infisical['secrets']['y']['/alpha/'] = ['NEW_KEY' => 'added-later'];

    $this->artisan('infisical:cleanup-apply', ['plan' => $file, '--confirm' => true])
        ->expectsOutputToContain('refused folder:y:/alpha/: changed since the plan')
        ->assertFailed();

    expect($this->infisical['secrets']['y']['/alpha/'])->toBe(['NEW_KEY' => 'added-later']);
});
