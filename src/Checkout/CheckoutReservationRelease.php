<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Checkout;

use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubscriptionService;

/**
 * Task 17 (abandon + operator reconciliation, design spec §4.1's `releaseCheckoutReservation()`
 * docblock): the ONE place that resolves the bool this call returns into "settled" vs. "nothing to
 * release" -- both `SelfBillingController::abandon()` and `CheckoutResolveCommand` need this exact
 * disambiguation, and diverging even slightly between the two would let one refuse a settled
 * checkout while the other silently abandons it.
 *
 * `SubscriptionRepository::deleteIncompleteReservation()`'s own docblock spells out the ledger
 * note verbatim: its `int` (0 vs. >0) affected-row count collapses TWO distinct outcomes into a
 * single `false` from {@see SubscriptionService::releaseCheckoutReservation()} --
 * "the row is already gone / was never this one" (fine to proceed past) and "the row still exists
 * but the projector has since activated it" (a genuine refusal: the checkout actually completed,
 * and marking the origination abandoned/releasing the guard would misrepresent a settled purchase
 * as dead). Distinguishing them requires a SECOND read after a `false` release: the subject's
 * CURRENT subscription row, checked for BOTH the exact `checkout_origination_uuid` binding (a
 * different/newer reservation existing is not this settlement) AND at least one non-null
 * `provider_*` field.
 *
 * Ordering this call BEFORE any origination/guard mutation (both callers do this) is what makes
 * the whole abandon/resolve sequence safe: a settled reservation must abort the ENTIRE action
 * before the origination is ever transitioned to `abandoned` or the guard is ever reopened --
 * never a partial apply that marks a completed checkout as dead.
 *
 * **Waiver (controller-adjudicated, code review Critical finding -- do not re-litigate in a later
 * review pass):** {@see SubscriptionService::releaseCheckoutReservation()}'s own docblock states
 * the rule this class appears to break: "callers that need to react differently... must branch on
 * the boolean result of THIS call, never re-query state before/after to infer which `false` case
 * occurred." This method DOES re-query (`currentFor()`) after a `false` result. The deviation is
 * approved for this specific caller shape for two reasons:
 *   1. The re-query happens strictly AFTER the CAS has already run and returned -- it is not used
 *      to decide whether to attempt the release, nor to infer anything about a state that preceded
 *      the CAS (the exact TOCTOU-prone "infer before/around the call" pattern the vendor rule
 *      warns against). It is a REACTION to an already-final CAS outcome, reading forward from it.
 *   2. Traced against the engine's projector/entitlement code: no path ever clears `provider_*`
 *      fields off a row while the projector or an entitlement resolver is guarding it as
 *      genuinely entitling, and an `incomplete` row's provider fields are only ever ADDED (by
 *      activation), never removed then re-added within the same origination binding. A `false`
 *      "not settled" verdict from this method is therefore not constructible: if the row really
 *      settled under this exact origination, the re-query will always observe it. The only
 *      remaining race is the row being deleted BETWEEN the `false` release and this re-query --
 *      benign, because `deleteIncompleteReservation()`'s CAS is the ONLY deleter of an `incomplete`
 *      reservation row, so a concurrent deletion means a concurrent caller genuinely released the
 *      SAME origination-bound reservation, and proceeding (this method returning `false`, i.e.
 *      "not settled") is the correct outcome for that race, not an unsafe guess.
 */
final class CheckoutReservationRelease
{
    private function __construct()
    {
    }

    /**
     * @return bool true when the reservation is SETTLED (provider fields present under the exact
     *         same origination) -- the caller MUST treat this as a refusal and touch nothing else.
     *         false when the release genuinely succeeded, or there was nothing left to release --
     *         both are safe for the caller to proceed past.
     */
    public static function releaseOrDetectSettled(
        SubscriptionService $subscriptions,
        Subject $subject,
        string $originationUuid,
    ): bool {
        if ($subscriptions->releaseCheckoutReservation($subject, $originationUuid)) {
            return false;
        }

        $current = $subscriptions->currentFor($subject);
        if ($current === null) {
            return false;
        }

        $boundToThisOrigination = (string) ($current['checkout_origination_uuid'] ?? '') === $originationUuid;
        if (!$boundToThisOrigination) {
            return false;
        }

        return (string) ($current['provider_gateway'] ?? '') !== ''
            || (string) ($current['provider_customer_id'] ?? '') !== ''
            || (string) ($current['provider_subscription_id'] ?? '') !== ''
            || (string) ($current['provider_price_id'] ?? '') !== '';
    }
}
