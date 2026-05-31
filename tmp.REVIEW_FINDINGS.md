# Manual/Assisted Code-Review Findings
> **Status:** In Progress  
> **Updated:** 2026-05-31  
> **Owner:** Dominik Letica  
> **Purpose:** Resolve the findings listed below, trace affected code paths and neighboring workflows, identify equivalent defect patterns elsewhere in the repository, and apply consistent fixes across all impacted locations while preserving project architecture and documented behavior. Create separate commits for unrelated findings; only group changes into a single commit when they address the same root cause, workflow or defect family.
  
## Open
### P0 | Investigation Required | Runtime / Bootstrap Memory Analysis
> This item is investigative only and should not be closed until a concrete root cause has been identified and verified.

After a clean checkout or git clean -fdx, the first bin/init execution can exhaust the default PHP memory_limit of 128M during cold bootstrap, while subsequent executions succeed. The project source code (excluding vendor and assets) is comparatively small and the translation cache warmup alone does not appear sufficient to explain the observed peak memory usage.
A detailed investigation is required to identify the actual memory hotspot. Focus on cold-start execution paths that are only triggered during a fresh initialization, including but not limited to cache warmers, translation generation, package discovery, messenger dispatching, doctrine metadata loading, twig template compilation, runtime cache generation and test bootstrap procedures.
The goal is not to raise memory limits but to identify the specific subsystem, service graph or initialization sequence responsible for the unexpected memory growth and reduce peak memory consumption to remain compatible with constrained shared-hosting environments (128M limit).
Additional context:
- The issue is reproducible only after a fully clean initialization.
- Subsequent runs succeed without changes.
- PHPUnit memory usage also increased significantly compared to previous runs.
- Translation catalogue generation is currently a primary suspect but has not yet been proven to be the sole cause.
- Framework upgrade to 8.1 does not resolve the cold-init OOM; root cause is likely in project-specific initialization/warmup behavior or container compilation.

Do not start with fixes! First establish a memory profile. Measure. Then isolate. Then fix. Symfony\Component\Stopwatch\Stopwatch might help with that. Always prefer code optimization over environment adjustments.

### P4 | Audit required: Project goal/rules drift
The project goals explicitly prefer existing Symfony and vendor components over custom implementations where equivalent, well-maintained functionality is already available. A targeted audit should identify areas where custom abstractions duplicate functionality that is already provided by Symfony or available vendor packages.
Potential examples requiring verification:
- Process execution paths using proc_* functions instead of the Symfony Process component.
- UI/UX abstractions that reimplement functionality already available through Symfony UX, Stimulus, Turbo or Tailwind utility/component patterns.
- Custom helper, utility or infrastructure layers that duplicate existing Symfony framework features without providing project-specific value.

This is not necessarily a functional defect. The goal is to identify maintainability, consistency and long-term supportability issues that drift away from the project's architectural goals and repository guidelines.
Only report findings that can be demonstrated by concrete code locations and existing vendor/framework alternatives.

## Fixed
### P1 | Avoid restoring elevated roles through public registration
When registration is in auto_approval mode and the submitted email belongs to a deleted admin/owner account, this copies the deleted account's previous role into the new registration token; the acceptance path later calls changeRole($accountToken->role()), so anyone with access to that mailbox can self-reactivate the deleted account with its old elevated role without an admin review. Use the normal public registration role for auto-approved deleted-account registrations, or force these reactivation tokens through admin approval before preserving elevated roles.
**- Fixed by forcing public auto-approval reactivation requests for deleted accounts above the normal User role into pending admin approval while preserving the elevated role only for the approval workflow.**

### P1 | Use portable SQL when dropping the ACL index
On MySQL/MariaDB installs that still have idx_acl_group_access_level, this raw DROP INDEX idx_acl_group_access_level statement uses PostgreSQL/SQLite syntax; MySQL requires dropping an index in the context of its table. That makes this migration fail before the role backfill and column cleanup can run, blocking upgrades on the supported MySQL path; use Doctrine schema operations or platform-specific SQL for the index drop.
**– Fixed by emitting MySQL/MariaDB-specific `DROP INDEX ... ON acl_group` SQL while keeping the existing PostgreSQL/SQLite form for other platforms.**

### P2 | Validate notification email settings
When an admin enters a non-empty but invalid address for either notification setting, the form accepts it because these fields only have a length check, while UserFlowConfig::normalizedEmailSetting() later returns null for invalid emails. In admin-approval registration or password-dispute flows this means the user/request can be accepted but the configured administrator notification is silently dropped, so reject invalid non-empty email values at settings-save time.
**– Fixed by validating both optional notification email settings in the Users settings form before persistence and adding localized UI errors.**

### P2 | Allow clearing the default ACL group
When the Users settings form leaves user.default_acl_group blank, FormSubmissionHandler stores optional empty strings as null, but this branch treats every non-string as an unavailable group. Because the registry default for this setting is intentionally empty and registration can run without a default ACL group, this makes it impossible to save/clear the Users settings section with the blank default value; accept null the same as an empty string before checking for an existing group.
**– Fixed by treating null as a valid empty default ACL group value before checking group existence/minimum role.**

### P2 | Warn when deleting the sole view group
Fresh evidence: this warning predicate only checks acl_restrictions, so a published item whose access is protected solely by view_group_identifiers: [deleted_group] with view_min_level === null and no ACL restrictions is omitted from the dedicated warning. In that case removeReferences() clears the view group, and PublishedContentResolver later evaluates AccessRule::from(null, []) as inherited/default public view, so the confirmation screen does not flag content that will become public after the delete.
**– Fixed by flagging published content as access-opening when the deleted group is the sole view group and no minimum view level or ACL restrictions remain.**

### P2 | Removed cache-warmer side effects from cold bootstrap
Deleted the runtime translation catalogue and package discovery cache-warmers, keeping runtime catalogue generation in setup/test bootstrap and package-aware rebuild paths instead of cold container warmup. Setup now runs package discovery first and then package-aware asset/translation rebuild work in serial subprocesses after the database is initialized.

### P2 | Re-check ACL permissions in live group apply
When _operation_live queues a group delete/update, the controller validates the requester only before the background process is started; if the group is raised above that actor’s access level (or the actor is downgraded) before the runner reaches this service, this path only checks system constraints and still mutates the group. Persist the requesting actor or expected access boundary with the job and re-run the same validateGroupDelete/validateGroupUpdate policy here before applying the operation.
**– Fixed by storing the confirming admin UID with ACL group live operations and re-running the same current-user validateGroupDelete/validateGroupUpdate policy in the apply service before mutation.**

### P2 | Keep unresolved disputes out of used-token cleanup
If an operator runs studio:account-tokens:cleanup --include-used after a user disputes a password change, the consumed SecurityReview token is expired by the original link TTL and is deleted along with other used tokens. The reviews list and reactivate/delete handlers require that used token to identify the unresolved dispute, so the inactive account can disappear from review with no admin recovery action; exclude used security-review tokens while their user is still inactive.
**– Fixed by preserving used security-review tokens while their linked account is still inactive, even when cleanup includes used tokens.**

### P2 | Block raising the default registration group
When the group configured as user.default_acl_group is edited to require a role above USER, this validator accepts the change even though settings validation only allows default registration groups at user level and defaultRegistrationGroup() later ignores groups whose minRole() > USER. That leaves public registrations silently created without the configured default ACL group; block this update or clear/repair the setting just as deletion of the default group is blocked.
**– Fixed by rejecting default registration group updates that would raise its minimum role above User.**

### P2 | Enforce raised group floors for existing members
When a group’s min_role is raised above some current members’ roles, this update keeps those users in the group, and AccessActor::fromUserAccount()/AccessRule::allows() still grants group-based access solely from the identifier without re-checking min_role. That leaves lower-role users retaining access through a group they could no longer be assigned; block the raise until affected memberships/tokens are repaired, or remove memberships that fall below the new floor.
**– Fixed by removing the group from existing users and pending account links whose role falls below the new floor during confirmed ACL group updates, plus filtering below-floor memberships out of effective access actors as a stale-state fallback.**

### P2 | Revoke recovery tokens when changing email
When a user has a pending password-reset/security-review link sent to their old address and then changes the profile email here, the token remains pending and bound to the same account, so anyone with the old mailbox link can still reset or dispute the account after it moved to a new address. Revoke pending recovery tokens when the normalized email actually changes before flushing the profile update.
**– Fixed by revoking pending password-reset and security-review tokens before persisting a normalized profile email change.**

### P2 | Reject stale token groups before activation
When an already-delivered invitation or registration link is submitted after one of its ACL groups has been deleted, groups() silently drops the missing identifier and this path still activates the account and consumes the token. That gives the user a different ACL assignment than the admin approved, often with the intended contextual group missing; compare the resolved groups with the token identifiers and reject or repair stale links before clearing/replacing memberships.
**– Closed as Won't Fix for blocking behavior: account activation intentionally ignores missing groups for user experience, but now logs a warning Message with the stale group identifiers without exposing the issue in the UI.**

### P2 | ACL model review required: OWNER role, role/group separation and granular access rules
Several existing authorization and account-management findings assumed the previous ACL hierarchy where ADMIN represented the highest privilege level and groups carried access-level semantics.
**– Fixed by introducing the dedicated OWNER role at access level 9, moving ADMIN to access level 8, separating one global account role from optional contextual ACL groups, adding per-group minimum-role assignment checks, preserving owner/last-owner invariants, wiring Symfony role hierarchy/access-control rules, updating invitation/registration/recovery/token flows to carry and enforce roles, and covering the affected user-management, account-link, content/menu ACL and UI paths with focused regression tests.**

### P3 | Account-flow edge polish
Existing account invitations, deleted-account reactivation, profile email changes and token GET entry points needed one final consistency pass after the ACL split.
**– Fixed by keeping public registration enumeration-safe, allowing token-only onboarding to show helpful duplicate username/email feedback, making wrong token types return 404 before rendering workflow pages, upgrading existing accounts additively without role downgrades, preserving surviving role/groups for deleted-account reactivation, and validating profile email changes with structured UI errors.**

### P4 | Large controller modularization pass
The user-management refactor left repeated group-membership and deleted-account reactivation helper logic in multiple controllers.
**– Fixed by extracting shared `UserGroupMembershipManager` and `AccountReactivationAccessResolver` services, reducing duplicated ACL helper code across admin user, admin invitation and registration controllers while leaving larger package/backend classes intact for a dedicated lower-risk refactor.**

### P4 | Runtime translation catalogue tracking cleanup
Generated runtime translation catalogues were ignored by `.gitignore` but still tracked in Git from earlier commits, so manual edits could be committed despite the ignore rule.
**– Fixed by removing the generated `translations/runtime/messages.*.yaml` files from Git tracking while keeping source catalogues under `translations/languages/{locale}` as the committed translation source of truth.**

### P2 | Messenger dispatch
The application currently dispatches some work through Symfony Messenger on kernel terminate so normal requests are not delayed. During a fresh initialization, however, the database may not exist yet. If a Doctrine-backed Messenger transport is touched in this state, Doctrine/ORM can throw during terminate after the main request already completed. Even if this exception is swallowed or effectively invisible to the user, it can still initialize Doctrine, transport, logging and debug exception handling paths and contribute to unexpected peak memory consumption during cold bootstrap.
Before queueing or dispatching Messenger work, explicitly check whether the database/schema is ready or use a non-Doctrine/bootstrap-safe fallback path. Fresh setup/cache warmup/init paths should not trigger Doctrine transport failures as expected control flow. Expected missing-database states should be handled by a cheap readiness check, not by catching heavy ORM exceptions.
Hint: Consider dispatching potentially load-heavy tasks through the already implemented operation/action queue CLI runner.
**– Fixed with cheap Messenger storage readiness checks in package discovery and package asset rebuild dispatchers.**

### P2 | src/Controller/AdminUserReviewController.php
When two admins have the reviews page open, if one admin already resolves the password-dispute review, the other stale form can still post to this route by user UID. Because this action never verifies that the user is still inactive with a used SecurityReview token before mutating state, it can reset an already-active user's password to a random value or even re-activate an account that was just deleted; gate this path with the same unresolved-review check used by the delete action before changing the password/status.
**– Fixed by gating reactivation with the unresolved security-review state.**

### P2 | src/Controller/UserPasswordRecoveryController.php
A stale password-change security-review token can still be consumed after the linked account has already been deleted, deactivated, or otherwise changed through another path. Also cover the GET-confirmation-to-POST race: if the user opens the confirmation page while the token is valid, but the account is deleted, deactivated or otherwise changed before the POST, the POST handler must revalidate that the linked user is still active/usable and that the pending SecurityReview token still represents a meaningful dispute.
**– Fixed by validating usable linked-user state, token status and token expiry before security-review GET/POST completion.**

### P2 | src/Controller/AdminUserInvitationController.php
Invitation and registration token approve/reissue paths validate group assignment but may not validate the target user when the token is bound to an existing or deleted account. A lower-privileged admin could potentially approve or reissue a setup/reactivation link for a high-access deleted account as long as the replacement groups are assignable. If token->user() exists, run the same target-user authorization used by password-reset creation and recovery-token reissue before delivering a fresh link. Revocation should remain possible for stale/broken tokens without requiring the old groups to still be deliverable.
**– Fixed by validating bound target users and current target-user state before token delivery while keeping revocation independent from stale group deliverability.**

### P2 | src/Controller/UserPasswordRecoveryController.php
The public password-reset flow can create reset tokens for any matched account and complete pending reset tokens without checking whether the linked user is still usable. Inactive or deleted accounts should not receive or consume password-reset links. Keep the public request response enumeration-safe, but skip token creation for non-usable accounts and reject/reset-token completion when the linked account is no longer usable.
**– Fixed by skipping token creation for non-usable accounts and rejecting stale/non-usable reset-token completion.**

### P2 | src/Controller/AdminUserController.php
The admin password-reset action can issue a recovery link for a target user without an explicit usable-status check. If inactive or deleted users should not retain adjacent credentials or recovery links, the admin-created reset path should follow the same rule and refuse reset-link creation unless the target account is usable and authorized for the acting admin.
**– Fixed by requiring usable target-user status before issuing admin-created password reset links.**

### P2 | src/Controller/UserRegistrationController.php
Revalidate bound deleted-account invitation state on POST
If a user opens an invitation/reactivation form for a deleted account and an admin changes/deletes/reactivates that account before form submit, the POST path should re-check that token->user() is still in the expected Deleted state before changing username/email/password/groups and activating it.
**– Fixed by revalidating pending/non-expired token state before POST mutation and retaining the deleted-user guard for bound account reuse.**

### P2 | src/Controller/AdminUserInvitationController.php
Revalidate token status/type immediately before reissue/approve/revoke mutations
Admin token tables are stale-prone. If another admin already approved, revoked or reissued a token, stale forms should not deliver a fresh link or mutate token state based only on an earlier page view. POST handlers should re-fetch and verify current token status/type before each mutation.
**– Fixed by rechecking token status/type for approve/reissue/revoke paths before mutation or delivery.**

### P2 | src/Controller/UserApiKeyController.php
Revalidate API key status immediately before reveal/revoke
A bookmarked or stale reveal/revoke form should not expose or act on credentials after another request has already revoked or changed the key. Reveal should require current active/readable status, and revoke should be idempotent or refuse already revoked keys cleanly.
**– Fixed by requiring active readable status for reveal and treating already-revoked credentials idempotently during revoke.**

### P3 | src/Controller/UserRegistrationController.php
The invitation acceptance route currently rejects password-reset tokens, but security-review tokens can still match the route-level token lookup and reach the invitation flow. Restrict the route to AccountTokenType::Invitation and AccountTokenType::Registration only, returning not found for all other token types.
**– Fixed by restricting the invitation route to Invitation and Registration tokens.**

### P3 | Account token completion flows
Reject expired tokens at mutation time, not only page-render time
Any GET confirmation page followed by POST should re-run expiration checks on POST. Otherwise a token that was valid during GET but expired before POST could still be consumed if the handler carries stale assumptions.
**– Fixed for invitation acceptance, password reset completion and security-review completion POST paths.**

### P3 | src/Security/AppSecretRotationGuard.php
The current retry fix stores the new APP_SECRET fingerprint when at least one owner reset link was issued. If multiple owners exist and URL generation fails for some but not all, the guard may treat the rotation as handled while some owners never receive recovery links. Prefer marking the fingerprint only when there are no active owners or when reset links were successfully generated for every intended owner, or store explicit retry state for partial delivery failures.
**– Fixed by storing the fingerprint only when no active owners exist or every intended owner reset link was delivered.**

### P4 | Request: Global project lint runner
Introduce a dedicated bin/lint command that executes all project-wide linting and validation routines through a single entry point.
Currently, various validation mechanisms already exist throughout the project (PHP syntax checks, Symfony container validation, Twig/YAML validation, asset linting, etc.), but there is no unified way to execute them consistently from local development environments and CI pipelines.
The goal is to provide a single command that performs all non-functional code and configuration validation tasks and returns a non-zero exit code if any validation fails.
Potential checks include:
- PHP syntax validation (recursive)
- Symfony container linting
- Twig template linting
- YAML configuration linting
- JavaScript linting
- CSS linting
- Additional project-specific validators as needed

The command should exclude paths such as `tests/Fixtures/`, `vendor/` and `var/` to avoid intentionally invalid test fixtures and generated files.
This is primarily intended to simplify CI integration and ensure that local validation and pull-request validation use the exact same execution path: `bin/lint`.
**– Fixed with `bin/lint` and CI integration; CSS validation is covered through the Tailwind build rather than a strict authored-CSS parser.**

### P2 | tests/Controller/AdminUserControllerTest.php
The AppSecret rotation controller tests intentionally simulate a rotated APP_SECRET by overwriting AppSecretRotationGuard::FINGERPRINTS_KEY and triggering the rotation flow. The test revokes active API keys and creates password-reset tokens as part of its assertions, but did not fully restore the modified global test state afterwards. As a result, later tests could observe revoked seed API keys or modified rotation fingerprints depending on execution order and prior environment state. This caused non-deterministic failures in unrelated API key and database seed tests after a clean initialization. Ensure that all modified configuration values, generated password-reset tokens and API key state are restored in a finally block so the test remains hermetic and does not leak state into subsequent test cases.
**– Reviewed and fixed with commit 2e27da0 plus an additional EntityManager clear after direct API-key state restoration.**
