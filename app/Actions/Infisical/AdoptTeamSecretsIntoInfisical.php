<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalConnection;
use Lorisleiva\Actions\Concerns\AsAction;

class AdoptTeamSecretsIntoInfisical
{
    use AsAction;

    /**
     * Push every Coolify variable Infisical does not already hold, once.
     *
     * Never overwrites: a key Infisical already has keeps its Infisical value.
     *
     * Idempotent: a re-run pushes only what is still missing, folder creation
     * tolerates an already-exists response, and environment creation is skipped
     * when it already exists. A partial failure can therefore be retried
     * safely.
     *
     * adopted_at is stamped ONLY on a run where no environment was skipped,
     * so a permissions problem does not silently mark adoption complete.
     *
     * @return array{pushed: int, skippedEnvironments: array<int, string>}
     */
    public function handle(InfisicalConnection $connection): array
    {
        $client = $connection->client();
        $projectId = $connection->infisical_project_id;

        $buckets = CollectTeamSecrets::run($connection->team);

        $existing = $client->listEnvironmentSlugs($projectId);
        $needed = collect($buckets)->pluck('environment')->unique()->values();
        $skipped = [];

        foreach ($needed as $slug) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            if (! $client->createEnvironment($projectId, $slug, $slug)) {
                $skipped[] = $slug;
            }
        }

        $pushed = 0;

        foreach ($buckets as $bucket) {
            if (in_array($bucket['environment'], $skipped, true)) {
                continue;
            }

            if ($bucket['secrets'] === []) {
                continue;
            }

            $client->ensureFolderPath($projectId, $bucket['environment'], $bucket['path']);

            // Create-if-absent: adopting into a project that already holds
            // values must not overwrite them with Coolify's copies. Keys
            // deleted in Infisical ARE restored: "Sync now" runs adoption as
            // the recovery path for exactly that.
            $pushed += PullTeamSecrets::pushMissing(
                $client,
                $projectId,
                $bucket,
                $client->fetchSecrets($projectId, $bucket['environment'], $bucket['path']),
                restoreDeleted: true,
            );
        }

        $connection->forceFill([
            'last_synced_at' => now(),
            'last_sync_status' => InfisicalConnection::STATUS_SUCCESS,
            'last_sync_error' => $skipped === [] ? null
                : 'Could not create Infisical environment(s): '.implode(', ', $skipped),
            'adopted_at' => $skipped === [] ? now() : null,
        ])->save();

        return ['pushed' => $pushed, 'skippedEnvironments' => $skipped];
    }
}
