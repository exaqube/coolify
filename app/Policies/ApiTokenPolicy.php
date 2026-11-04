<?php

namespace App\Policies;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenPolicy
{
    /**
     * Determine whether the user can view any API tokens.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the API token.
     */
    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $this->belongsToUserAndCurrentTeam($user, $token);
    }

    /**
     * Determine whether the user can create API tokens.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the API token.
     */
    public function update(User $user, PersonalAccessToken $token): bool
    {
        return $this->belongsToUserAndCurrentTeam($user, $token);
    }

    /**
     * Determine whether the user can delete the API token.
     */
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $this->belongsToUserAndCurrentTeam($user, $token);
    }

    /**
     * Determine whether the user can manage their own API tokens.
     */
    public function manage(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can use root permissions for API tokens.
     */
    public function useRootPermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Platform tokens (instance-level team provisioning) are limited to admins and owners
     * of the root team, created while the root team is the session team.
     */
    public function usePlatformPermissions(User $user): bool
    {
        return $user->currentTeam()?->id === 0 && $user->isAdminOfTeam(0);
    }

    /**
     * Determine whether the user can use write permissions for API tokens.
     */
    public function useWritePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can use deploy permissions for API tokens.
     */
    public function useDeployPermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    /**
     * Determine whether the user can use read:sensitive permissions for API tokens.
     */
    public function useSensitivePermissions(User $user): bool
    {
        return $user->isAdmin() || $user->isOwner();
    }

    private function belongsToUserAndCurrentTeam(User $user, PersonalAccessToken $token): bool
    {
        if ($user->id !== $token->tokenable_id || $token->tokenable_type !== User::class) {
            return false;
        }

        $currentTeamId = $user->currentTeam()?->id;

        if ($currentTeamId === null || $token->team_id === null) {
            return false;
        }

        return (string) $currentTeamId === (string) $token->team_id;
    }
}
