<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalConnection;
use App\Models\Project;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalPath;
use Lorisleiva\Actions\Concerns\AsAction;

class PlanInfisicalCleanup
{
    use AsAction;

    /**
     * Read-only: what the old team-wide sync left in a team's Infisical
     * project that no longer belongs there.
     *
     * Two kinds of item:
     *
     * - `folder`: `/{project}/` in an environment the Coolify project does not
     *   own. The old sync replicated every project's folder into every
     *   environment of the team.
     * - `environment`: an Infisical environment no Coolify environment maps to.
     *
     * For every item it counts the secrets and checks each one against its
     * correct location: the same path and key in one of the environments that
     * really own that path (the project's environments for `/{project}/…`,
     * any used environment for the team root `/`). Values are compared by
     * SHA-256 and never leave this method. A hidden value cannot be compared
     * and so never matches. `safe` means every secret matched, which is what
     * cleanup-apply requires before it deletes a non-empty item.
     *
     * Root folders that match no Coolify project are listed under
     * `unmatched_folders` for the owner to look at. They are never items: the
     * sync did not create them, so nothing here can say they are stray.
     *
     * @return array{
     *   team_id: int,
     *   team_name: string,
     *   infisical_project_id: string,
     *   generated_at: string,
     *   project_environments: array<string, array<int, string>>,
     *   items: array<int, array<string, mixed>>,
     *   unmatched_folders: array<int, array{environment: string, path: string, secret_count: int}>
     * }
     *
     * @throws InfisicalApiException
     */
    public function handle(InfisicalConnection $connection): array
    {
        $team = $connection->team;
        $projectId = (string) $connection->infisical_project_id;
        $client = $connection->client();

        // folder segment => [project name, owned env slugs]
        $owners = [];
        foreach ($team->projects()->with('environments')->get() as $project) {
            /** @var Project $project */
            $owners[trim(InfisicalPath::forProject($project->name), '/')] = [
                'project' => $project->name,
                'environments' => CollectTeamSecrets::projectEnvironmentSlugs($project),
            ];
        }

        $used = collect($owners)->pluck('environments')->flatten()->unique()->values()->all();

        $environments = $client->listEnvironments($projectId);

        // env slug => path => key => sha256 (null when hidden)
        $hashes = [];
        $roots = [];
        foreach (array_keys($environments) as $slug) {
            foreach ($client->fetchEnvironmentSecrets($projectId, $slug) as $path => $secrets) {
                $hashes[$slug][$path] = array_map(fn (?string $value) => $value === null ? null : hash('sha256', $value), $secrets);
            }
            $roots[$slug] = $client->listFolderNames($projectId, $slug, '/');
        }

        $correctFor = function (string $path) use ($owners, $used): array {
            if ($path === '/') {
                return $used;
            }

            $segment = explode('/', trim($path, '/'))[0];

            return $owners[$segment]['environments'] ?? [];
        };

        $items = [];
        $unmatched = [];

        foreach ($environments as $slug => $environmentId) {
            if (! in_array($slug, $used, true)) {
                $items[] = $this->item('environment', $slug, $environmentId, '/', null, $hashes[$slug] ?? [], $hashes, $correctFor);

                continue;
            }

            foreach ($roots[$slug] as $folder) {
                $path = "/{$folder}/";
                $subtree = array_filter($hashes[$slug] ?? [], fn (string $p) => str_starts_with($p, $path), ARRAY_FILTER_USE_KEY);

                if (! isset($owners[$folder])) {
                    $unmatched[] = ['environment' => $slug, 'path' => $path, 'secret_count' => $this->count($subtree)];

                    continue;
                }

                if (in_array($slug, $owners[$folder]['environments'], true)) {
                    continue;
                }

                $items[] = $this->item('folder', $slug, $environmentId, $path, $owners[$folder]['project'], $subtree, $hashes, $correctFor);
            }
        }

        return [
            'team_id' => (int) $team->id,
            'team_name' => (string) $team->name,
            'infisical_project_id' => $projectId,
            'generated_at' => now()->toIso8601String(),
            'project_environments' => collect($owners)->mapWithKeys(fn ($o) => [$o['project'] => $o['environments']])->all(),
            'items' => $items,
            'unmatched_folders' => $unmatched,
        ];
    }

    /**
     * @param  array<string, array<string, ?string>>  $subtree  path => key => hash
     * @param  array<string, array<string, array<string, ?string>>>  $hashes
     * @return array<string, mixed>
     */
    private function item(string $kind, string $slug, string $environmentId, string $path, ?string $project, array $subtree, array $hashes, \Closure $correctFor): array
    {
        $keys = [];
        $missing = [];
        $different = [];
        $correct = $kind === 'folder' ? array_diff($correctFor($path), [$slug]) : [];

        foreach ($subtree as $secretPath => $secrets) {
            $targets = array_values(array_diff($correctFor($secretPath), [$slug]));
            $correct = [...$correct, ...$targets];

            foreach ($secrets as $key => $hash) {
                $name = $secretPath.$key;
                $keys[] = $name;

                $found = false;
                $equal = false;
                foreach ($targets as $target) {
                    $there = $hashes[$target][$secretPath] ?? [];
                    if (array_key_exists($key, $there)) {
                        $found = true;
                        if ($hash !== null && $there[$key] !== null && hash_equals($there[$key], $hash)) {
                            $equal = true;
                            break;
                        }
                    }
                }

                if (! $found) {
                    $missing[] = $name;
                } elseif (! $equal) {
                    $different[] = $name;
                }
            }
        }

        sort($keys);

        return [
            'id' => $kind === 'environment' ? "environment:{$slug}" : "folder:{$slug}:{$path}",
            'kind' => $kind,
            'environment' => $slug,
            'environment_id' => $environmentId,
            'path' => $path,
            'project' => $project,
            'secret_count' => count($keys),
            'correct_environments' => array_values(array_unique($correct)),
            'missing' => $missing,
            'different' => $different,
            'safe' => $missing === [] && $different === [],
            // Key names only, so apply can refuse an item that changed after review.
            'keys_fingerprint' => hash('sha256', implode("\n", $keys)),
        ];
    }

    /**
     * @param  array<string, array<string, ?string>>  $subtree
     */
    private function count(array $subtree): int
    {
        return array_sum(array_map('count', $subtree));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public static function markdown(array $plan): string
    {
        $items = collect($plan['items']);
        $lines = [
            "# Infisical cleanup plan: {$plan['team_name']} (team {$plan['team_id']})",
            '',
            "Infisical project `{$plan['infisical_project_id']}`, generated {$plan['generated_at']}. Read-only; nothing was deleted.",
            '',
            '| | count |',
            '|---|---|',
            '| Stray folders (project folder in a foreign environment) | '.$items->where('kind', 'folder')->count().' |',
            '| Unused environments | '.$items->where('kind', 'environment')->count().' |',
            '| Safe to delete (all secrets present and equal in the correct location, or empty) | '.$items->where('safe', true)->count().' |',
            '| Needs review (some secret missing or different in the correct location) | '.$items->where('safe', false)->count().' |',
            '| Unmatched root folders (no Coolify project, informational) | '.count($plan['unmatched_folders']).' |',
            '',
            '## Items',
            '',
            '| id | kind | environment | path | project | secrets | safe | correct environments |',
            '|---|---|---|---|---|---|---|---|',
        ];

        foreach ($items as $item) {
            $lines[] = sprintf('| `%s` | %s | %s | `%s` | %s | %d | %s | %s |',
                $item['id'], $item['kind'], $item['environment'], $item['path'], $item['project'] ?? '-',
                $item['secret_count'], $item['safe'] ? 'yes' : '**no**', implode(', ', $item['correct_environments']) ?: '-');
        }

        $unsafe = $items->where('safe', false);
        if ($unsafe->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Needs review (key names only)';
            foreach ($unsafe as $item) {
                $lines[] = '';
                $lines[] = "### `{$item['id']}`";
                if ($item['missing'] !== []) {
                    $lines[] = '- Not present in any correct environment: '.implode(', ', array_map(fn ($k) => "`{$k}`", $item['missing']));
                }
                if ($item['different'] !== []) {
                    $lines[] = '- Present but with a different value: '.implode(', ', array_map(fn ($k) => "`{$k}`", $item['different']));
                }
            }
        }

        if ($plan['unmatched_folders'] !== []) {
            $lines[] = '';
            $lines[] = '## Unmatched root folders (not part of the plan)';
            $lines[] = '';
            $lines[] = '| environment | path | secrets |';
            $lines[] = '|---|---|---|';
            foreach ($plan['unmatched_folders'] as $folder) {
                $lines[] = "| {$folder['environment']} | `{$folder['path']}` | {$folder['secret_count']} |";
            }
        }

        $lines[] = '';
        $lines[] = 'To delete, review the JSON plan, remove any item you want to keep, then run `php artisan infisical:cleanup-apply <plan.json> --confirm`. Apply re-reads Infisical and refuses any non-empty item that is not safe or that changed since this plan.';

        return implode("\n", $lines)."\n";
    }
}
