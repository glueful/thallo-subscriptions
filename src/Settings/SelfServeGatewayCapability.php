<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Support\PayviaSettings;

/**
 * Task 15 (spec §5.1): the lazy, per-request verdict on whether the CONFIGURED default Payvia
 * gateway can initiate subscription checkout -- the gate {@see
 * \Thallo\Subscriptions\Http\SelfServeSettingsController} enforces before flipping the switch ON,
 * and the same verdict {@see \Thallo\Subscriptions\Http\MetaController} exposes so the platform
 * Billing page can explain an unavailable switch without attempting to enable it.
 *
 * Resolves payvia SOFTLY, following {@see \Thallo\Subscriptions\Engine\EngineGateway}'s idiom:
 * never constructor-injects `GatewayManager` (autowiring it would hard-fail with payvia's own
 * provider inactive), probes the container's `has()` fresh on every call, and never caches a
 * verdict -- an operator can enable/disable the payvia extension, or reconfigure
 * `payvia.default_gateway`, between two calls in the same process.
 */
final class SelfServeGatewayCapability
{
    public const REASON_PAYVIA_UNAVAILABLE = 'payvia_unavailable';
    public const REASON_GATEWAY_NOT_CAPABLE = 'gateway_not_capable';

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** @return array{capable: bool, gateway: ?string, reason: ?string} */
    public function evaluate(): array
    {
        if (!$this->context->getContainer()->has(GatewayManager::class)) {
            return ['capable' => false, 'gateway' => null, 'reason' => self::REASON_PAYVIA_UNAVAILABLE];
        }

        $gateway = PayviaSettings::defaultGateway($this->context);
        /** @var GatewayManager $manager */
        $manager = $this->context->getContainer()->get(GatewayManager::class);

        if (!$manager->supports($gateway, 'subscription_checkout')) {
            return ['capable' => false, 'gateway' => $gateway, 'reason' => self::REASON_GATEWAY_NOT_CAPABLE];
        }

        return ['capable' => true, 'gateway' => $gateway, 'reason' => null];
    }
}
