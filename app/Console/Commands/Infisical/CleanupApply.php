<?php

namespace App\Console\Commands\Infisical;

use App\Actions\Infisical\PlanInfisicalCleanup;
use App\Models\InfisicalConnection;
use Illuminate\Console\Command;

/**
 * Deletes exactly the items of a reviewed cleanup plan, and nothing else.
 *
 * Never scheduled. Infisical is re-read first, and an item is refused when it
 * is no longer stray, when its keys changed since the plan was written, or
 * when it holds secrets that are not present and equal in its correct
 * location. Without --confirm it only reports what it would do.
 */
class CleanupApply extends Command
{
    protected $signature = 'infisical:cleanup-apply {plan : Path to a plan JSON written by infisical:cleanup-plan} {--confirm : Actually delete}';

    protected $description = 'Delete the items of a reviewed Infisical cleanup plan (refuses unsafe items)';

    public function handle(): int
    {
        $plan = json_decode((string) @file_get_contents($this->argument('plan')), true);

        if (! is_array($plan) || ! isset($plan['team_id'], $plan['infisical_project_id'], $plan['items'])) {
            $this->error('Not a cleanup plan.');

            return self::FAILURE;
        }

        $connection = InfisicalConnection::query()->where('team_id', $plan['team_id'])->first();

        if ($connection === null || $connection->infisical_project_id !== $plan['infisical_project_id']) {
            $this->error('The plan does not match this team\'s current Infisical project.');

            return self::FAILURE;
        }

        $live = collect(PlanInfisicalCleanup::run($connection)['items'])->keyBy('id');
        $client = $connection->client();
        $projectId = $connection->infisical_project_id;
        $confirm = (bool) $this->option('confirm');
        $refused = 0;

        foreach ($plan['items'] as $planned) {
            $id = (string) ($planned['id'] ?? '');
            $item = $live->get($id);

            $reason = match (true) {
                $item === null => 'no longer stray',
                $item['keys_fingerprint'] !== ($planned['keys_fingerprint'] ?? null) => 'changed since the plan',
                $item['secret_count'] > 0 && ! $item['safe'] => 'secrets not present and equal in the correct location',
                default => null,
            };

            if ($reason !== null) {
                $refused++;
                $this->warn("refused {$id}: {$reason}");

                continue;
            }

            if (! $confirm) {
                $this->line("would delete {$id} ({$item['secret_count']} secrets)");

                continue;
            }

            if ($item['kind'] === 'environment') {
                $client->deleteEnvironment($projectId, $item['environment_id']);
            } else {
                $client->deleteFolder($projectId, $item['environment'], '/', trim($item['path'], '/'));
            }

            auditLog('infisical.cleanup.deleted', [
                'team_id' => $connection->team_id,
                'infisical_project_id' => $projectId,
                'item_id' => $id,
                'kind' => $item['kind'],
                'environment' => $item['environment'],
                'path' => $item['path'],
                'secret_count' => $item['secret_count'],
            ]);

            $this->info("deleted {$id} ({$item['secret_count']} secrets)");
        }

        if (! $confirm) {
            $this->line('Dry run. Re-run with --confirm to delete.');
        }

        return $refused > 0 ? self::FAILURE : self::SUCCESS;
    }
}
