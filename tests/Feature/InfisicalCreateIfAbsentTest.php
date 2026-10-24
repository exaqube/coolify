<?php

use App\Actions\Infisical\AdoptTeamSecretsIntoInfisical;
use App\Actions\Infisical\PullTeamSecrets;
use App\Jobs\InfisicalPushJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Infisical\InfisicalLock;
use App\Services\Infisical\InfisicalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Coolify used to upsert whole folders into Infisical, overwriting edits made
// there, and the scheduled sync pushed back keys that had been deleted there.

beforeEach(function () {
    Queue::fake();
    InfisicalLock::resetDepthForTesting();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->connection = InfisicalConnection::factory()->create([
        'team_id' => $team->id,
        'host' => 'https://infisical.test',
        'is_enabled' => true,
        'adopted_at' => now(),
    ]);
    $this->slug = InfisicalPath::environmentSlug($environment->name);
    $this->path = InfisicalPath::forResource($project->name, $this->application->name);

    // In-memory Infisical: "{slug}|{path}" => [key => value].
    $this->infisical = [];
    $store = &$this->infisical;
    $slug = $this->slug;

    Http::fake(function (Request $request) use (&$store, $slug) {
        $url = parse_url($request->url(), PHP_URL_PATH);
        $data = $request->data();

        return match (true) {
            str_ends_with($url, '/auth/universal-auth/login') => Http::response(['accessToken' => 'token']),
            str_starts_with($url, '/api/v1/workspace/') => Http::response([
                'workspace' => ['orgId' => 'org', 'environments' => [['id' => 'env-1', 'slug' => $slug]]],
            ]),
            $url === '/api/v1/folders' => Http::response(['folders' => []]),
            $url === '/api/v3/secrets/raw' => Http::response(['secrets' => collect($store["{$data['environment']}|{$data['secretPath']}"] ?? [])
                ->map(fn ($value, $key) => ['secretKey' => $key, 'secretValue' => $value])
                ->values()
                ->all()]),
            $url === '/api/v3/secrets/batch/raw' => (function () use (&$store, $data) {
                foreach ($data['secrets'] as $secret) {
                    $store["{$data['environment']}|{$data['secretPath']}"][$secret['secretKey']] = $secret['secretValue'];
                }

                return Http::response([]);
            })(),
            default => Http::response([]),
        };
    });
});

function createIfAbsentRow(Application $application, string $key, string $value, ?string $managedPath = null): EnvironmentVariable
{
    return InfisicalLock::asSystem(function () use ($application, $key, $value, $managedPath) {
        $row = EnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'resourceable_type' => Application::class,
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);

        $row->forceFill(['is_infisical_managed' => $managedPath !== null, 'infisical_path' => $managedPath])->save();

        return $row;
    });
}

it('creates missing keys without overwriting Infisical edits on a folder push', function () {
    $this->infisical["{$this->slug}|{$this->path}"] = ['APP_RELEASE' => 'v1.4.3'];
    createIfAbsentRow($this->application, 'APP_RELEASE', 'v1.3.0');
    createIfAbsentRow($this->application, 'SERVICE_PASSWORD_DB', 'generated');
    $empty = createIfAbsentRow($this->application, 'UNSET_VAR', '');

    (new InfisicalPushJob($this->connection, $this->slug, $this->path))->handle();

    expect($this->infisical["{$this->slug}|{$this->path}"])
        ->toMatchArray(['APP_RELEASE' => 'v1.4.3', 'SERVICE_PASSWORD_DB' => 'generated'])
        ->not->toHaveKey('UNSET_VAR')
        ->and($empty->fresh()->is_infisical_managed)->toBeFalse();
});

it('does not push back a key deleted in Infisical on the scheduled sync', function () {
    createIfAbsentRow($this->application, 'LEAKED_TOKEN', 'old', managedPath: $this->path);
    createIfAbsentRow($this->application, 'NEW_KEY', 'fresh');

    PullTeamSecrets::run($this->connection);

    expect($this->infisical["{$this->slug}|{$this->path}"])
        ->toMatchArray(['NEW_KEY' => 'fresh'])
        ->not->toHaveKey('LEAKED_TOKEN');
});

it('restores deleted keys on adoption without overwriting existing ones', function () {
    $this->infisical["{$this->slug}|{$this->path}"] = ['APP_RELEASE' => 'v1.4.3'];
    createIfAbsentRow($this->application, 'APP_RELEASE', 'v1.3.0', managedPath: $this->path);
    createIfAbsentRow($this->application, 'LOST_KEY', 'kept', managedPath: $this->path);

    AdoptTeamSecretsIntoInfisical::run($this->connection);

    expect($this->infisical["{$this->slug}|{$this->path}"])
        ->toMatchArray(['APP_RELEASE' => 'v1.4.3', 'LOST_KEY' => 'kept']);
});
