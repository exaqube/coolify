<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Security\ApiTokens;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\GithubApp;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\PersonalAccessToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');
    Storage::fake('ssh-keys');
    Queue::fake();
    $this->withoutVite();

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->rootAdmin = User::factory()->create();
    $this->rootTeam->members()->attach($this->rootAdmin->id, ['role' => 'owner']);

    $this->platformHeaders = platformApiHeaders($this->rootAdmin, 0, ['platform']);
});

/**
 * @param  array<int, string>  $abilities
 * @return array<string, string>
 */
function platformApiHeaders(User $user, int $teamId, array $abilities): array
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'platform-api-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => $teamId,
    ]);

    return ['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken, 'Accept' => 'application/json'];
}

/**
 * Send the next request with these headers. Guards are reset first, because the test
 * client otherwise keeps the user resolved from the previous request's token.
 */
function asToken(array $headers): mixed
{
    app('auth')->forgetGuards();

    return test()->withHeaders($headers);
}

function platformBearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function platformCreateTeam(object $test, string $name = 'rq-acme'): array
{
    return asToken($test->platformHeaders)
        ->postJson('/api/v1/platform/teams', ['name' => $name])
        ->assertCreated()
        ->json();
}

function platformMintToken(object $test, int $teamId, array $abilities = ['read', 'write', 'deploy', 'read:sensitive']): string
{
    return asToken($test->platformHeaders)
        ->postJson("/api/v1/platform/teams/{$teamId}/tokens", ['name' => 'ops', 'abilities' => $abilities])
        ->assertCreated()
        ->json('token');
}

function platformRsaKey(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

describe('teams', function () {
    test('a platform token creates a team with a service account, idempotently by name', function () {
        $team = platformCreateTeam($this);

        expect($team['uuid'])->not->toBeEmpty()
            ->and($team['name'])->toBe('rq-acme');

        $members = Team::find($team['id'])->members;
        expect($members)->toHaveCount(1)
            ->and($members->first()->is_service_account)->toBeTrue()
            ->and($members->first()->email)->toBe("svc+{$team['uuid']}@platform.local")
            ->and($members->first()->pivot->role)->toBe('admin')
            ->and($members->first()->teams)->toHaveCount(1)
            ->and($members->first()->password)->toBeNull();

        asToken($this->platformHeaders)
            ->postJson('/api/v1/platform/teams', ['name' => 'rq-acme'])
            ->assertOk()
            ->assertJsonPath('id', $team['id']);

        expect(Team::where('name', 'rq-acme')->count())->toBe(1);
        expect(User::where('is_service_account', true)->count())->toBe(1);
    });

    test('a name used by a team that is not platform-managed returns 409', function () {
        Team::factory()->create(['name' => 'Customer Team']);

        asToken($this->platformHeaders)
            ->postJson('/api/v1/platform/teams', ['name' => 'Customer Team'])
            ->assertStatus(409);
    });

    test('lists platform teams plus root, and hides other teams', function () {
        Team::factory()->create(['name' => 'Human Team']);
        $team = platformCreateTeam($this);

        $ids = collect(asToken($this->platformHeaders)->getJson('/api/v1/platform/teams')->assertOk()->json())->pluck('id');
        expect($ids->all())->toBe([0, $team['id']]);

        $humanTeam = Team::where('name', 'Human Team')->first();
        asToken($this->platformHeaders)->getJson("/api/v1/platform/teams/{$humanTeam->id}")->assertNotFound();
        asToken($this->platformHeaders)->getJson("/api/v1/platform/teams/{$team['id']}")->assertOk()->assertJsonPath('uuid', $team['uuid']);
    });

    test('delete is refused while the team has resources and then removes the service account and its tokens', function () {
        $team = platformCreateTeam($this);
        $token = platformMintToken($this, $team['id']);
        $serviceAccount = User::where('is_service_account', true)->sole();
        Project::factory()->create(['team_id' => $team['id']]);

        asToken($this->platformHeaders)
            ->deleteJson("/api/v1/platform/teams/{$team['id']}")
            ->assertStatus(409)
            ->assertJsonPath('blockers.projects', 1);

        Project::where('team_id', $team['id'])->get()->each->forceDelete();

        asToken($this->platformHeaders)->deleteJson("/api/v1/platform/teams/{$team['id']}")->assertOk();

        expect(Team::find($team['id']))->toBeNull()
            ->and(User::find($serviceAccount->id))->toBeNull()
            ->and(PersonalAccessToken::where('tokenable_id', $serviceAccount->id)->count())->toBe(0);

        asToken(platformBearer($token))->getJson('/api/v1/team')->assertUnauthorized();
    });

    test('the root team and other teams cannot be deleted or receive tokens', function () {
        $human = Team::factory()->create();

        foreach ([0, $human->id] as $id) {
            asToken($this->platformHeaders)->deleteJson("/api/v1/platform/teams/{$id}")->assertNotFound();
            asToken($this->platformHeaders)
                ->postJson("/api/v1/platform/teams/{$id}/tokens", ['name' => 'x-token', 'abilities' => ['read']])
                ->assertNotFound();
        }
        expect(Team::find(0))->not->toBeNull();
    });
});

describe('the platform ability', function () {
    test('root, wildcard and team tokens cannot call platform endpoints', function () {
        asToken(platformApiHeaders($this->rootAdmin, 0, ['root']))->getJson('/api/v1/platform/teams')->assertForbidden();
        asToken(platformApiHeaders($this->rootAdmin, 0, ['*']))->getJson('/api/v1/platform/teams')->assertForbidden();

        $other = Team::factory()->create();
        $owner = User::factory()->create();
        $other->members()->attach($owner->id, ['role' => 'owner']);
        asToken(platformApiHeaders($owner, $other->id, ['platform']))->getJson('/api/v1/platform/teams')->assertForbidden();
    });

    test('a platform token owned by a root team member (not admin) is refused', function () {
        $member = User::factory()->create();
        $this->rootTeam->members()->attach($member->id, ['role' => 'member']);

        asToken(platformApiHeaders($member, 0, ['platform']))->getJson('/api/v1/platform/teams')->assertForbidden();
    });

    test('a platform token cannot read or change resources inside any team', function () {
        $team = platformCreateTeam($this);
        Server::factory()->create(['team_id' => 0]);
        InstanceSettings::get()->forceFill(['is_mcp_server_enabled' => true])->save();

        foreach (['/api/v1/servers', '/api/v1/projects', '/api/v1/team', '/api/v1/github-apps', '/api/v1/security/keys'] as $path) {
            asToken($this->platformHeaders)->getJson($path)->assertForbidden();
        }
        asToken($this->platformHeaders)->postJson('/api/v1/projects', ['name' => 'nope'])->assertForbidden();
        asToken($this->platformHeaders)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
    });

    test('minting with root, platform or wildcard abilities returns 422', function (array $abilities) {
        $team = platformCreateTeam($this);

        asToken($this->platformHeaders)
            ->postJson("/api/v1/platform/teams/{$team['id']}/tokens", ['name' => 'bad-token', 'abilities' => $abilities])
            ->assertUnprocessable();

        expect(PersonalAccessToken::where('team_id', $team['id'])->count())->toBe(0);
    })->with([
        'root' => [['root']],
        'platform' => [['platform']],
        'wildcard' => [['*']],
        'write:sensitive' => [['read', 'write:sensitive']],
    ]);

    test('a non-root admin cannot create a platform token in the UI', function () {
        $team = Team::factory()->create();
        $admin = User::factory()->create();
        $team->members()->attach($admin->id, ['role' => 'owner']);
        session(['currentTeam' => $team]);

        Livewire::actingAs($admin)->test(ApiTokens::class)
            ->assertSet('canUsePlatformPermissions', false)
            ->set('description', 'sneaky')
            ->set('permissions', ['platform'])
            ->call('addNewToken');

        expect($admin->tokens()->count())->toBe(0);
    });

    test('a root admin creates a platform-only token in the root team', function () {
        session(['currentTeam' => $this->rootTeam]);

        Livewire::actingAs($this->rootAdmin)->test(ApiTokens::class)
            ->assertSet('canUsePlatformPermissions', true)
            ->set('description', 'provisioning')
            ->set('permissions', ['platform', 'read'])
            ->call('addNewToken');
        expect($this->rootAdmin->tokens()->where('name', 'provisioning')->count())->toBe(0);

        Livewire::actingAs($this->rootAdmin)->test(ApiTokens::class)
            ->set('description', 'provisioning')
            ->set('permissions', ['platform'])
            ->call('addNewToken')
            ->assertSee('Platform tokens issued');

        $token = $this->rootAdmin->tokens()->where('name', 'provisioning')->sole();
        expect($token->abilities)->toBe(['platform'])->and((int) $token->team_id)->toBe(0);
    });
});

describe('minted team tokens', function () {
    test('work inside their own team only', function () {
        $team = platformCreateTeam($this);
        $token = platformMintToken($this, $team['id']);
        $headers = platformBearer($token);

        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);

        asToken($headers)->getJson('/api/v1/team')->assertOk()->assertJsonPath('id', $team['id']);

        $keyUuid = asToken($headers)
            ->postJson('/api/v1/security/keys', ['private_key' => platformRsaKey()])
            ->assertCreated()->json('uuid');
        $serverUuid = asToken($headers)
            ->postJson('/api/v1/servers', ['name' => 'customer-vm', 'ip' => '192.0.2.10', 'private_key_uuid' => $keyUuid])
            ->assertCreated()->json('uuid');
        $projectUuid = asToken($headers)
            ->postJson('/api/v1/projects', ['name' => 'customer'])
            ->assertCreated()->json('uuid');
        $environmentUuid = Project::where('uuid', $projectUuid)->sole()->environments()->firstOrFail()->uuid;

        asToken($headers)->postJson('/api/v1/databases/postgresql', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'server_uuid' => $serverUuid,
        ])->assertCreated();
        asToken($headers)->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'server_uuid' => $serverUuid,
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'alpine',
        ])->assertCreated();

        expect(Server::where('uuid', $serverUuid)->value('team_id'))->toBe($team['id'])
            ->and(StandalonePostgresql::count())->toBe(1)
            ->and(Application::count())->toBe(1);

        asToken($headers)->getJson("/api/v1/servers/{$otherServer->uuid}")->assertNotFound();
        asToken($headers)->getJson("/api/v1/projects/{$otherProject->uuid}")->assertNotFound();
        asToken($headers)->getJson('/api/v1/platform/teams')->assertForbidden();
    });

    test('can be revoked, and only service-account tokens of that team', function () {
        $team = platformCreateTeam($this);
        $token = platformMintToken($this, $team['id'], ['deploy']);
        $tokenId = (int) Str::before($token, '|');

        asToken($this->platformHeaders)
            ->getJson("/api/v1/platform/teams/{$team['id']}")
            ->assertJsonPath('tokens.0.id', $tokenId)
            ->assertJsonPath('tokens.0.abilities', ['deploy'])
            ->assertJsonMissingPath('tokens.0.token');

        $rootTokenId = $this->rootAdmin->tokens()->value('id');
        asToken($this->platformHeaders)->deleteJson("/api/v1/platform/teams/{$team['id']}/tokens/{$rootTokenId}")->assertNotFound();

        asToken($this->platformHeaders)->deleteJson("/api/v1/platform/teams/{$team['id']}/tokens/{$tokenId}")->assertOk();
        expect(PersonalAccessToken::find($tokenId))->toBeNull();
    });
});

describe('shared GitHub Apps', function () {
    beforeEach(function () {
        $key = PrivateKey::create(['name' => 'App key', 'private_key' => platformRsaKey(), 'team_id' => 0]);
        $this->githubApp = GithubApp::create([
            'name' => 'Exaqube Deployer',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'app_id' => 11111,
            'installation_id' => 22222,
            'client_id' => 'client-id',
            'client_secret' => 'SENTINEL-CLIENT-SECRET',
            'webhook_secret' => 'SENTINEL-WEBHOOK-SECRET',
            'private_key_id' => $key->id,
            'team_id' => 0,
            'is_system_wide' => false,
            'is_public' => false,
        ]);
        $this->appKey = $key;
    });

    test('sharing a non-shareable source returns 422', function () {
        $team = platformCreateTeam($this);

        asToken($this->platformHeaders)
            ->postJson("/api/v1/platform/teams/{$team['id']}/github-apps/{$this->githubApp->uuid}/share")
            ->assertUnprocessable();

        expect(Team::find($team['id'])->sharedGithubApps()->count())->toBe(0);
    });

    test('a shared source deploys in team B, which cannot read its secrets or change it', function () {
        $this->githubApp->update(['is_shareable' => true]);
        $team = platformCreateTeam($this);
        $headers = platformBearer(platformMintToken($this, $team['id']));

        asToken($this->platformHeaders)
            ->postJson("/api/v1/platform/teams/{$team['id']}/github-apps/{$this->githubApp->uuid}/share")
            ->assertOk();

        $server = Server::factory()->create(['team_id' => $team['id']]);
        $project = Project::factory()->create(['team_id' => $team['id']]);
        $environment = $project->environments()->firstOrFail();

        Http::fake([
            'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
            'https://api.github.com/app/installations/22222/access_tokens' => Http::response(['token' => 'installation-token'], 201),
            'https://api.github.com/repos/exaqube/shell' => Http::response(['id' => 42]),
        ]);

        $appUuid = asToken($headers)->postJson('/api/v1/applications/private-github-app', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'server_uuid' => $server->uuid,
            'github_app_uuid' => $this->githubApp->uuid,
            'git_repository' => 'exaqube/shell',
            'git_branch' => 'main',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'autogenerate_domain' => false,
        ])->assertCreated()->json('uuid');

        expect(Application::where('uuid', $appUuid)->value('source_id'))->toBe($this->githubApp->id);

        asToken($headers)->postJson('/api/v1/deploy', ['uuid' => $appUuid])->assertOk();
        Queue::assertPushed(ApplicationDeploymentJob::class);

        $list = asToken($headers)->getJson('/api/v1/github-apps')->assertOk();
        expect($list->json('*.uuid'))->toContain($this->githubApp->uuid)
            ->and($list->getContent())->not->toContain('SENTINEL-CLIENT-SECRET')
            ->and($list->getContent())->not->toContain('SENTINEL-WEBHOOK-SECRET');

        asToken($headers)->patchJson("/api/v1/github-apps/{$this->githubApp->id}", ['name' => 'hijacked'])->assertNotFound();
        asToken($headers)->deleteJson("/api/v1/github-apps/{$this->githubApp->id}")->assertNotFound();
        asToken($headers)->getJson("/api/v1/security/keys/{$this->appKey->uuid}")->assertNotFound();
        expect($this->githubApp->fresh()->name)->toBe('Exaqube Deployer');

        // Unsharing (or turning sharing off) stops further use.
        asToken($this->platformHeaders)
            ->deleteJson("/api/v1/platform/teams/{$team['id']}/github-apps/{$this->githubApp->uuid}/share")
            ->assertOk();
        expect(GithubApp::usableByTeam($team['id'])->whereKey($this->githubApp->id)->exists())->toBeFalse();
    });

    test('only the owning team admin can toggle is_shareable through the API', function () {
        $owner = User::factory()->create();
        $ownerTeam = Team::factory()->create();
        $ownerTeam->members()->attach($owner->id, ['role' => 'owner']);
        $app = GithubApp::create([
            'name' => 'Team app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
            'team_id' => $ownerTeam->id, 'is_system_wide' => false, 'is_public' => false,
        ]);

        asToken(platformApiHeaders($owner, $ownerTeam->id, ['write']))
            ->patchJson("/api/v1/github-apps/{$app->id}", ['is_shareable' => true])
            ->assertOk();
        expect($app->fresh()->is_shareable)->toBeTrue();

        $member = User::factory()->create();
        $ownerTeam->members()->attach($member->id, ['role' => 'member']);
        asToken(platformApiHeaders($member, $ownerTeam->id, ['read']))
            ->patchJson("/api/v1/github-apps/{$app->id}", ['is_shareable' => false])
            ->assertForbidden();
        expect($app->fresh()->is_shareable)->toBeTrue();
    });
});

describe('Infisical connection', function () {
    test('the client secret is encrypted and never present in a response or audit row', function () {
        $sentinel = 'SENTINEL-INFISICAL-'.Str::random(16);
        $team = platformCreateTeam($this);

        $created = asToken($this->platformHeaders)->postJson("/api/v1/platform/teams/{$team['id']}/infisical-connection", [
            'host' => 'https://infisical.example.com',
            'client_id' => 'machine-identity',
            'client_secret' => $sentinel,
            'infisical_org_id' => 'org-1',
            'infisical_project_id' => 'project-1',
        ])->assertCreated();

        $updated = asToken($this->platformHeaders)->postJson("/api/v1/platform/teams/{$team['id']}/infisical-connection", [
            'host' => 'https://infisical.example.com',
            'infisical_project_id' => 'project-2',
            'client_secret' => $sentinel,
        ])->assertOk()->assertJsonPath('infisical_project_id', 'project-2');

        $viewed = asToken($this->platformHeaders)->getJson("/api/v1/platform/teams/{$team['id']}")->assertJsonPath('has_infisical_connection', true);

        $connection = InfisicalConnection::where('team_id', $team['id'])->sole();
        expect($connection->client_secret)->toBe($sentinel)
            ->and(DB::table('infisical_connections')->value('client_secret'))->not->toContain($sentinel);

        foreach ([$created, $updated, $viewed] as $response) {
            expect($response->getContent())->not->toContain($sentinel);
        }

        $audit = AuditEvent::query()->where('event', 'like', 'api.platform_%')->get();
        expect($audit->pluck('event'))->toContain('api.platform_infisical_connection.created', 'api.platform_infisical_connection.updated')
            ->and(json_encode(DB::table('audit_events')->get()))->not->toContain($sentinel);
    });
});

test('every platform call is audited with the actor token and target team, without token values', function () {
    $team = platformCreateTeam($this);
    $token = platformMintToken($this, $team['id']);

    $created = AuditEvent::query()->where('event', 'api.platform_token.created')->sole();
    expect(data_get($created->metadata, 'target_team_id'))->toBe($team['id'])
        ->and($created->actor_token_id)->not->toBeNull()
        ->and(AuditEvent::query()->where('event', 'api.platform_team.created')->exists())->toBeTrue()
        ->and(json_encode(DB::table('audit_events')->get()))->not->toContain(Str::after($token, '|'));
});

test('service accounts cannot sign in with a password', function () {
    $team = platformCreateTeam($this);
    $serviceAccount = User::where('is_service_account', true)->sole();
    $serviceAccount->forceFill(['password' => bcrypt('known-password')])->save();

    $this->post('/login', ['email' => $serviceAccount->email, 'password' => 'known-password']);

    $this->assertGuest('web');
    expect($team['id'])->toBeInt();
});

test('service accounts cannot sign in through OAuth', function () {
    platformCreateTeam($this);
    $serviceAccount = User::where('is_service_account', true)->sole();

    expect(fn () => app(OauthLoginService::class)->login(
        'github',
        (object) ['email' => $serviceAccount->email, 'id' => '123', 'user' => []],
        new OauthSetting(['provider' => 'github']),
    ))->toThrow(HttpException::class);

    $this->assertGuest('web');
});

test('a web session of a service account is ended', function () {
    platformCreateTeam($this);
    $serviceAccount = User::where('is_service_account', true)->sole();

    $this->actingAs($serviceAccount)->get('/')->assertRedirect(route('login'));

    $this->assertGuest('web');
});

test('the platform endpoints are documented in openapi.json', function () {
    $paths = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR)['paths'];

    expect($paths['/platform/teams'])->toHaveKeys(['get', 'post'])
        ->and($paths['/platform/teams/{id}'])->toHaveKeys(['get', 'delete'])
        ->and($paths['/platform/teams/{id}/tokens'])->toHaveKey('post')
        ->and($paths['/platform/teams/{id}/tokens/{tokenId}'])->toHaveKey('delete')
        ->and($paths['/platform/teams/{id}/github-apps/{githubAppUuid}/share'])->toHaveKeys(['post', 'delete'])
        ->and($paths['/platform/teams/{id}/infisical-connection'])->toHaveKey('post');
});

test('a service account removed from its team is reused when minting, and gets no notification email', function () {
    $team = platformCreateTeam($this);
    $serviceAccount = User::where('is_service_account', true)->sole();
    Team::find($team['id'])->members()->detach($serviceAccount->id);

    platformMintToken($this, $team['id']);

    expect(User::where('is_service_account', true)->count())->toBe(1)
        ->and(Team::find($team['id'])->members()->whereKey($serviceAccount->id)->exists())->toBeTrue()
        ->and(Team::find($team['id'])->getRecipients())->not->toContain($serviceAccount->email);
});
