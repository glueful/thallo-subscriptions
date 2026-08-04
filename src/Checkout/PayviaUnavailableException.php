<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Checkout;

/**
 * Thrown by {@see PayviaCheckoutGateway}'s accessors when `glueful/payvia`'s checkout-ledger
 * services are not bound in this host (its provider is inactive, or a stripped-down boot never
 * loaded it). Mirrors {@see \Thallo\Subscriptions\Engine\EngineUnavailableException}'s role for
 * the subscriptions engine -- `SelfBillingController` catches this and answers a structured 409
 * rather than letting it propagate as a 500.
 */
final class PayviaUnavailableException extends \RuntimeException
{
    public const CODE = 'payvia_unavailable';

    public function __construct()
    {
        parent::__construct('payvia checkout ledger services are unavailable in this host.');
    }
}
