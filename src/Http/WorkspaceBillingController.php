<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Engine\EngineUnavailableException;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 9 (Phase B): the per-workspace billing admin API -- `/v1/admin/subscriptions/workspaces*`.
 * Joins the tenancy directory ({@see TenantAdministration}) with the subscriptions engine
 * ({@see EngineGateway}), degrading the same way Task 8's `PlansController` does whenever the
 * engine isn't `ready` ({@see RespondsEngineUnavailable}), plus this pack's own two extra
 * degradations:
 *
 *  - **Provider-managed guard:** `SubscriptionService::cancelFor()`/`changePlanFor()` only ever
 *    change LOCAL state (spec: "cancel/change honesty") -- a subscription carrying a non-empty
 *    `provider_subscription_id` is refused with structured 409 `provider_managed_subscription`
 *    rather than silently drifting from what the payment provider actually has.
 *  - **Workspace-not-active guard (final-wave fix C):** the directory is filtered to the same
 *    visibility `getTenant()` gives every `{uuid}`-scoped action (soft-deleted workspaces are
 *    neither listed nor resolvable), and every billing WRITE against a live-but-not-`active`
 *    workspace (`provisioning`/`suspended`) is refused with structured 409 `workspace_not_active`.
 *    Reads stay open for those states, with `status` in the row so the SPA can show it.
 *  - **Single-store degradation:** with tenancy off, every workspace-scoped read/write is
 *    resolved against {@see SingleStoreTenant::defaultUuidOrNull()} (never `resolve()` -- a
 *    missing pointer must not 500 here either); a missing default answers structured 409
 *    `default_workspace_missing`, and the ONE valid `{uuid}` in that mode is the default itself
 *    (anything else 404s exactly like an unknown tenant would under tenancy ON).
 *
 * The workspace index takes NO caller-supplied UUID filter (design ruling, non-negotiable): the
 * UUID set for the trusted `SubscriptionService::currentForTenants()` batch read is derived
 * SOLELY from `TenantAdministration::listTenants()` after this route's platform-authority gate,
 * paginated in memory (clamped 1-100 BEFORE any engine read) -- proving the upstream seam's own
 * "administrative projection, not a public batch-by-UUID endpoint" precondition. Exactly one
 * `currentForTenants()` call and one `plans()->list()` call happen per page, never one per row.
 */
final class WorkspaceBillingController
{
    use RespondsEngineUnavailable;

    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;
    /** `subscription_overrides.entitlement` / `.reason` column bounds (engine migration 002). */
    private const MAX_ENTITLEMENT_LENGTH = 128;
    private const MAX_REASON_LENGTH = 255;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EngineGateway $gateway,
        private readonly TenantAdministration $tenants,
        private readonly SingleStoreTenant $singleStore,
        private readonly SystemFlags $flags,
    ) {
    }

    #[ApiOperation(summary: 'List workspace billing status (paginated)', tags: ['Thallo Subscriptions'])]
    public function index(Request $request): Response
    {
        $pagination = $this->parsePagination($request);
        if ($pagination['error'] !== null) {
            return $pagination['error'];
        }

        [$directory, $directoryError] = $this->resolveDirectory();
        if ($directoryError !== null) {
            return $directoryError;
        }

        // ONE readiness probe for this whole action (final-wave fix B) -- never one per accessor.
        try {
            $engine = $this->gateway->requireServices();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }
        $subscriptions = $engine->subscriptions();
        $plans = $engine->plans();

        $page = $pagination['page'];
        $perPage = $pagination['per_page'];
        $total = count($directory);
        $slice = array_slice($directory, ($page - 1) * $perPage, $perPage);

        $uuids = array_values(array_map(static fn (array $tenant): string => (string) $tenant['uuid'], $slice));

        // Exactly ONE bulk read and ONE plan-catalog read for this whole page -- never per-row.
        $subscriptionsByTenant = $subscriptions->currentForTenants($uuids);
        $planDisplayNames = $this->planDisplayNameMap($plans->list());

        $rows = [];
        foreach ($slice as $tenant) {
            $uuid = (string) $tenant['uuid'];
            $subscription = $subscriptionsByTenant[$uuid] ?? null;
            $rows[] = [
                'tenant' => $tenant,
                'subscription' => $subscription === null
                    ? null
                    : $this->projectSubscription(
                        $subscription,
                        $planDisplayNames[(string) ($subscription['plan_key'] ?? '')] ?? null,
                    ),
            ];
        }

        // Standard pagination envelope (current_page/per_page/total/total_pages/has_next_page/
        // has_previous_page) -- matches the repo's established Response::paginated() convention
        // (packages/thallo-collections' CollectionDataController, app/Content's DeliveryController),
        // never a hand-rolled meta shape.
        return Response::paginated($rows, $total, $page, $perPage, null, 'Workspaces retrieved');
    }

    #[ApiOperation(summary: 'Workspace billing detail', tags: ['Thallo Subscriptions'])]
    public function show(Request $request, string $uuid): Response
    {
        $resolved = $this->resolveWorkspace($uuid);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        // ONE readiness probe for this whole action (final-wave fix B) -- this action needs THREE
        // engine services, which used to mean three full 32-query schema probes per request.
        try {
            $engine = $this->gateway->requireServices();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }
        $subscriptions = $engine->subscriptions();
        $overrides = $engine->overrides();
        $plans = $engine->plans();

        $subscription = $this->readSubscription($subscriptions, $resolved, $uuid);
        $displayName = null;
        if ($subscription !== null) {
            $plan = $plans->find((string) ($subscription['plan_key'] ?? ''));
            $displayName = $plan['display_name'] ?? null;
        }

        $overrideRows = $this->readOverrides($overrides, $resolved, $uuid);

        return Response::success([
            'tenant' => $resolved,
            'subscription' => $subscription === null ? null : $this->projectSubscription($subscription, $displayName),
            'overrides' => $overrideRows,
        ], 'Workspace retrieved');
    }

    #[ApiOperation(summary: 'Start or change a workspace subscription plan', tags: ['Thallo Subscriptions'])]
    public function setPlan(Request $request, string $uuid): Response
    {
        $resolved = $this->resolveWorkspace($uuid);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $inactive = $this->workspaceNotActiveGuard($resolved);
        if ($inactive !== null) {
            return $inactive;
        }

        try {
            $subscriptions = $this->gateway->requireServices()->subscriptions();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $existing = $subscriptions->current($uuid);
        $providerManaged = $this->providerManagedGuard($existing);
        if ($providerManaged !== null) {
            return $providerManaged;
        }

        $payload = $this->jsonBody($request);
        $planKey = is_string($payload['plan_key'] ?? null) ? $payload['plan_key'] : '';

        try {
            $result = $existing === null
                ? $subscriptions->start($uuid, $planKey)
                : $subscriptions->changePlan($uuid, $planKey);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($result, $existing === null ? 'Subscription started' : 'Plan updated');
    }

    #[ApiOperation(summary: 'Cancel a workspace subscription', tags: ['Thallo Subscriptions'])]
    public function cancel(Request $request, string $uuid): Response
    {
        $resolved = $this->resolveWorkspace($uuid);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $inactive = $this->workspaceNotActiveGuard($resolved);
        if ($inactive !== null) {
            return $inactive;
        }

        try {
            $subscriptions = $this->gateway->requireServices()->subscriptions();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $existing = $subscriptions->current($uuid);
        if ($existing === null) {
            return Response::error('workspace has no subscription to cancel', 422);
        }
        $providerManaged = $this->providerManagedGuard($existing);
        if ($providerManaged !== null) {
            return $providerManaged;
        }

        $payload = $this->jsonBody($request);
        $atPeriodEnd = !array_key_exists('at_period_end', $payload) || (bool) $payload['at_period_end'];

        $result = $subscriptions->cancel($uuid, $atPeriodEnd);

        return Response::success($result, 'Subscription canceled');
    }

    #[ApiOperation(summary: 'Set a workspace entitlement override', tags: ['Thallo Subscriptions'])]
    public function upsertOverride(Request $request, string $uuid, string $entitlement): Response
    {
        $resolved = $this->resolveWorkspace($uuid);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $inactive = $this->workspaceNotActiveGuard($resolved);
        if ($inactive !== null) {
            return $inactive;
        }

        try {
            $overrides = $this->gateway->requireServices()->overrides();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        // Final-wave fix D: this action used to forward the raw payload straight into the engine's
        // writer, where an unparseable `expires_at` or an over-length `entitlement`/`reason` became a
        // driver-level 500 on the column, and a `value` of any shape at all (arrays, objects) reached
        // every downstream entitlement consumer. Validate the FOUR fields here -- the engine's writer
        // has no validator of its own for them.
        $payload = $this->jsonBody($request);
        $invalid = $this->validateOverrideInput($payload, $entitlement);
        if ($invalid !== null) {
            return $invalid;
        }

        $value = $payload['value'];
        $expiresAt = is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null;
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

        try {
            TenantIntegration::runAsTenantOr(
                $this->context,
                $uuid,
                function () use ($overrides, $uuid, $entitlement, $value, $expiresAt, $reason): void {
                    $overrides->upsertForSubject(
                        $this->context,
                        Subject::tenant($uuid),
                        $entitlement,
                        $value,
                        $expiresAt,
                        $reason,
                    );
                },
            );
        } catch (\InvalidArgumentException $e) {
            // Same upstream-message-verbatim 422 mapping every sibling action already carries.
            return Response::error($e->getMessage(), 422);
        }

        return Response::success([
            'entitlement' => $entitlement,
            'value' => $value,
            'expires_at' => $expiresAt,
            'reason' => $reason,
        ], 'Override saved');
    }

    #[ApiOperation(summary: 'Remove a workspace entitlement override', tags: ['Thallo Subscriptions'])]
    public function deleteOverride(Request $request, string $uuid, string $entitlement): Response
    {
        $resolved = $this->resolveWorkspace($uuid);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        $inactive = $this->workspaceNotActiveGuard($resolved);
        if ($inactive !== null) {
            return $inactive;
        }

        try {
            $overrides = $this->gateway->requireServices()->overrides();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        try {
            TenantIntegration::runAsTenantOr(
                $this->context,
                $uuid,
                function () use ($overrides, $uuid, $entitlement): void {
                    $overrides->deleteForSubject($this->context, Subject::tenant($uuid), $entitlement);
                },
            );
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success([], 'Override removed');
    }

    /**
     * The detail action's subscription read (final-wave fix C).
     *
     * An `active` workspace keeps the ordinary per-subject `current()` read, unchanged -- it
     * validates the subject through `SubjectResolverInterface` and enters the workspace's own
     * context, which is the right thing for the normal case.
     *
     * A live-but-not-`active` workspace CANNOT be entered at all: `current()` delegates to
     * `currentFor()`, which runs inside `TenantIntegration::runAsTenantOr()`, and
     * `Tenancy::runAsTenant()` refuses any tenant failing `isActive()` with
     * `TenantNotFoundException` -- so the detail read of a `provisioning`/`suspended` workspace was
     * an unconditional 500 while the directory listed it happily. Those read through the engine's
     * OWN trusted administrative bulk seam instead -- the same `currentForTenants()` the index uses,
     * whose documented precondition (a normalized UUID list taken from the host's authoritative
     * tenant directory AFTER platform authorization) is exactly what `resolveWorkspace()` just
     * produced for this one uuid, behind this route's platform-authority gate.
     *
     * @param array<string,mixed> $tenant
     * @return array<string,mixed>|null
     */
    private function readSubscription(SubscriptionService $subscriptions, array $tenant, string $uuid): ?array
    {
        if ((string) ($tenant['status'] ?? '') !== 'active') {
            return $subscriptions->currentForTenants([$uuid])[$uuid] ?? null;
        }

        return $subscriptions->current($uuid);
    }

    /**
     * The detail action's cross-workspace override read. This admin actor may not be scoped to
     * `$uuid` at all right now, so the read runs explicitly AS that target workspace (spec).
     *
     * EXCEPT for a live-but-not-`active` workspace (final-wave fix C): `Tenancy::runAsTenant()`
     * resolves its target through `Tenant::isActive()` and throws `TenantNotFoundException` for
     * anything else, which turned the detail read of a `provisioning`/`suspended` workspace into a
     * 500 -- while the directory happily listed it. Those read through the SAME system-scoped lane
     * the index's own cross-workspace bulk read already uses upstream; the subject filter
     * ({@see Subject::tenant()}) stays explicit either way, so this widens the DB-enforcement scope
     * for the read and never the row set. Writes never reach here -- they are refused first by
     * {@see self::workspaceNotActiveGuard()}.
     *
     * @param array<string,mixed> $tenant
     * @return list<array<string,mixed>>
     */
    private function readOverrides(OverrideRepository $overrides, array $tenant, string $uuid): array
    {
        $read = fn (): array => $overrides->listForSubject($this->context, Subject::tenant($uuid));

        if ((string) ($tenant['status'] ?? '') !== 'active') {
            /** @var list<array<string,mixed>> */
            return TenantIntegration::runAsSystemOr($this->context, $read);
        }

        /** @var list<array<string,mixed>> */
        return TenantIntegration::runAsTenantOr($this->context, $uuid, $read);
    }

    // ------------------------------------------------------------------
    // Workspace resolution -- single-store vs tenancy-on, shared by every
    // {uuid}-scoped action AND the index's directory source.
    // ------------------------------------------------------------------

    /**
     * Resolves the ONE `{uuid}` a workspace-scoped route was called with. Tenancy ON: the real
     * tenant directory via `TenantAdministration::getTenant()` (404 when unknown). Tenancy OFF:
     * the single-store default is the only valid uuid -- a missing default pointer is 409
     * `default_workspace_missing` (there is no workspace concept established yet to compare
     * against), while any OTHER uuid (including a well-formed one) 404s exactly like an unknown
     * tenant would.
     *
     * @return array<string,mixed>|Response
     */
    private function resolveWorkspace(string $uuid): array|Response
    {
        if ($this->flags->tenancyEnabled()) {
            $tenant = $this->tenants->getTenant($this->context, $uuid);

            return $tenant ?? $this->workspaceNotFound();
        }

        $defaultUuid = $this->singleStore->defaultUuidOrNull();
        if ($defaultUuid === null) {
            return $this->defaultWorkspaceMissing();
        }
        if ($uuid !== $defaultUuid) {
            return $this->workspaceNotFound();
        }

        $tenant = $this->tenants->getTenant($this->context, $uuid);

        return $tenant ?? $this->workspaceNotFound();
    }

    /**
     * The index's directory source: the FULL authoritative tenant directory when tenancy is on
     * (paginated in memory by the caller), or exactly the one single-store default row
     * (spec: "resolve the one real default-workspace row or return 409").
     *
     * VISIBILITY (final-wave fix C, closes deferred #6): `listTenants()` is a raw
     * `SELECT ... FROM tenants` with NO soft-delete filter, while every `{uuid}`-scoped action
     * resolves through `getTenant()` -- which goes through the ORM's soft-delete scope. Left
     * unfiltered the two disagreed: a soft-deleted workspace was listed in the directory and then
     * 404'd the moment an operator clicked it. The directory is filtered to exactly what
     * `getTenant()` would resolve (i.e. `deleted_at IS NULL`); non-active-but-live states
     * (`provisioning`, `suspended`) stay VISIBLE -- with their `status` in the row, which the SPA
     * renders -- because hiding a suspended workspace from its billing directory would be worse
     * than showing it. Billing WRITES against those states are refused separately, by
     * {@see self::workspaceNotActiveGuard()}.
     *
     * @return array{0:list<array<string,mixed>>,1:?Response}
     */
    private function resolveDirectory(): array
    {
        if ($this->flags->tenancyEnabled()) {
            $live = array_values(array_filter(
                $this->tenants->listTenants($this->context),
                static fn (array $tenant): bool => ($tenant['deleted_at'] ?? null) === null,
            ));

            return [$live, null];
        }

        $defaultUuid = $this->singleStore->defaultUuidOrNull();
        if ($defaultUuid === null) {
            return [[], $this->defaultWorkspaceMissing()];
        }

        $tenant = $this->tenants->getTenant($this->context, $defaultUuid);
        if ($tenant === null) {
            return [[], $this->defaultWorkspaceMissing()];
        }

        return [[$tenant], null];
    }

    private function workspaceNotFound(): Response
    {
        return Response::error('workspace not found', 404);
    }

    private function defaultWorkspaceMissing(): Response
    {
        return Response::error(
            'no default workspace is established yet',
            409,
            ['code' => 'default_workspace_missing'],
        );
    }

    /**
     * Final-wave fix C (closes deferred #6): billing WRITES are refused on any workspace that isn't
     * `active`. Reads (index/detail) stay open for every live workspace so an operator can still see
     * what a `provisioning`/`suspended` workspace is on -- but starting, changing, cancelling or
     * overriding a workspace that is mid-provisioning or suspended is never a legitimate operation,
     * and used to be fully permitted. Structured 409, matching this controller's established idiom
     * ({@see self::providerManagedGuard()}, `default_workspace_missing`).
     *
     * @param array<string,mixed> $tenant the row {@see self::resolveWorkspace()} already resolved
     */
    private function workspaceNotActiveGuard(array $tenant): ?Response
    {
        $status = (string) ($tenant['status'] ?? '');
        if ($status === 'active') {
            return null;
        }

        return Response::error(
            "this workspace is {$status} and its billing cannot be changed",
            409,
            ['code' => 'workspace_not_active', 'status' => $status],
        );
    }

    /**
     * Final-wave fix D (closes deferred #7): the four override inputs the engine's own writer never
     * validates. Everything here is a 422 -- never a silent coercion (an unparseable `expires_at`
     * must NOT quietly become "never expires") and never a 500 from the column.
     *
     * @param array<string,mixed> $payload
     */
    private function validateOverrideInput(array $payload, string $entitlement): ?Response
    {
        // `subscription_overrides.entitlement` is a bounded column; an over-length key is a driver
        // error, not a server fault.
        if (mb_strlen($entitlement) > self::MAX_ENTITLEMENT_LENGTH) {
            return Response::error(
                'entitlement must be at most ' . self::MAX_ENTITLEMENT_LENGTH . ' characters',
                422,
            );
        }

        if (!array_key_exists('value', $payload)) {
            return Response::error('value is required', 422);
        }
        // The engine's entitlement consumers compare/serialize bool|int|null ONLY -- an array or
        // object reaching them is a downstream type error far from here.
        $value = $payload['value'];
        if (!is_bool($value) && !is_int($value) && $value !== null) {
            return Response::error('value must be a boolean, an integer, or null', 422);
        }

        if (array_key_exists('expires_at', $payload) && $payload['expires_at'] !== null) {
            $expiresAt = $payload['expires_at'];
            if (!is_string($expiresAt) || trim($expiresAt) === '' || !$this->isParseableDateTime($expiresAt)) {
                return Response::error('expires_at must be null or a valid date/time string', 422);
            }
        }

        if (array_key_exists('reason', $payload) && $payload['reason'] !== null) {
            $reason = $payload['reason'];
            if (!is_string($reason)) {
                return Response::error('reason must be null or a string', 422);
            }
            if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
                return Response::error(
                    'reason must be at most ' . self::MAX_REASON_LENGTH . ' characters',
                    422,
                );
            }
        }

        return null;
    }

    private function isParseableDateTime(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed>|null $existing */
    private function providerManagedGuard(?array $existing): ?Response
    {
        if ($existing === null) {
            return null;
        }
        if ((string) ($existing['provider_subscription_id'] ?? '') === '') {
            return null;
        }

        return Response::error(
            'this subscription is managed by a payment provider and cannot be changed locally',
            409,
            ['code' => 'provider_managed_subscription'],
        );
    }

    // ------------------------------------------------------------------
    // Projection helpers
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $subscription @return array<string,mixed> */
    private function projectSubscription(array $subscription, ?string $displayName): array
    {
        return [
            'status' => $subscription['status'] ?? null,
            'plan_key' => $subscription['plan_key'] ?? null,
            'plan_display_name' => $displayName,
            'trial_ends_at' => $subscription['trial_ends_at'] ?? null,
            'grace_ends_at' => $subscription['grace_ends_at'] ?? null,
            'provider_managed' => (string) ($subscription['provider_subscription_id'] ?? '') !== '',
        ];
    }

    /** @param list<array<string,mixed>> $plans @return array<string,string> */
    private function planDisplayNameMap(array $plans): array
    {
        $map = [];
        foreach ($plans as $plan) {
            $key = (string) ($plan['plan_key'] ?? '');
            if ($key !== '') {
                $map[$key] = (string) ($plan['display_name'] ?? '');
            }
        }

        return $map;
    }

    // ------------------------------------------------------------------
    // Pagination -- validated and clamped BEFORE any engine read.
    // ------------------------------------------------------------------

    /** @return array{page:int,per_page:int,error:?Response} */
    private function parsePagination(Request $request): array
    {
        $pageParam = $request->query->get('page');
        $perPageParam = $request->query->get('per_page');

        if ($pageParam !== null && (!is_numeric($pageParam) || (int) $pageParam < 1)) {
            return ['page' => 1, 'per_page' => self::DEFAULT_PER_PAGE, 'error' => Response::error(
                'page must be a positive integer',
                422,
            )];
        }
        if ($perPageParam !== null && (!is_numeric($perPageParam) || (int) $perPageParam < 1)) {
            return ['page' => 1, 'per_page' => self::DEFAULT_PER_PAGE, 'error' => Response::error(
                'per_page must be a positive integer',
                422,
            )];
        }

        $page = $pageParam !== null ? (int) $pageParam : 1;
        // Clamp per_page to [1, MAX_PER_PAGE] BEFORE any engine read -- never a caller-controlled
        // batch size past the upstream bulk-read seam's own MAX_TENANT_BATCH.
        $perPage = $perPageParam !== null ? min(self::MAX_PER_PAGE, (int) $perPageParam) : self::DEFAULT_PER_PAGE;

        return ['page' => $page, 'per_page' => $perPage, 'error' => null];
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $content = (string) $request->getContent();
        if ($content === '') {
            return [];
        }

        return (array) json_decode($content, true);
    }
}
