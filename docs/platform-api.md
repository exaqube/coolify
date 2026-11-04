# Platform API (`platform` token ability)

The platform API lets one service provision customer teams end to end: create
the team, mint the team's API tokens, share a GitHub App source with it, and
link its Infisical project. Everything inside a team (keys, servers, projects,
databases, applications, deploys) still goes through the normal team endpoints,
called with a token minted here.

## The `platform` ability

- Only an admin or owner of the root team (`team_id = 0`) can create one, from
  **Keys & Tokens → API tokens** while the root team is the active team. The
  checkbox is hidden for everyone else, and `addNewToken()` re-checks the
  `usePlatformPermissions` policy on submit.
- A platform token carries `platform` and nothing else.
- `root` and `*` do **not** imply `platform` (`ApiAbility::isPlatformToken()`).
  The token must belong to the root team and its owner must still be a root
  team admin or owner.
- A platform token cannot call any team endpoint or MCP:
  `EnsureTokenBelongsToCurrentTeamMember` (`api.token.team`) answers 403.
- Routes live in `routes/api.php` under `/api/v1/platform`, with
  `auth:sanctum`, `ApiAllowed` and `api.ability:platform`. The normal `api`
  rate limiter applies.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| `POST` | `/teams` | `{name, description?}`. 201 on create, 200 when a platform-managed team with that name exists, 409 when another team uses the name. |
| `GET` | `/teams` | Platform-managed teams plus the root team. |
| `GET` | `/teams/{id}` | Includes service-account tokens (no values), shared GitHub Apps and whether an Infisical connection exists. |
| `DELETE` | `/teams/{id}` | Platform-managed teams only. 409 with `blockers` while projects, servers or own sources exist. Removes the service account and every team token. |
| `POST` | `/teams/{id}/tokens` | `{name, abilities, expires_at?}`. Abilities are a subset of `read, write, deploy, read:sensitive`; anything else is 422. Returns `{id, token}` once. |
| `DELETE` | `/teams/{id}/tokens/{tokenId}` | Only tokens owned by that team's service account. |
| `POST` | `/teams/{id}/github-apps/{uuid}/share` | 422 unless the app's owning team marked it shareable. |
| `DELETE` | `/teams/{id}/github-apps/{uuid}/share` | Unshare. |
| `POST` | `/teams/{id}/infisical-connection` | `{host, client_id, client_secret, infisical_project_id, infisical_org_id?, name?, is_enabled?}`. Credentials are required on create, optional on update, encrypted at rest and never returned. `is_enabled: true` starts the same adoption job as the UI. |

Team-scoped `/teams/{id}/…` endpoints only act on platform-managed teams
(`teams.is_platform_managed`); other teams return 404.

## Service accounts

Each platform team gets one user `svc+<team-uuid>@platform.local`
(`users.is_service_account`), admin of that team only, with no password and no
personal team. It owns every token minted for the team. It cannot sign in:
password login (`FortifyServiceProvider`), OAuth (`OauthLoginService`),
invitation links and impersonation refuse it, and `DecideWhatToDoWithUser`
ends any web session it somehow has. The members list shows a **Service
account** badge.

## Shareable GitHub Apps

`github_apps.is_shareable` (default false) is toggled by the owning team's
admins in the source's settings or with `PATCH /api/v1/github-apps/{id}`.
Shares are rows in `github_app_team`. `GithubApp::usableByTeam($teamId)` (own,
system-wide, or shareable and shared) is used wherever a team picks or deploys
from a source; edit and delete paths stay scoped to `team_id`, so a sharing
team can deploy but cannot update, delete or read the app's secrets or private
key. Turning `is_shareable` off stops every share immediately.

## Audit

Every call writes `api.platform_<resource>.<action>` through `auditLog()`,
with the actor token id, `target_team_id` and `target_team_name`. Token values,
private keys and Infisical credentials are never part of the context.

Tests: `tests/Feature/Api/PlatformApiTest.php`.
