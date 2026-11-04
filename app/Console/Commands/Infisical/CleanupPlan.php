<?php

namespace App\Console\Commands\Infisical;

use App\Actions\Infisical\PlanInfisicalCleanup;
use App\Models\InfisicalConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only. Lists what the old team-wide sync left in each team's Infisical
 * project and writes a JSON and a markdown plan per team. Deletes nothing.
 */
class CleanupPlan extends Command
{
    protected $signature = 'infisical:cleanup-plan {team? : Team id; every team with an Infisical connection when omitted}';

    protected $description = 'Read-only: list Infisical folders and environments that do not belong where the old sync put them';

    public function handle(): int
    {
        $connections = InfisicalConnection::query()
            ->when($this->argument('team') !== null, fn ($q) => $q->where('team_id', (int) $this->argument('team')))
            ->orderBy('team_id')
            ->get();

        if ($connections->isEmpty()) {
            $this->warn('No Infisical connection found.');

            return self::FAILURE;
        }

        $failed = false;
        $stamp = now()->utc()->format('Ymd\THis\Z');

        foreach ($connections as $connection) {
            try {
                $plan = PlanInfisicalCleanup::run($connection);
            } catch (Throwable $e) {
                $failed = true;
                $this->error("team {$connection->team_id}: {$e->getMessage()}");

                continue;
            }

            $base = "infisical-cleanup-{$connection->team_id}-{$stamp}";
            Storage::disk('local')->put("{$base}.json", json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            Storage::disk('local')->put("{$base}.md", PlanInfisicalCleanup::markdown($plan));

            $items = collect($plan['items']);
            $this->line(sprintf(
                'team %d (%s): %d stray folders, %d unused environments, %d safe, %d need review -> %s.{json,md}',
                $plan['team_id'], $plan['team_name'],
                $items->where('kind', 'folder')->count(), $items->where('kind', 'environment')->count(),
                $items->where('safe', true)->count(), $items->where('safe', false)->count(),
                Storage::disk('local')->path($base),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
