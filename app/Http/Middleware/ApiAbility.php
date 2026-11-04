<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

class ApiAbility extends CheckForAnyAbility
{
    /**
     * Permissions that only admins/owners may use.
     */
    private const MEMBER_DISALLOWED_ABILITIES = [
        'root',
        'platform',
        'write',
        'write:sensitive',
        'deploy',
        'read:sensitive',
    ];

    public function handle($request, $next, ...$abilities)
    {
        try {
            $token = $request->user()->currentAccessToken();
            $teamId = data_get($token, 'team_id');

            if ($teamId !== null && ! $request->user()->isAdminOfTeam((int) $teamId)) {
                $tokenAbilities = $token->abilities ?? [];
                $disallowed = array_intersect($tokenAbilities, self::MEMBER_DISALLOWED_ABILITIES);

                if (! empty($disallowed)) {
                    return response()->json([
                        'message' => 'This API token has permissions ('.implode(', ', $disallowed).') that exceed your current role as a team member. Members are restricted to read-only API access. Please revoke this token and create a new one with only read permissions.',
                    ], 403);
                }
            }

            // `platform` is never implied by `root` or `*`: only an explicit
            // platform token of a root-team admin may call /platform/*.
            if (in_array('platform', $abilities, true)) {
                if (! self::isPlatformToken($request->user())) {
                    auditLog('api.auth.ability_denied', [
                        'required_abilities' => $abilities,
                        'token_id' => $token?->id,
                        'reason' => 'not a platform token',
                    ], 'warning');

                    return response()->json(['message' => 'Missing required permissions: platform'], 403);
                }

                return $next($request);
            }

            if ($request->user()->tokenCan('root')) {
                return $next($request);
            }

            return parent::handle($request, $next, ...$abilities);
        } catch (AuthenticationException $e) {
            auditLog('api.auth.unauthenticated', [
                'reason' => $e->getMessage(),
                'required_abilities' => $abilities,
            ], 'warning');

            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        } catch (\Exception $e) {
            auditLog('api.auth.ability_denied', [
                'required_abilities' => $abilities,
                'token_id' => $request->user()?->currentAccessToken()?->id,
                'reason' => $e->getMessage(),
            ], 'warning');

            return response()->json([
                'message' => 'Missing required permissions: '.implode(', ', $abilities),
            ], 403);
        }
    }

    /**
     * A platform token carries the literal `platform` ability, belongs to the root team,
     * and its owner is still an admin or owner of the root team.
     */
    public static function isPlatformToken(?User $user): bool
    {
        $token = $user?->currentAccessToken();

        return $token !== null
            && in_array('platform', $token->abilities ?? [], true)
            && (string) data_get($token, 'team_id') === '0'
            && $user->isAdminOfTeam(0);
    }
}
