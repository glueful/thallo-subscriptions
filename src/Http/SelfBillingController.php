<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Checkout\CheckoutUnavailableException;
use Glueful\Extensions\Payvia\Checkout\IdempotencyConflictException;
use Glueful\Extensions\Payvia\Checkout\OriginationLiveException;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutResult;
use Glueful\Extensions\Subscriptions\CheckoutReservationException;
use Glueful\Extensions\Subscriptions\Plans\PlanPurchasability;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Settings\AdminUrlProvider;
use Thallo\Subscriptions\Checkout\PayviaCheckoutGateway;
use Thallo\Subscriptions\Checkout\PayviaUnavailableException;
use Thallo\Subscriptions\Checkout\WorkspaceCheckoutCoordinator;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Engine\EngineServices;
use Thallo\Subscriptions\Engine\EngineUnavailableException;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2): `GET /v1/admin/billing/meta`
 * + `POST /v1/admin/billing/checkout` -- the workspace-scoped self-serve billing API. Mounted at
 * `/v1/admin/billing` behind `['auth', 'tenant_profile:admin', 'tenant_bootstrap',
 * 'admin_tenant_binding']` + per-route `content_permission:billing.manage`
 * ({@see \Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider}'s routes file) -- the
 * workspace uuid ALWAYS comes from {@see SingleStoreTenant::resolve()} (which itself defers to
 * the shared `CurrentTenantResolver` `admin_tenant_binding` bound for this request, or the
 * single-store default), never from request input (spec §1 boundary).
 *
 * `billing.manage` is a DISJOINT, per-workspace-delegable authority from the platform's
 * `tenancy.manage` (spec §1) -- this controller never touches `CapabilityCatalog`/role-matrix
 * logic itself; that is entirely the route middleware's job. It does NOT return
 * `can_manage_billing` on `/meta`: reaching the route already proved that permission.
 *
 * Fix round (code review, Critical C1): every constructor dependency here is UNCONDITIONALLY
 * available regardless of whether `glueful/payvia`, `glueful/subscriptions`, or `glueful/users`
 * are enabled in this host -- `EngineGateway`/`SelfServeGatewayCapability`/`PayviaCheckoutGateway`
 * all soft-probe their respective extensions PER CALL (never at construction), and
 * `Glueful\Extensions\Users\Repositories\UserRepository` is resolved lazily via
 * {@see self::users()} rather than constructor-injected. This is load-bearing: an earlier version
 * of this class constructor-injected Payvia's ledger repositories and the users pack's repository
 * directly, which made the CONTROLLER ITSELF unconstructible (a hard container exception, before
 * any handler method ever ran) the moment any one of those extensions was disabled -- turning
 * `GET /meta`'s pinned "200 after auth even when [everything] is unavailable" contract into a
 * 500. `WorkspaceCheckoutCoordinator` is likewise never constructor-injected (see its own
 * docblock) -- it is built with `new` inside {@see self::checkout()}, only once both `EngineGateway`
 * and {@see PayviaCheckoutGateway} have already been confirmed available for this one request.
 *
 * **`POST /checkout` replay/terminal status -> HTTP mapping** (spec §5.2, pinned here for the
 * SPA task to consume verbatim):
 *
 * | ledger status                | HTTP | body / `error.details`                        |
 * |-------------------------------|------|-------------------------------------------------|
 * | `pending`                     | 200  | `{status, checkout_url}`                        |
 * | `provider_observed`/`dispatched` | 200 | `{status, checkout_url: null}` (note A)      |
 * | `initializing`                | 202  | `{status:'initializing', checkout_url:null}` (note B) |
 * | `failed`                      | 409  | `{code:'checkout_failed', status:'failed'}`     |
 * | `expired`                     | 409  | `{code:'checkout_expired', status:'expired'}`   |
 * | `abandoned`                   | 409  | `{code:'checkout_abandoned', status:'abandoned'}` |
 *
 * Note A: settlement is projected elsewhere, never re-derived here. Note B: covers BOTH a
 * concurrent lease loser and any unknown provider-I/O outcome -- see
 * {@see self::initializingResponse()}.
 *
 * A terminal `failed`/`expired`/`abandoned` replay is a genuine refusal, not an informational
 * body: the attempt is definitively dead and the caller must mint a NEW idempotency key for
 * another attempt (design spec §3.2's "attempt idempotency" rule), which a bare 200 would not
 * communicate.
 *
 * **`Idempotency-Key` header contract** (spec §5.2, pinned for the SPA task): an opaque token,
 * 16-128 characters, matching `/^[A-Za-z0-9._~-]+$/` (URL-safe token characters -- no whitespace,
 * no other punctuation). Validated against the RAW header value with no trimming first, so a
 * whitespace-padded value is rejected rather than silently normalized.
 */
final class SelfBillingController
{
    use RespondsEngineUnavailable;

    /** Idempotency-Key header bounds (spec §5.2): an opaque token, 16-128 characters. */
    private const IDEMPOTENCY_KEY_MIN = 16;
    private const IDEMPOTENCY_KEY_MAX = 128;
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._~-]+$/';

    /** `plan_key` bounds (mirrors spec §5.4's `pricing_plan.plan_key` editor-input contract). */
    private const PLAN_KEY_PATTERN = '/^[a-z0-9._-]{1,100}$/';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EngineGateway $gateway,
        private readonly SingleStoreTenant $workspace,
        private readonly SelfServeCheckoutSetting $selfServe,
        private readonly SelfServeGatewayCapability $gatewayCapability,
        private readonly PayviaCheckoutGateway $payvia,
        private readonly ?AdminUrlProvider $adminUrl = null,
    ) {
    }

    #[ApiOperation(summary: 'Workspace billing status', tags: ['Thallo Subscriptions'])]
    public function meta(Request $request): Response
    {
        $workspaceUuid = $this->resolveWorkspace();
        if ($workspaceUuid instanceof Response) {
            return $workspaceUuid;
        }
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspaceUuid);

        // ONE engine readiness probe for this whole action (mirrors EngineServices' own
        // single-probe-per-action rule) -- fix round I4: this used to call engineState() AND
        // requireServices() separately, paying the full ~32-query schema-readiness probe twice.
        $engineState = EngineGateway::READY;
        $engine = null;
        try {
            $engine = $this->gateway->requireServices();
        } catch (EngineUnavailableException $e) {
            $engineState = $e->state;
        }

        $subscription = null;
        $purchasablePlans = [];
        if ($engine !== null) {
            $subscription = $this->projectSubscription($engine, $workspaceUuid);
            $capability = $this->gatewayCapability->evaluate();
            if ($capability['capable'] === true && $capability['gateway'] !== null) {
                $purchasablePlans = $this->purchasablePlans($capability['gateway']);
            }
        }

        // Fix round C1/I8: soft-tolerate Payvia being entirely unavailable (provider off, or its
        // ledger tables not migrated) -- no live/blocked origination can be reported, but that is
        // a representable state, never a 500.
        [$origination, $operatorContactReason] = $this->payvia->isAvailable()
            ? $this->guardState($subjectKey)
            : [null, null];

        return Response::success([
            'engine' => $engineState,
            'self_serve_checkout_enabled' => $this->selfServe->isEnabled(),
            'workspace_uuid' => $workspaceUuid,
            'subscription' => $subscription,
            'origination' => $origination,
            'operator_contact_required' => $operatorContactReason !== null,
            'operator_contact_reason' => $operatorContactReason,
            'purchasable_plans' => $purchasablePlans,
        ], 'Billing status retrieved');
    }

    #[ApiOperation(summary: 'Start a workspace subscription checkout', tags: ['Thallo Subscriptions'])]
    public function checkout(Request $request): Response
    {
        // Fix round I8: validated against the RAW header value -- reading it first and trimming
        // before validating would silently accept (and then use) a whitespace-padded key that
        // still met the length bound; the pattern already excludes whitespace entirely, so no
        // trim is needed once this passes.
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');
        if (!$this->isValidIdempotencyKey($idempotencyKey)) {
            return Response::error(
                'Idempotency-Key header must be an opaque token between ' . self::IDEMPOTENCY_KEY_MIN
                    . ' and ' . self::IDEMPOTENCY_KEY_MAX
                    . ' characters, using only letters, digits, and . _ ~ -.',
                422,
                ['code' => 'invalid_idempotency_key'],
            );
        }

        $payload = $this->jsonBody($request);
        $planKey = is_string($payload['plan_key'] ?? null) ? $payload['plan_key'] : '';
        if (preg_match(self::PLAN_KEY_PATTERN, $planKey) !== 1) {
            return Response::error(
                'plan_key must be 1-100 characters of lowercase letters, digits, dot, underscore, or hyphen.',
                422,
                ['code' => 'invalid_plan_key'],
            );
        }

        $actorUuid = $this->actorUuid($request);
        if ($actorUuid === null) {
            return Response::error('Authentication is required.', 401);
        }

        $workspaceUuid = $this->resolveWorkspace();
        if ($workspaceUuid instanceof Response) {
            return $workspaceUuid;
        }

        if (!$this->selfServe->isEnabled()) {
            return Response::error(
                'Self-serve checkout is not enabled on this platform.',
                409,
                ['code' => 'self_serve_disabled'],
            );
        }

        try {
            $engine = $this->gateway->requireServices();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $current = $engine->subscriptions()->current($workspaceUuid);
        if ($current !== null && $this->isEntitling($current)) {
            return Response::error(
                'This workspace already has an active subscription.',
                409,
                ['code' => 'subscription_already_active'],
            );
        }

        // Fix round C1: Payvia's ledger must be confirmed available BEFORE any guard/origination
        // read is attempted (never a 500 from a missing-service container exception, and --
        // fix round 2, I8 residual -- never a raw DB error from an unmigrated ledger table
        // either, since PayviaCheckoutGateway::unavailableReason() also probes the schema).
        // ONE public code (`payvia_unavailable`) either way; the specific reason (extension
        // unbound vs. schema not migrated) rides in the `reason` detail for operator diagnosis.
        $payviaUnavailableReason = $this->payvia->unavailableReason();
        if ($payviaUnavailableReason !== null) {
            return Response::error(
                'The checkout payment system is unavailable on this platform.',
                409,
                ['code' => PayviaUnavailableException::CODE, 'reason' => $payviaUnavailableReason],
            );
        }

        // Refusal order mirrors spec §5.2's own listing exactly: already-active (above), THEN
        // checkout_pending/checkout_blocked (a matching same-key attempt is never preempted by
        // this read -- it falls through to prepare() for replay/resume), THEN plan_not_purchasable.
        $subjectKey = WorkspaceCheckoutCoordinator::subjectKey($workspaceUuid);
        $guardRefusal = $this->guardRefusal($subjectKey, $idempotencyKey);
        if ($guardRefusal !== null) {
            return $guardRefusal;
        }

        $capability = $this->gatewayCapability->evaluate();
        $plan = $capability['capable'] === true && $capability['gateway'] !== null
            ? $this->findPurchasablePlan($capability['gateway'], $planKey)
            : null;
        if ($plan === null) {
            return Response::error(
                'This plan is not purchasable through the configured payment gateway.',
                409,
                ['code' => 'plan_not_purchasable'],
            );
        }
        /** @var string $gateway */
        $gateway = $capability['gateway'];

        // Fix round C1: the users pack must be confirmed available BEFORE loading the verified
        // email -- never a 500 from a missing UserRepository binding.
        $users = $this->users();
        if ($users === null) {
            return Response::error(
                'The account service is unavailable on this platform.',
                409,
                ['code' => 'users_unavailable'],
            );
        }

        $verifiedEmail = $this->verifiedEmail($users, $actorUuid);
        if ($verifiedEmail === null) {
            return Response::error(
                'This account does not have a verified email address on file.',
                409,
                ['code' => 'verified_email_required'],
            );
        }

        $adminOrigin = $this->adminUrl?->adminUrl();
        if ($adminOrigin === null || trim($adminOrigin) === '') {
            return Response::error(
                'The platform admin origin is not configured.',
                409,
                ['code' => 'checkout_unavailable', 'reason' => 'admin_origin_unconfigured'],
            );
        }
        $returnUrlBase = rtrim($adminOrigin, '/') . '/billing/return';

        // Built fresh for this one request -- see WorkspaceCheckoutCoordinator's own docblock for
        // why it is never container-bound/autowired.
        $coordinator = new WorkspaceCheckoutCoordinator(
            $this->payvia->checkoutService(),
            $this->payvia->originations(),
            $engine->subscriptions(),
        );

        try {
            $claim = $coordinator->prepare(
                $this->context,
                $workspaceUuid,
                $plan,
                $actorUuid,
                $verifiedEmail,
                $idempotencyKey,
                $gateway,
                $returnUrlBase,
            );
        } catch (CheckoutReservationException $e) {
            return $this->reservationRefusal($e);
        } catch (OriginationLiveException) {
            // Fix round I8: a race lost between this action's own guard pre-check and prepare()'s
            // claim attempt -- re-read the guard/origination NOW (it just changed) so the stored
            // checkout_url can still be reported, exactly like the pre-check's own response.
            return $this->livePendingRefusal($subjectKey);
        } catch (IdempotencyConflictException) {
            // Fix round I3: host-authored message -- never $e->getMessage() verbatim (it embeds
            // the caller's own idempotency key and Payvia's internal marker prefix).
            return Response::error(
                'This idempotency key was already used for a different checkout request.',
                409,
                ['code' => 'idempotency_conflict'],
            );
        } catch (CheckoutUnavailableException) {
            return Response::error(
                'This plan is not purchasable through the configured payment gateway.',
                409,
                ['code' => 'plan_not_purchasable'],
            );
        }

        try {
            $result = $coordinator->initialize($this->context, $claim->originationUuid);
        } catch (\Throwable $e) {
            $this->logUnknownInitializationOutcome($claim->originationUuid, $e);

            return $this->initializingResponse();
        }

        return $this->respondForResult($result);
    }

    // ------------------------------------------------------------------
    // Workspace + actor resolution
    // ------------------------------------------------------------------

    private function resolveWorkspace(): string|Response
    {
        try {
            return $this->workspace->resolve();
        } catch (\RuntimeException) {
            return Response::error(
                'no default workspace is established yet',
                409,
                ['code' => 'default_workspace_missing'],
            );
        }
    }

    /**
     * The acting user's uuid from the request principal. Never a display label/email -- this
     * pack never accepts a request/JWT-carried email as the checkout receipt address (spec
     * §5.2); the verified email comes ONLY from {@see self::verifiedEmail()}'s authoritative
     * account-row read. Duplicated locally (rather than depending on the engine app's own
     * equivalent principal resolver) because first-party packs may not reference the engine
     * app's namespace at all (`composer boundaries`).
     */
    private function actorUuid(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        if ($user instanceof UserIdentity) {
            $uuid = trim($user->uuid());

            return $uuid === '' ? null : $uuid;
        }

        $raw = $request->attributes->get('user');
        if (is_array($raw) && is_string($raw['uuid'] ?? null) && trim($raw['uuid']) !== '') {
            return trim($raw['uuid']);
        }

        return null;
    }

    /**
     * Fix round C1: `glueful/users` soft-resolved per call, never constructor-injected --
     * `UserRepository` may legitimately be absent (the users pack disabled), and this controller
     * must stay constructible either way.
     */
    private function users(): ?UserRepository
    {
        $container = $this->context->getContainer();
        if (!$container->has(UserRepository::class)) {
            return null;
        }
        $repository = $container->get(UserRepository::class);

        return $repository instanceof UserRepository ? $repository : null;
    }

    /**
     * The authoritative account row's verified email, by actor uuid -- NEVER the request body or
     * any JWT/claims-carried email (spec §5.2: "request/JWT email claims are never accepted").
     * Missing account (unknown or soft-deleted -- {@see UserRepository::findAccountRow()}
     * already filters `deleted_at`), empty email, or empty/null `email_verified_at` all refuse.
     */
    private function verifiedEmail(UserRepository $users, string $actorUuid): ?string
    {
        $account = $users->findAccountRow($actorUuid, ['email', 'email_verified_at']);
        if ($account === null) {
            return null;
        }

        $email = is_string($account['email'] ?? null) ? trim($account['email']) : '';
        $verifiedAt = $account['email_verified_at'] ?? null;
        if ($email === '' || $verifiedAt === null || $verifiedAt === '') {
            return null;
        }

        return $email;
    }

    // ------------------------------------------------------------------
    // Pre-checks
    // ------------------------------------------------------------------

    /**
     * `already_subscribed` predicate (mirrors `SubscriptionService::guardAgainstEntitledSubject()`
     * exactly -- design spec §4.1): active/trialing/past_due are always entitling; `non_renewing`
     * only while its period end is still in the future. This is a best-effort, non-transactional
     * pre-check for a fast, precise refusal -- `reserveCheckoutFor()` inside the coordinator's
     * transaction remains the actual enforcement authority for a concurrent race (see
     * {@see self::reservationRefusal()} for that race's HTTP mapping).
     *
     * @param array<string,mixed> $subscription
     */
    private function isEntitling(array $subscription): bool
    {
        $status = (string) ($subscription['status'] ?? '');
        if (in_array($status, ['active', 'trialing', 'past_due'], true)) {
            return true;
        }

        if ($status === 'non_renewing') {
            $periodEnd = $subscription['current_period_end'] ?? null;

            return is_scalar($periodEnd) && (string) $periodEnd !== '' && $this->inFuture((string) $periodEnd);
        }

        return false;
    }

    private function inFuture(string $dateTime): bool
    {
        try {
            return new \DateTimeImmutable($dateTime) > new \DateTimeImmutable('now');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * `checkout_pending`/`checkout_blocked` pre-check (spec §5.2): a `live` guard refuses UNLESS
     * the caller's key matches the live origination's own stored key (a legitimate resume/replay,
     * which must reach `prepare()` untouched). A `blocked` guard (projection-rejected/
     * late-settlement -- operator remediation only) applies the identical same-key exception: a
     * terminal replay of the blocked origination is harmless (Payvia never re-touches the guard
     * for a replayed claim), but a genuinely new attempt is refused -- `lockAndClaim()` would
     * refuse it anyway, this just avoids the round trip and gives a precise reason.
     */
    private function guardRefusal(string $subjectKey, string $idempotencyKey): ?Response
    {
        $guard = $this->payvia->guards()->findBySubject($this->context, $this->payvia->tenantUuid(), $subjectKey);
        if ($guard === null) {
            return null;
        }

        $state = (string) ($guard['state'] ?? '');
        if ($state !== 'live' && $state !== 'blocked') {
            return null;
        }

        $origination = $this->originationForGuard($guard);

        if ($origination !== null && (string) $origination['idempotency_key'] === $idempotencyKey) {
            return null; // same-key resume: let prepare() replay it.
        }

        if ($state === 'live') {
            return Response::error(
                'A checkout is already in progress for this workspace.',
                409,
                [
                    'code' => 'checkout_pending',
                    'status' => $origination['status'] ?? null,
                    'checkout_url' => $origination['checkout_url'] ?? null,
                ],
            );
        }

        return Response::error(
            "This workspace's checkout requires operator resolution before another attempt can start.",
            409,
            ['code' => 'checkout_blocked', 'reason' => $this->blockedReason($guard, $origination)],
        );
    }

    /**
     * Fix round I8: re-reads the guard/origination FRESH -- used when a race is caught between
     * this action's own {@see self::guardRefusal()} pre-check and Payvia's own `lockAndClaim()`
     * (a concurrent attempt won the guard in between), so the stored `checkout_url` this response
     * reports is never stale.
     */
    private function livePendingRefusal(string $subjectKey): Response
    {
        $guard = $this->payvia->guards()->findBySubject($this->context, $this->payvia->tenantUuid(), $subjectKey);
        $origination = $guard !== null ? $this->originationForGuard($guard) : null;

        return Response::error(
            'A checkout is already in progress for this workspace.',
            409,
            [
                'code' => 'checkout_pending',
                'status' => $origination['status'] ?? null,
                'checkout_url' => $origination['checkout_url'] ?? null,
            ],
        );
    }

    /**
     * Fix round I3: maps `reserveCheckoutFor()`'s race-path refusal (caught when a concurrent
     * caller entitled the subject between this action's own pre-check and the coordinator's
     * transaction) onto the SAME `subscription_already_active` code the pre-check itself uses --
     * one refusal vocabulary regardless of which layer caught it. Host-authored message: never
     * `$e->getMessage()` verbatim (it embeds the subject key). `replace_refused` is not reachable
     * through this endpoint (the coordinator always passes `replace: true`, per spec §4.1's own
     * "only Payvia's prepare() continuation... may set that flag"); mapped defensively rather than
     * asserted unreachable.
     */
    private function reservationRefusal(CheckoutReservationException $e): Response
    {
        if ($e->reasonCode === 'already_subscribed') {
            return Response::error(
                'This workspace already has an active subscription.',
                409,
                ['code' => 'subscription_already_active'],
            );
        }

        return Response::error(
            'This workspace cannot start this checkout right now.',
            409,
            ['code' => 'checkout_reservation_conflict'],
        );
    }

    /** @param array<string,mixed> $guard @return array<string,mixed>|null */
    private function originationForGuard(array $guard): ?array
    {
        $originationUuid = $guard['origination_uuid'] ?? null;

        return is_string($originationUuid) && $originationUuid !== ''
            ? $this->payvia->originations()->findByUuid($originationUuid)
            : null;
    }

    /**
     * Fix round I8: the ONE blocked-reason derivation, shared by {@see self::guardRefusal()}
     * (`POST /checkout`'s `checkout_blocked` detail) and {@see self::guardState()} (`GET /meta`'s
     * `operator_contact_reason`) -- previously these read two DIFFERENT fields (the origination's
     * status vs. the guard's free-text `blocked_reason`), so the two endpoints could disagree
     * about why a workspace was blocked.
     *
     * @param array<string,mixed> $guard
     * @param array<string,mixed>|null $origination
     */
    private function blockedReason(array $guard, ?array $origination): string
    {
        return $origination !== null
            ? (string) $origination['status']
            : (string) ($guard['blocked_reason'] ?? '');
    }

    // ------------------------------------------------------------------
    // Plan purchasability
    // ------------------------------------------------------------------

    /** @return array{plan_uuid:string,plan_key:string,name:string,provider_identifier:string}|null */
    private function findPurchasablePlan(string $gateway, string $planKey): ?array
    {
        foreach (PlanPurchasability::forGateway($this->context, $gateway) as $plan) {
            if ($plan['plan_key'] === $planKey) {
                return $plan;
            }
        }

        return null;
    }

    /** @return list<array{plan_key:string,name:string}> */
    private function purchasablePlans(string $gateway): array
    {
        return array_values(array_map(
            static fn (array $plan): array => ['plan_key' => $plan['plan_key'], 'name' => $plan['name']],
            PlanPurchasability::forGateway($this->context, $gateway),
        ));
    }

    // ------------------------------------------------------------------
    // Meta projections
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function projectSubscription(EngineServices $engine, string $workspaceUuid): ?array
    {
        $subscription = $engine->subscriptions()->current($workspaceUuid);
        if ($subscription === null) {
            return null;
        }

        return [
            'status' => $subscription['status'] ?? null,
            'plan_key' => $subscription['plan_key'] ?? null,
            'current_period_end' => $subscription['current_period_end'] ?? null,
            'provider_managed' => (string) ($subscription['provider_subscription_id'] ?? '') !== '',
        ];
    }

    /**
     * @return array{0:?array{status:string,checkout_url:?string},1:?string} [origination
     *         projection for a `live` guard, operator-contact reason for a `blocked` guard]
     */
    private function guardState(string $subjectKey): array
    {
        $guard = $this->payvia->guards()->findBySubject($this->context, $this->payvia->tenantUuid(), $subjectKey);
        if ($guard === null) {
            return [null, null];
        }

        $origination = $this->originationForGuard($guard);
        $state = (string) ($guard['state'] ?? '');

        if ($state === 'live') {
            if ($origination === null) {
                return [null, null];
            }

            return [
                ['status' => (string) $origination['status'], 'checkout_url' => $origination['checkout_url'] ?? null],
                null,
            ];
        }

        if ($state === 'blocked') {
            return [null, $this->blockedReason($guard, $origination)];
        }

        return [null, null];
    }

    // ------------------------------------------------------------------
    // POST /checkout response shaping (see class docblock for the pinned status -> HTTP table)
    // ------------------------------------------------------------------

    private function respondForResult(SubscriptionCheckoutResult $result): Response
    {
        return match ($result->status) {
            'initializing' => $this->initializingResponse(),
            'failed' => Response::error(
                'This checkout attempt failed.',
                409,
                ['code' => 'checkout_failed', 'status' => 'failed'],
            ),
            'expired' => Response::error(
                'This checkout attempt has expired.',
                409,
                ['code' => 'checkout_expired', 'status' => 'expired'],
            ),
            'abandoned' => Response::error(
                'This checkout attempt was abandoned.',
                409,
                ['code' => 'checkout_abandoned', 'status' => 'abandoned'],
            ),
            // Fix round 2 (code review, I6 residual): a `provider_observed`/`dispatched` replay
            // NEVER re-serves the stored checkout_url -- the session is spent (money has already
            // moved, or the finalizer has already confirmed acceptance), and re-serving it would
            // invite a double-payment attempt against an already-completing/completed session.
            // Pinned in this class's own docblock table (Note A).
            'provider_observed', 'dispatched' => Response::success(
                ['status' => $result->status, 'checkout_url' => null],
                'Checkout ready',
            ),
            default => Response::success(
                ['status' => $result->status, 'checkout_url' => $result->checkoutUrl],
                'Checkout ready',
            ),
        };
    }

    private function initializingResponse(): Response
    {
        $response = Response::success(['status' => 'initializing', 'checkout_url' => null], 'Checkout initializing');
        $response->setStatusCode(202);

        return $response;
    }

    /**
     * Fix round I7: the caller of `initializeClaim()` MUST NOT silently swallow an unknown
     * provider-I/O outcome -- log it at error level (with the origination uuid, which is safe to
     * log: local correlation state, never PII/secret) so an operator can investigate a workspace
     * stuck `initializing` past the recovery threshold, even though the HTTP response itself
     * stays a plain 202 (design spec §3.2: "`initializing` rows past a recovery threshold are
     * surfaced (diagnostics/console), never auto-failed or freed").
     */
    private function logUnknownInitializationOutcome(string $originationUuid, \Throwable $e): void
    {
        $container = $this->context->getContainer();
        if (!$container->has(LoggerInterface::class)) {
            return;
        }
        $logger = $container->get(LoggerInterface::class);
        if (!$logger instanceof LoggerInterface) {
            return;
        }

        $logger->error(
            'Payvia checkout initialization returned an unknown outcome; origination remains '
                . 'initializing for same-key retry.',
            [
                'origination_uuid' => $originationUuid,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ],
        );
    }

    // ------------------------------------------------------------------
    // Input parsing
    // ------------------------------------------------------------------

    private function isValidIdempotencyKey(string $key): bool
    {
        $length = strlen($key);

        return $length >= self::IDEMPOTENCY_KEY_MIN
            && $length <= self::IDEMPOTENCY_KEY_MAX
            && preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) === 1;
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $content = (string) $request->getContent();
        if ($content === '') {
            return [];
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
