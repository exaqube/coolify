<?php

use App\Actions\Infisical\CollectTeamSecrets;
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
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// parse() turns every `${VAR}` in a compose file into an empty row. Pushing
// those blanks overwrote the real values already in Infisical.
it('never pushes an empty value but still lets the pull fill it', function () {
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

    InfisicalConnection::factory()->create(['team_id' => $team->id, 'is_enabled' => true, 'adopted_at' => now()]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    InfisicalLock::asSystem(function () use ($application) {
        foreach (['DATABASE_URL' => '', 'APP_ENV' => 'staging'] as $key => $value) {
            EnvironmentVariable::create([
                'key' => $key,
                'value' => $value,
                'resourceable_type' => Application::class,
                'resourceable_id' => $application->id,
                'is_preview' => false,
            ]);
        }
    });

    $id = InfisicalPath::environmentSlug($environment->name).'|'.InfisicalPath::forResource($project->name, $application->name);
    $bucket = CollectTeamSecrets::run($team, [$id])[$id];

    expect($bucket['secrets'])->not->toHaveKey('DATABASE_URL')
        ->and($bucket['secrets']['APP_ENV'])->toBe('staging')
        ->and($bucket['rows'])->toHaveKey('DATABASE_URL');
});
