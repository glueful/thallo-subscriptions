<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Subscriptions\Lifecycle\TenantIntegration;
use Glueful\Extensions\Subscriptions\Subject;
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

        try {
            $subscriptions = $this->gateway->subscriptions();
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

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

        try {
            $subscriptions = $this->gateway->subscriptions();
            $overrides = $this->gateway->overrides();
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $subscription = $subscriptions->current($uuid);
        $displayName = null;
        if ($subscription !== null) {
            $plan = $plans->find((string) ($subscription['plan_key'] ?? ''));
            $displayName = $plan['display_name'] ?? null;
        }

        // Cross-workspace administrative read: this admin actor may not be scoped to $uuid at
        // all right now, so the read must run explicitly AS that target workspace (spec).
        $overrideRows = TenantIntegration::runAsTenantOr(
            $this->context,
            $uuid,
            fn (): array => $overrides->listForSubject($this->context, Subject::tenant($uuid)),
        );

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

        try {
            $subscriptions = $this->gateway->subscriptions();
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

        try {
            $subscriptions = $this->gateway->subscriptions();
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

        try {
            $overrides = $this->gateway->overrides();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $payload = $this->jsonBody($request);
        if (!array_key_exists('value', $payload)) {
            return Response::error('value is required', 422);
        }
        $value = $payload['value'];
        $expiresAt = is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null;
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

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

        try {
            $overrides = $this->gateway->overrides();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        TenantIntegration::runAsTenantOr(
            $this->context,
            $uuid,
            function () use ($overrides, $uuid, $entitlement): void {
                $overrides->deleteForSubject($this->context, Subject::tenant($uuid), $entitlement);
            },
        );

        return Response::success([], 'Override removed');
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
     * @return array{0:list<array<string,mixed>>,1:?Response}
     */
    private function resolveDirectory(): array
    {
        if ($this->flags->tenancyEnabled()) {
            return [$this->tenants->listTenants($this->context), null];
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
