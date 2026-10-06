<?php

use App\Exceptions\InfisicalManagedVariableException;
use App\Livewire\Project\Service\StackForm;
use App\Models\Environment;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const SNIPEIT_COMPOSE = <<<'YAML'
services:
  snipeit:
    image: snipe/snipe-it:v8
    environment:
      - APP_KEY=${SERVICE_BASE64_64_APPKEY}
      - DB_HOST=mariadb
      - DB_USERNAME=${SERVICE_USER_MYSQL}
      - DB_PASSWORD=${SERVICE_PASSWORD_MYSQL}
    depends_on:
      - mariadb
  mariadb:
    image: mariadb:11.4
    environment:
      - MYSQL_USER=${SERVICE_USER_MYSQL}
      - MYSQL_PASSWORD=${SERVICE_PASSWORD_MYSQL}
      - MYSQL_ROOT_PASSWORD=${SERVICE_PASSWORD_ROOT}
YAML;

beforeEach(function () {
    InfisicalLock::resetDepthForTesting();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => SNIPEIT_COMPOSE,
    ]);
    $this->service->parse(isNew: true);

    InfisicalConnection::factory()->create(['team_id' => $this->team->id, 'is_enabled' => true, 'adopted_at' => now()]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

it('lets a locked team rename a custom compose service', function () {
    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->set('name', 'Snipe-IT')
        ->call('submit');

    expect($this->service->fresh()->name)->toBe('Snipe-IT');
});

it('lets a locked team edit the compose file', function () {
    $edited = str_replace('snipe/snipe-it:v8', 'snipe/snipe-it:latest', SNIPEIT_COMPOSE);

    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->call('saveCompose', $edited);

    expect($this->service->fresh()->docker_compose_raw)->toContain('snipe/snipe-it:latest');
});

it('still refuses a real change to a managed template field', function () {
    $variable = $this->service->environment_variables()->where('key', 'SERVICE_USER_MYSQL')->first();
    $variable->value = 'changed';

    expect(fn () => $variable->save())->toThrow(InfisicalManagedVariableException::class);
});
