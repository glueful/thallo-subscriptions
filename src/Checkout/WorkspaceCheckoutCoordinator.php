<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Checkout;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutClaim;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutResult;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Glueful\Extensions\Subscriptions\SubscriptionService;

/**
 * Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2): the ONE orchestration seam
 * over Payvia's `SubscriptionCheckoutService::prepare()`/`initializeClaim()` and Subscriptions'
 * `reserveCheckoutFor()` -- the "atomically prepare checkout locally -> claim the Payvia live
 * origination -> bind the engine's non-entitling reservation -> commit, then initialize provider
 * checkout" sequence the design's canonical flow (top of the spec) describes.
 *
 * `SelfBillingController` owns every refusal that must happen BEFORE any Payvia/subscriptions
 * write (switch recheck, already-active/checkout-pending/plan-purchasability pre-checks,
 * verified-email load) -- this class assumes all of that has already passed and focuses purely
 * on the atomic origination + reservation handshake. Domain exceptions
 * ({@see \Glueful\Extensions\Subscriptions\CheckoutReservationException},
 * {@see \Glueful\Extensions\Payvia\Checkout\OriginationLiveException},
 * {@see \Glueful\Extensions\Payvia\Checkout\IdempotencyConflictException},
 * {@see \Glueful\Extensions\Payvia\Checkout\CheckoutUnavailableException}) are left to propagate
 * -- the controller maps them to the HTTP refusal vocabulary, this class stays HTTP-agnostic.
 *
 * Fix round (code review, Critical C1): deliberately NOT container-bound/autowired -- its
 * constructor hard-requires Payvia's `SubscriptionCheckoutService`/`CheckoutOriginationRepository`
 * and the subscriptions engine's `SubscriptionService`, all three of which may be legitimately
 * ABSENT in a host that has not enabled those extensions. Autowiring this class as a
 * `SelfBillingController` constructor dependency would make the controller itself
 * unconstructible (and therefore `GET /meta` un-reachable, 500ing instead of 200ing) the moment
 * either extension is off. The controller instead resolves `PayviaCheckoutGateway`/`EngineGateway`
 * lazily per action and, only once both are confirmed available, builds a fresh instance of this
 * class with `new` for that one request.
 */
final class WorkspaceCheckoutCoordinator
{
    public function __construct(
        private readonly SubscriptionCheckoutService $checkout,
        private readonly CheckoutOriginationRepository $originations,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * @param array{plan_uuid:string,plan_key:string,name:string,provider_identifier:string} $plan
     *        one `PlanPurchasability::forGateway()` row -- the controller's own plan_not_purchasable
     *        pre-check already proved this plan is purchasable for `$gateway`.
     * @param string $returnUrlBase the canonical admin `/billing/return` URL WITHOUT the
     *        `origination` query param -- this method appends it using the RESOLVED origination
     *        uuid (see {@see self::resolveOriginationUuid()}) so a same-key retry builds a
     *        byte-identical URL to what is already stored (Payvia's request fingerprint includes
     *        `returnUrl`/`cancelUrl`; a freshly minted uuid on every call would spuriously trip
     *        `IdempotencyConflictException` on a legitimate resume/replay).
     *
     * Split into {@see self::prepare()} + {@see self::initialize()} (rather than one combined
     * call) so the controller can apply a DIFFERENT exception policy to each stage: `prepare()`
     * only ever throws the closed, already-handled domain exception set (design spec §3.2/§4.1)
     * -- anything else there is a genuine bug and must surface as a server error. `initialize()`
     * deliberately treats EVERY exception as an unknown provider outcome (spec §3.2's own
     * "release only the execution lease... rethrow so a later replay resumes safely" contract),
     * which the controller maps to the SAME 202 `initializing` response as a concurrent lease
     * loser.
     */
    public function prepare(
        ApplicationContext $context,
        string $tenantUuid,
        array $plan,
        string $actorUuid,
        string $verifiedEmail,
        string $idempotencyKey,
        string $gateway,
        string $returnUrlBase,
    ): SubscriptionCheckoutClaim {
        $subjectKey = self::subjectKey($tenantUuid);
        $originationUuid = $this->resolveOriginationUuid($context, $tenantUuid, $idempotencyKey);
        $destinationUrl = self::withOrigination($returnUrlBase, $originationUuid);

        $request = new SubscriptionCheckoutRequest(
            originationUuid: $originationUuid,
            tenantUuid: $tenantUuid,
            subjectKey: $subjectKey,
            gateway: $gateway,
            providerPlanIdentifier: $plan['provider_identifier'],
            consumerMetadata: [
                'tenant_uuid' => $tenantUuid,
                'subject_type' => SubjectType::TENANT,
                'subject_uuid' => $tenantUuid,
                'plan_uuid' => $plan['plan_uuid'],
                'glueful_consumer' => 'subscriptions',
                'actor_user_uuid' => $actorUuid,
            ],
            customerEmail: $verifiedEmail,
            returnUrl: $destinationUrl,
            cancelUrl: $destinationUrl,
            idempotencyKey: $idempotencyKey,
            requiredProjectionConsumer: 'subscriptions',
        );

        $subscriptions = $this->subscriptions;
        $subject = Subject::tenant($tenantUuid);
        $planUuid = $plan['plan_uuid'];

        // The local-only continuation Payvia's prepare() invokes INSIDE its single owning
        // transaction, AFTER the new origination has won the database live guard -- exactly the
        // precondition reserveCheckoutFor()'s own docblock requires before `replace: true` may be
        // passed (design spec §4.1). No network I/O happens here.
        return $this->checkout->prepare(
            $context,
            $request,
            static function ($claim) use ($subscriptions, $subject, $planUuid, $actorUuid): void {
                $subscriptions->reserveCheckoutFor($subject, $planUuid, $claim->originationUuid, [
                    'actor' => $actorUuid,
                    'replace' => true,
                ]);
            },
        );
    }

    /**
     * Zero-I/O for anything not currently `initializing` (a replayed/terminal claim just reads
     * the stored row back), so it is always safe to call unconditionally after a successful
     * {@see self::prepare()}.
     */
    public function initialize(ApplicationContext $context, string $originationUuid): SubscriptionCheckoutResult
    {
        return $this->checkout->initializeClaim($context, $originationUuid);
    }

    public static function subjectKey(string $tenantUuid): string
    {
        return 'tenant:' . $tenantUuid;
    }

    /**
     * Reuse the uuid of any origination already on file for this (tenant, idempotency key) pair
     * -- live, blocked, or terminal -- so the URL this call builds matches byte-for-byte whatever
     * was fingerprinted on the original attempt.
     *
     * Fix round (code review, Important I2): a genuinely new key does NOT mint a random uuid --
     * it derives one DETERMINISTICALLY from `sha256(tenantUuid . ':' . idempotencyKey)` (first 12
     * hex characters, fitting the `uuid` column's bound). A random mint would race a concurrent
     * duplicate submission of the SAME idempotency key (e.g. a retried request whose first
     * attempt has not committed yet): `findByIdempotencyKey()` would still find nothing for
     * EITHER caller, each would mint a DIFFERENT random uuid, embed it into `returnUrl`/
     * `cancelUrl`, and Payvia's own fingerprint (which hashes those URLs verbatim) would then
     * disagree between the two callers -- turning a legitimate concurrent same-key retry into a
     * spurious `IdempotencyConflictException`. Deriving the uuid from the (tenant, key) pair
     * alone means every caller who ever presents this exact pair computes the IDENTICAL uuid
     * before either one has written anything, so the URL -- and therefore the fingerprint --
     * agrees regardless of arrival order. The origination's `uuid` column is globally unique
     * (design spec §3.3), so a distinct (tenant, key) pair hashing to the same 12 characters as
     * an unrelated existing row is a birthday-bound collision on a 48-bit space -- the same order
     * of magnitude as a random 12-character nanoid collision, and surfaces as an ordinary unique-
     * constraint race Payvia's own `claimPreparing()` already recovers from (re-reads the
     * winner by idempotency key; a genuine collision with an UNRELATED key simply re-raises,
     * exactly as an accidental nanoid collision would today).
     */
    private function resolveOriginationUuid(
        ApplicationContext $context,
        string $tenantUuid,
        string $idempotencyKey,
    ): string {
        $existing = $this->originations->findByIdempotencyKey($context, $idempotencyKey);
        if ($existing !== null) {
            return (string) $existing['uuid'];
        }

        return substr(hash('sha256', $tenantUuid . ':' . $idempotencyKey), 0, 12);
    }

    private static function withOrigination(string $url, string $originationUuid): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'origination=' . rawurlencode($originationUuid);
    }
}
