<?php

use App\Actions\Service\DeployServiceApplication;
use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\Infisical\InfisicalLock;
use App\Services\Infisical\InfisicalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

// Only ApplicationDeploymentJob pulled before deploying, so a compose service
// restarted after an Infisical edit shipped the old value until the scheduled sync.
it('pulls Infisical before starting a compose service', function (string $entryPoint) {
    Server::flushIdentityMap();
    Bus::fake();
    Process::fake();
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false]);
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
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => "services:\n  web:\n    image: nginx\n    environment:\n      - APP_RELEASE=\${APP_RELEASE}\n",
    ]);

    InfisicalLock::asSystem(fn () => EnvironmentVariable::create([
        'key' => 'APP_RELEASE',
        'value' => 'v1.3.0',
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
        'is_preview' => false,
    ]));

    InfisicalConnection::factory()->create([
        'team_id' => $team->id,
        'host' => 'https://infisical.test',
        'is_enabled' => true,
        'adopted_at' => now(),
    ]);

    $resourcePath = InfisicalPath::forResource($project->name, $service->name);

    Http::fake([
        'https://infisical.test/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token']),
        'https://infisical.test/api/v3/secrets/raw*' => fn ($request) => Http::response([
            'secrets' => ($request->data()['secretPath'] ?? null) === $resourcePath
                ? [['secretKey' => 'APP_RELEASE', 'secretValue' => 'v1.4.3']]
                : [],
        ]),
    ]);

    if ($entryPoint === 'stack') {
        StartService::run($service);
    } else {
        $service->parse();
        DeployServiceApplication::run($service->applications()->firstOrFail());
    }

    expect($service->environment_variables()->where('key', 'APP_RELEASE')->value('value'))->toBe('v1.4.3');
})->with(['stack', 'single service application']);
