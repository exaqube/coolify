<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\InfisicalAdoptJob;
use App\Models\GithubApp;
use App\Models\InfisicalConnection;
use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PlatformTeam',
    description: 'A team as seen by the platform API.',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string', 'nullable' => true],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string', 'nullable' => true],
        'is_platform_managed' => ['type' => 'boolean'],
        'created_at' => ['type' => 'string'],
    ],
)]
/**
 * Instance-level team provisioning for a `platform` token (root team only).
 *
 * A platform token manages teams but never acts inside one: work inside a team
 * uses that team's own token, minted here and owned by the team's service account.
 */
class PlatformController extends Controller
{
    /**
     * The widest token the platform API may mint into a team.
     */
    public const MINTABLE_ABILITIES = ['read', 'write', 'deploy', 'read:sensitive'];

    #[OA\Post(
        summary: 'Create team',
        description: 'Create a platform-managed team with its service account. Idempotent by name: an existing platform-managed team with the same name is returned with 200. A name used by any other team returns 409. Requires a `platform` token.',
        path: '/platform/teams',
        operationId: 'platform-create-team',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Team created.', content: new OA\JsonContent(ref: '#/components/schemas/PlatformTeam')),
            new OA\Response(response: 200, description: 'Platform-managed team with this name already exists.', content: new OA\JsonContent(ref: '#/components/schemas/PlatformTeam')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 409, description: 'A team that is not platform-managed already uses this name.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function createTeam(Request $request): JsonResponse
    {
        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->json()->all(), [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ], ValidationPatterns::combinedMessages());
        if ($response = $this->validationFailed($validator, ['name', 'description'], $request)) {
            return $response;
        }
        $validated = $validator->validated();
        $name = $validated['name'];

        // ponytail: lock per name so two concurrent creates cannot both insert; names are not unique in the schema.
        return Cache::lock('platform:team:'.sha1($name), 10)->block(5, function () use ($name, $validated): JsonResponse {
            $existing = Team::query()->where('name', $name)->orderByDesc('is_platform_managed')->first();
            if ($existing?->is_platform_managed) {
                $this->audit('team.create_existing', $existing);

                return response()->json($this->teamPayload($existing), 200);
            }
            if ($existing) {
                $this->audit('team.create_conflict', null, ['team_name' => $name], 'warning');

                return response()->json(['message' => 'A team with this name already exists and is not platform-managed.'], 409);
            }

            $team = DB::transaction(function () use ($validated): Team {
                $team = (new Team)->forceFill([
                    'uuid' => new_public_id(),
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'personal_team' => false,
                    'show_boarding' => false,
                    'is_platform_managed' => true,
                ]);
                $team->save();
                User::createServiceAccount($team);

                return $team;
            });

            $this->audit('team.created', $team);

            return response()->json($this->teamPayload($team), 201);
        });
    }

    #[OA\Get(
        summary: 'List teams',
        description: 'List the platform-managed teams and the root team. Requires a `platform` token.',
        path: '/platform/teams',
        operationId: 'platform-list-teams',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        responses: [
            new OA\Response(response: 200, description: 'Teams.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/PlatformTeam'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
        ],
    )]
    public function listTeams(): JsonResponse
    {
        $teams = Team::query()
            ->where(fn ($query) => $query->where('is_platform_managed', true)->orWhere('id', 0))
            ->orderBy('id')
            ->get();

        $this->audit('team.listed', null, ['count' => $teams->count()]);

        return response()->json($teams->map(fn (Team $team) => $this->teamPayload($team))->values());
    }

    #[OA\Get(
        summary: 'Get team',
        description: 'Get a platform-managed team (or the root team) with its service-account tokens and shared GitHub Apps. Token values are never returned. Requires a `platform` token.',
        path: '/platform/teams/{id}',
        operationId: 'platform-get-team',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Team.', content: new OA\JsonContent(ref: '#/components/schemas/PlatformTeam')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function getTeam(int $id): JsonResponse
    {
        $team = Team::query()
            ->whereKey($id)
            ->where(fn ($query) => $query->where('is_platform_managed', true)->orWhere('id', 0))
            ->first();
        if (! $team) {
            return $this->notFound();
        }

        $this->audit('team.viewed', $team);

        $payload = $this->teamPayload($team);
        if ($team->is_platform_managed) {
            $payload['tokens'] = $this->serviceTokens($team)->get()
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                    'created_at' => $token->created_at,
                ])->values();
            $payload['shared_github_apps'] = $team->sharedGithubApps()->get(['github_apps.uuid', 'github_apps.name'])
                ->map(fn (GithubApp $app) => ['uuid' => $app->uuid, 'name' => $app->name])->values();
            $payload['has_infisical_connection'] = InfisicalConnection::query()->where('team_id', $team->id)->exists();
        }

        return response()->json($payload);
    }

    #[OA\Delete(
        summary: 'Delete team',
        description: 'Delete an empty platform-managed team (no projects, servers or own sources), its service account and its tokens. Requires a `platform` token.',
        path: '/platform/teams/{id}',
        operationId: 'platform-delete-team',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Team deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'The team still has resources.'),
        ],
    )]
    public function deleteTeam(int $id): JsonResponse
    {
        $team = $this->platformTeam($id);
        if (! $team) {
            return $this->notFound();
        }

        $blockers = DB::transaction(function () use ($team): array {
            $team = Team::query()->lockForUpdate()->findOrFail($team->id);
            $blockers = $team->deletionBlockers();
            if ($blockers !== []) {
                return $blockers;
            }

            $members = $team->members()->get();
            foreach ($members->reject(fn (User $member) => $member->is_service_account) as $member) {
                $member->teams()->detach($team->id);
                $member->clearStoredTeamIfMatches($team->id);
            }

            // Team::deleting revokes every token of the team and removes its keys and sources.
            $team->delete();
            $members->filter(fn (User $member) => $member->is_service_account)
                ->each(fn (User $serviceAccount) => $serviceAccount->delete());

            return [];
        });

        if ($blockers !== []) {
            $this->audit('team.delete_refused', $team, ['blockers' => $blockers], 'warning');

            return response()->json([
                'message' => 'The team still has resources. Delete them first.',
                'blockers' => $blockers,
            ], 409);
        }

        $this->audit('team.deleted', $team);

        return response()->json(['message' => 'Team deleted.']);
    }

    #[OA\Post(
        summary: 'Create team token',
        description: 'Mint an API token inside a platform-managed team, owned by the team service account. Abilities are limited to read, write, deploy and read:sensitive. The plaintext token is returned once. Requires a `platform` token.',
        path: '/platform/teams/{id}/tokens',
        operationId: 'platform-create-team-token',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'abilities'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'abilities', type: 'array', items: new OA\Items(type: 'string', enum: self::MINTABLE_ABILITIES)),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Token created. `token` is only returned here.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'token', type: 'string'),
            ])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function createToken(Request $request, int $id): JsonResponse
    {
        $team = $this->platformTeam($id);
        if (! $team) {
            return $this->notFound();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->json()->all(), [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::MINTABLE_ABILITIES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        if ($response = $this->validationFailed($validator, ['name', 'abilities', 'expires_at'], $request)) {
            return $response;
        }
        $validated = $validator->validated();

        $serviceAccount = $team->members()->where('is_service_account', true)->first()
            ?? User::createServiceAccount($team);
        $abilities = array_values($validated['abilities']);
        sort($abilities);
        $expiresAt = filled($validated['expires_at'] ?? null) ? Carbon::parse($validated['expires_at']) : null;

        $newToken = $serviceAccount->createToken($validated['name'], $abilities, $expiresAt, $team->id);

        $this->audit('token.created', $team, [
            'minted_token_id' => $newToken->accessToken->id,
            'minted_token_name' => $validated['name'],
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);

        return response()->json([
            'id' => $newToken->accessToken->id,
            'token' => $newToken->plainTextToken,
        ], 201);
    }

    #[OA\Delete(
        summary: 'Revoke team token',
        description: 'Revoke a token owned by the team service account. Requires a `platform` token.',
        path: '/platform/teams/{id}/tokens/{tokenId}',
        operationId: 'platform-revoke-team-token',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'tokenId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Token revoked.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function revokeToken(int $id, int $tokenId): JsonResponse
    {
        $team = $this->platformTeam($id);
        $token = $team ? $this->serviceTokens($team)->whereKey($tokenId)->first() : null;
        if (! $token) {
            return $this->notFound();
        }

        $token->delete();
        $this->audit('token.revoked', $team, ['revoked_token_id' => $tokenId]);

        return response()->json(['message' => 'Token revoked.']);
    }

    #[OA\Post(
        summary: 'Share GitHub App',
        description: 'Let a platform-managed team deploy from a GitHub App marked shareable by its owning team. The team can use the source but cannot read its secrets, update or delete it. Requires a `platform` token.',
        path: '/platform/teams/{id}/github-apps/{githubAppUuid}/share',
        operationId: 'platform-share-github-app',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'githubAppUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Shared.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, description: 'The GitHub App is not shareable or already belongs to the team.'),
        ],
    )]
    public function shareGithubApp(int $id, string $githubAppUuid): JsonResponse
    {
        $team = $this->platformTeam($id);
        $githubApp = GithubApp::query()->where('uuid', $githubAppUuid)->first();
        if (! $team || ! $githubApp) {
            return $this->notFound();
        }

        if (! $githubApp->is_shareable || $githubApp->team_id === $team->id) {
            $this->audit('github_app.share_refused', $team, ['github_app_uuid' => $githubApp->uuid], 'warning');

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['github_app' => [$githubApp->is_shareable
                    ? 'The GitHub App already belongs to this team.'
                    : 'The GitHub App is not marked as shareable by its owning team.']],
            ], 422);
        }

        $team->sharedGithubApps()->syncWithoutDetaching([$githubApp->id]);
        $this->audit('github_app.shared', $team, ['github_app_uuid' => $githubApp->uuid, 'github_app_name' => $githubApp->name]);

        return response()->json(['message' => 'GitHub App shared.', 'github_app_uuid' => $githubApp->uuid, 'team_id' => $team->id]);
    }

    #[OA\Delete(
        summary: 'Unshare GitHub App',
        description: 'Stop sharing a GitHub App with a platform-managed team. Requires a `platform` token.',
        path: '/platform/teams/{id}/github-apps/{githubAppUuid}/share',
        operationId: 'platform-unshare-github-app',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'githubAppUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Unshared.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function unshareGithubApp(int $id, string $githubAppUuid): JsonResponse
    {
        $team = $this->platformTeam($id);
        $githubApp = GithubApp::query()->where('uuid', $githubAppUuid)->first();
        if (! $team || ! $githubApp) {
            return $this->notFound();
        }

        $team->sharedGithubApps()->detach($githubApp->id);
        $this->audit('github_app.unshared', $team, ['github_app_uuid' => $githubApp->uuid, 'github_app_name' => $githubApp->name]);

        return response()->json(['message' => 'GitHub App unshared.', 'github_app_uuid' => $githubApp->uuid, 'team_id' => $team->id]);
    }

    #[OA\Post(
        summary: 'Set Infisical connection',
        description: 'Create or update the Infisical connection of a platform-managed team. Credentials are encrypted at rest and never returned. On update, omitted credentials stay unchanged. Setting `is_enabled` to true for the first time starts the same adoption as the UI. Requires a `platform` token.',
        path: '/platform/teams/{id}/infisical-connection',
        operationId: 'platform-set-infisical-connection',
        security: [['bearerAuth' => []]],
        tags: ['Platform'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['host', 'infisical_project_id'],
                properties: [
                    new OA\Property(property: 'host', type: 'string', example: 'https://app.infisical.com'),
                    new OA\Property(property: 'client_id', type: 'string', description: 'Required on create.'),
                    new OA\Property(property: 'client_secret', type: 'string', description: 'Required on create.'),
                    new OA\Property(property: 'infisical_org_id', type: 'string', nullable: true),
                    new OA\Property(property: 'infisical_project_id', type: 'string'),
                    new OA\Property(property: 'name', type: 'string', description: 'Defaults to "Infisical".'),
                    new OA\Property(property: 'is_enabled', type: 'boolean'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Connection created.'),
            new OA\Response(response: 200, description: 'Connection updated.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Not a platform token.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function setInfisicalConnection(Request $request, int $id): JsonResponse
    {
        $team = $this->platformTeam($id);
        if (! $team) {
            return $this->notFound();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $connection = InfisicalConnection::query()->where('team_id', $team->id)->first();
        $credentialRule = $connection ? 'sometimes' : 'required';
        $fields = ['host', 'client_id', 'client_secret', 'infisical_org_id', 'infisical_project_id', 'name', 'is_enabled'];
        $validator = customApiValidator($request->json()->all(), [
            'host' => ['required', 'url:http,https', 'max:255'],
            'client_id' => [$credentialRule, 'string', 'filled', 'max:255'],
            'client_secret' => [$credentialRule, 'string', 'filled', 'max:512'],
            'infisical_org_id' => ['nullable', 'string', 'max:255'],
            'infisical_project_id' => ['required', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'min:1', 'max:128'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        if ($response = $this->validationFailed($validator, $fields, $request)) {
            return $response;
        }
        $validated = $validator->validated();

        $created = $connection === null;
        $connection ??= new InfisicalConnection(['team_id' => $team->id, 'name' => 'Infisical']);
        $connection->fill(collect($validated)->except('is_enabled')->all());
        $connection->save();

        $enable = ($validated['is_enabled'] ?? null) === true && ! $connection->is_enabled;
        if (($validated['is_enabled'] ?? null) === false) {
            $connection->forceFill(['is_enabled' => false])->save();
        } elseif ($enable) {
            // Same as the UI's enableConnection().
            $connection->forceFill(['is_enabled' => true, 'last_sync_status' => null, 'last_sync_error' => null])->save();
            InfisicalAdoptJob::dispatch($connection);
        }

        $this->audit($created ? 'infisical_connection.created' : 'infisical_connection.updated', $team, [
            'infisical_connection_uuid' => $connection->uuid,
            'changed_fields' => array_keys($validated),
        ]);

        return response()->json([
            'uuid' => $connection->uuid,
            'team_id' => $team->id,
            'name' => $connection->name,
            'host' => $connection->host,
            'infisical_org_id' => $connection->infisical_org_id,
            'infisical_project_id' => $connection->infisical_project_id,
            'is_enabled' => (bool) $connection->is_enabled,
        ], $created ? 201 : 200);
    }

    private function platformTeam(int $id): ?Team
    {
        return Team::query()->whereKey($id)->where('is_platform_managed', true)->first();
    }

    private function serviceTokens(Team $team)
    {
        return PersonalAccessToken::query()
            ->where('team_id', $team->id)
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $team->members()->where('is_service_account', true)->select('users.id'))
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function teamPayload(Team $team): array
    {
        return [
            'id' => $team->id,
            'uuid' => $team->uuid,
            'name' => $team->name,
            'description' => $team->description,
            'is_platform_managed' => (bool) $team->is_platform_managed,
            'created_at' => $team->created_at,
        ];
    }

    /**
     * @param  array<int, string>  $allowedFields
     */
    private function validationFailed($validator, array $allowedFields, Request $request): ?JsonResponse
    {
        $extraFields = array_diff(array_keys($request->json()->all()), $allowedFields);
        if (! $validator->fails() && $extraFields === []) {
            return null;
        }

        $errors = $validator->errors();
        foreach ($extraFields as $field) {
            $errors->add($field, 'This field is not allowed.');
        }

        return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    /**
     * Audit rows carry ids and names only, never token values, keys or secrets.
     *
     * @param  array<string, mixed>  $context
     */
    private function audit(string $action, ?Team $team, array $context = [], string $level = 'info'): void
    {
        [$resource, $verb] = explode('.', $action, 2);

        auditLog("api.platform_{$resource}.{$verb}", array_merge([
            'resource' => "platform_{$resource}",
            'action' => $verb,
            'actor_token_id' => auth()->user()?->currentAccessToken()?->id,
            'target_team_id' => $team?->id,
            'target_team_name' => $team?->name,
        ], $context), $level);
    }
}
