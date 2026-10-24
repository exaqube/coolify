<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalConnection;
use App\Services\Infisical\FetchedSecrets;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalClient;
use App\Services\Infisical\InfisicalLock;
use App\Services\Infisical\InfisicalPath;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class PullTeamSecrets
{
    use AsAction;

    /**
     * Pull every folder of a team into Coolify.
     *
     * Additive and update-only: a key that has disappeared from Infisical is
     * deliberately LEFT IN PLACE. Removing a leaked credential therefore
     * requires a manual step in Coolify too. That is a recorded product
     * decision, not an oversight.
     *
     * This walks the WHOLE team — one HTTP round trip per bucket — and is
     * meant for the scheduled job. A deploy must use forResource() instead.
     *
     * @return array{created: int, updated: int, hidden: array<int, string>, skipped: array<int, string>}
     *
     * @throws InfisicalApiException
     */
    public function handle(InfisicalConnection $connection): array
    {
        $result = $this->pull($connection, null, reconcile: true);

        // Only a full walk may claim the connection is synced. A deploy-scoped
        // pull reads three folders out of a hundred; stamping last_synced_at
        // from it would tell the settings screen the team is up to date when
        // almost none of it was read.
        $connection->forceFill([
            'last_synced_at' => now(),
            'last_sync_status' => InfisicalConnection::STATUS_SUCCESS,
            'last_sync_error' => $this->describeDegradations($result),
            'infisical_org_id' => $connection->infisical_org_id ?: $this->organizationId($connection),
        ])->save();

        return $result;
    }

    /**
     * Pull only the folders the given resource actually inherits.
     *
     * `PullTeamSecrets::run()` issues one request per bucket; on a team with a
     * handful of projects, environments and resources that is well over a
     * hundred sequential requests before the first deploy step, any one of
     * which is fatal to the deploy. A deployment only ever reads `/`,
     * `/{project}/` and `/{project}/{resource}/` in its own environment slug,
     * so it fetches exactly those three.
     *
     * Implemented as an `$only` filter on the collector rather than a second
     * walk, so the bucket shape, the promotion rules and the creation targets
     * stay in one place.
     *
     * @return array{created: int, updated: int, hidden: array<int, string>, skipped: array<int, string>}
     *
     * @throws InfisicalApiException
     */
    public static function forResource(InfisicalConnection $connection, Model $resource): array
    {
        $environment = $resource->environment;
        $project = $environment?->project;

        if ($environment === null || $project === null) {
            return ['created' => 0, 'updated' => 0, 'hidden' => [], 'skipped' => []];
        }

        $envSlug = InfisicalPath::environmentSlug($environment->name);

        $only = [
            $envSlug.'|'.InfisicalPath::forTeam(),
            $envSlug.'|'.InfisicalPath::forProject($project->name),
            $envSlug.'|'.InfisicalPath::forResource($project->name, $resource->name),
        ];

        return (new static)->pull($connection, $only);
    }

    /**
     * Deploy-time pull: fetch the resource's folders fresh if its team has an
     * adopted connection, and do nothing otherwise.
     *
     * Every deploy and start path calls this, applications and compose
     * services alike, so an edit made in Infisical takes effect on the next
     * deploy without waiting for the scheduled sync. A connection failure is
     * deliberately NOT caught: failing the deploy beats shipping stale values.
     *
     * @throws InfisicalApiException
     */
    public static function beforeDeploy(Model $resource): void
    {
        $teamId = $resource->environment?->project?->team_id;

        if ($teamId === null) {
            return;
        }

        $connection = InfisicalConnection::query()
            ->where('team_id', $teamId)
            ->where('is_enabled', true)
            ->whereNotNull('adopted_at')
            ->first();

        if ($connection !== null) {
            static::forResource($connection, $resource);
        }
    }

    /**
     * Alias kept because a deployment is always an Application. Present so the
     * deploy call site reads as the plan describes it.
     *
     * @return array{created: int, updated: int, hidden: array<int, string>, skipped: array<int, string>}
     *
     * @throws InfisicalApiException
     */
    public static function forApplication(InfisicalConnection $connection, Model $application): array
    {
        return static::forResource($connection, $application);
    }

    /**
     * @param  array<int, string>|null  $only  Bucket ids, or null for the whole team.
     * @return array{created: int, updated: int, hidden: array<int, string>, skipped: array<int, string>}
     *
     * @throws InfisicalApiException
     */
    private function pull(InfisicalConnection $connection, ?array $only, bool $reconcile = false): array
    {
        $client = $connection->client();
        $projectId = $connection->infisical_project_id;

        $buckets = CollectTeamSecrets::run($connection->team, $only);

        $created = 0;
        $updated = 0;
        $pushed = 0;
        $hidden = [];
        $skipped = [];
        $unreachable = [];

        $tornDown = [];

        if ($reconcile) {
            $unreachable = $this->ensureEnvironments($client, $projectId, $buckets);

            // Teardown is housekeeping. It must never stop a team syncing, so
            // any failure here is swallowed: an identity without permission to
            // inspect an environment simply leaves it alone.
            try {
                $tornDown = $this->tearDownStaleEnvironments($client, $projectId, $buckets);
            } catch (InfisicalApiException $e) {
                $tornDown = [];
            }
        }

        foreach ($buckets as $bucket) {
            // An environment Infisical refused to create has nothing to read
            // from and nowhere to write to.
            if (in_array($bucket['environment'], $unreachable, true)) {
                continue;
            }

            if ($reconcile) {
                // A project, resource or environment created in Coolify after
                // adoption has no folder yet. Without this, only adoption ever
                // created them and anything added later never synced.
                $client->ensureFolderPath($projectId, $bucket['environment'], $bucket['path']);
            }

            $fetched = $client->fetchSecrets($projectId, $bucket['environment'], $bucket['path']);

            $hidden = array_merge($hidden, $fetched->hiddenKeys);

            if ($reconcile) {
                $pushed += static::pushMissing($client, $projectId, $bucket, $fetched);
            }

            if ($fetched->values === []) {
                continue;
            }

            // Every write below came DOWN from Infisical, so the upward push
            // hook must not echo it back up.
            InfisicalLock::asInfisicalPull(function () use ($bucket, $fetched, &$created, &$updated, &$skipped): void {
                foreach ($fetched->values as $key => $value) {
                    // `rows` is a plain array MAP, not a Collection.
                    $row = $bucket['rows'][$key] ?? null;

                    if ($row !== null) {
                        // Stamp provenance unconditionally, not only when the
                        // value moved: the read-only UI keys off the flag, and
                        // a row that already happens to match Infisical would
                        // otherwise keep rendering as editable. `updated` still
                        // counts real value changes only.
                        $changed = (string) $row->value !== $value;

                        $row->forceFill([
                            'value' => $value,
                            'is_infisical_managed' => true,
                            'infisical_path' => $bucket['path'],
                        ])->save();

                        if ($changed) {
                            $updated++;
                        }

                        continue;
                    }

                    $writer = $bucket['writers'][$key] ?? null;

                    if ($writer !== null) {
                        $writer($value);
                        $updated++;

                        continue;
                    }

                    if ($bucket['create'] === null) {
                        // A replicated team/project bucket is visited once per
                        // Infisical environment, so creating here would produce
                        // one duplicate Coolify row per environment for a
                        // single logical variable. Surface the key instead of
                        // silently dropping it.
                        $skipped[] = $key;

                        continue;
                    }

                    if (($bucket['create'])($key, $value) !== null) {
                        $created++;
                    }
                }
            });
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'pushed' => $pushed,
            'hidden' => array_values(array_unique($hidden)),
            'skipped' => array_values(array_unique($skipped)),
            'unreachableEnvironments' => $unreachable,
            'tornDownEnvironments' => $tornDown,
        ];
    }

    /**
     * Create any Infisical environment a Coolify environment maps to but that
     * does not exist yet.
     *
     * Adoption used to be the only thing that created environments, and it
     * runs once. A Coolify environment added afterwards therefore had nowhere
     * to sync to until somebody pressed "Sync now".
     *
     * @param  array<string, array<string, mixed>>  $buckets
     * @return array<int, string> environment slugs that could not be created
     *
     * @throws InfisicalApiException
     */
    private function ensureEnvironments($client, string $projectId, array $buckets): array
    {
        $needed = collect($buckets)->pluck('environment')->unique()->values();

        if ($needed->isEmpty()) {
            return [];
        }

        $existing = $client->listEnvironmentSlugs($projectId);
        $unreachable = [];

        foreach ($needed as $slug) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            if (! $client->createEnvironment($projectId, $slug, $slug)) {
                $unreachable[] = $slug;
            }
        }

        return $unreachable;
    }

    /**
     * Delete Infisical environments that no Coolify environment maps to.
     *
     * ONLY removes an environment that is completely empty - no secrets and
     * no folders, checked recursively. Everything else in this integration is
     * additive precisely so a bug cannot destroy secrets, and environment
     * deletion is the one genuinely destructive operation, so it is fenced.
     *
     * The fence matters because slug matching has failed before: Infisical
     * seeds dev/staging/prod while Coolify derives slugs from its own
     * environment names, and the API returns only what the identity can see,
     * so a permissions blip looks identical to "this is stale". An emptiness
     * check means the worst case is a no-op rather than data loss.
     *
     * @param  array<string, array<string, mixed>>  $buckets
     * @return array<int, string> slugs actually deleted
     *
     * @throws InfisicalApiException
     */
    private function tearDownStaleEnvironments($client, string $projectId, array $buckets): array
    {
        $wanted = collect($buckets)->pluck('environment')->unique()->all();

        // Nothing to compare against: never interpret an empty walk as
        // "delete everything".
        if ($wanted === []) {
            return [];
        }

        $deleted = [];

        foreach ($client->listEnvironments($projectId) as $slug => $environmentId) {
            if (in_array($slug, $wanted, true)) {
                continue;
            }

            try {
                if (! $client->environmentIsEmpty($projectId, $slug)) {
                    continue;
                }

                $client->deleteEnvironment($projectId, $environmentId);
                $deleted[] = $slug;
            } catch (InfisicalApiException $e) {
                // Cannot see inside it, so cannot prove it is empty. Never
                // delete on an unknown: leave it and move on.
                continue;
            }
        }

        return $deleted;
    }

    /**
     * Push only the keys Coolify holds that Infisical does not, then mark every
     * row Infisical now holds as Infisical-managed.
     *
     * This is the ONLY upward write. Adoption, the per-folder push job and the
     * scheduled sync all go through it, so Coolify can create a key in
     * Infisical but can never overwrite one. Infisical is the source of truth
     * once adopted, so re-pushing a key that already exists there would
     * overwrite an edit made in Infisical with Coolify's older copy.
     *
     * A row already stamped as managed for THIS folder was in Infisical before.
     * If Infisical no longer has it, somebody deleted it there, so it is not
     * pushed back. Without this, deleting a leaked credential in Infisical was
     * undone by the next sync. The path is part of the test so a renamed
     * project or resource, which moves the folder, is still pushed.
     * `$restoreDeleted` turns that off for adoption, which "Sync now" runs as
     * the explicit recovery path for an Infisical that lost its secrets.
     *
     * Credential columns have no row to carry the stamp, so a database
     * password deleted in Infisical is still pushed back.
     *
     * Verified against a live instance: mode=ignore does NOT give
     * create-if-absent semantics on the self-hosted API (it updates existing
     * keys and skips new ones), so the missing set has to be computed here
     * from a read.
     *
     * @param  array<string, mixed>  $bucket
     *
     * @throws InfisicalApiException
     */
    public static function pushMissing(InfisicalClient $client, string $projectId, array $bucket, FetchedSecrets $fetched, bool $restoreDeleted = false): int
    {
        $missing = array_diff_key($bucket['secrets'], $fetched->values);

        // A key Infisical hid from us exists there; pushing would clobber it.
        foreach ($fetched->hiddenKeys as $hiddenKey) {
            unset($missing[$hiddenKey]);
        }

        foreach (array_keys($restoreDeleted ? [] : $missing) as $key) {
            $row = $bucket['rows'][$key] ?? null;

            if ($row !== null && $row->is_infisical_managed && $row->infisical_path === $bucket['path']) {
                unset($missing[$key]);
            }
        }

        $client->upsertSecrets($projectId, $bucket['environment'], $bucket['path'], $missing);

        // Stamp only what Infisical actually holds. An empty row was never
        // pushed, and stamping it would stop its first real value from being
        // pushed. These saves change only the two provenance columns, so the
        // saved hook's "did key or value move" guard stops them from queueing
        // another push.
        $held = array_flip([...array_keys($fetched->values), ...$fetched->hiddenKeys, ...array_keys($missing)]);

        InfisicalLock::asSystem(function () use ($bucket, $held): void {
            foreach ($bucket['rows'] as $key => $row) {
                if (! isset($held[$key])) {
                    continue;
                }

                $row->forceFill([
                    'is_infisical_managed' => true,
                    'infisical_path' => $bucket['path'],
                ])->save();
            }
        });

        return count($missing);
    }

    /**
     * Only feeds the "Open in Infisical" link, so it must never fail a sync.
     */
    private function organizationId(InfisicalConnection $connection): ?string
    {
        try {
            return $connection->client()->organizationId($connection->infisical_project_id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Human-readable summary of everything the pull could not do, or null when
     * it did everything.
     *
     * @param  array{created: int, updated: int, hidden: array<int, string>, skipped: array<int, string>}  $result
     */
    private function describeDegradations(array $result): ?string
    {
        $messages = [];

        if ($result['hidden'] !== []) {
            $messages[] = 'Unreadable secret(s), identity lacks secrets:readValue: '
                .implode(', ', $result['hidden']);
        }

        if ($result['skipped'] !== []) {
            $messages[] = 'Secret(s) only present in Infisical at team or project scope, which has no unambiguous Coolify destination: '
                .implode(', ', $result['skipped']);
        }

        if (($result['unreachableEnvironments'] ?? []) !== []) {
            $messages[] = 'Could not create Infisical environment(s), so they did not sync - the machine identity needs environments:create: '
                .implode(', ', $result['unreachableEnvironments']);
        }

        return $messages === [] ? null : implode(' ', $messages);
    }
}
