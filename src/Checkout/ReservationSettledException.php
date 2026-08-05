<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Checkout;

/**
 * Task 17 (abandon/operator reconciliation, design spec §4.1/§3.8): thrown ONLY by the
 * `subscriptions:checkout:resolve` console continuation (never by
 * `SelfBillingController::abandon()`, which reports the identical refusal as a plain 409 instead
 * -- see {@see CheckoutReservationRelease}'s own docblock for why the two callers need different
 * shapes for the SAME underlying signal) when {@see CheckoutReservationRelease::
 * releaseOrDetectSettled()} determines that `SubscriptionService::releaseCheckoutReservation()`
 * returned `false` because the bound reservation already carries provider fields -- i.e. the
 * checkout actually completed and the local subscription is no longer the non-entitling
 * `incomplete` row `reserveCheckoutFor()` created. Throwing here is deliberate: Payvia's
 * `CheckoutReconciliationService::resolve()` runs the host's continuation INSIDE its single owning
 * transaction, so this throw aborts the ENTIRE resolution (the origination's status/audit-note
 * write and the subject guard's reopen both roll back together) rather than letting an operator
 * mistakenly reconcile-away a checkout that in fact succeeded.
 */
final class ReservationSettledException extends \RuntimeException
{
    public const MARKER = 'reservation_settled';

    public function __construct(string $originationUuid)
    {
        parent::__construct(sprintf(
            '%s: the reservation bound to checkout origination %s already carries provider fields '
                . '(it settled) -- refusing to treat this origination as dead/refunded.',
            self::MARKER,
            $originationUuid,
        ));
    }
}
