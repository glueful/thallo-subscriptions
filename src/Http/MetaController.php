<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 9 (Phase B): `GET /v1/admin/subscriptions/meta` -- the one status endpoint this pack
 * exposes that returns 200 ALWAYS, in every engine state and every tenancy mode. The admin SPA
 * drives its empty/degraded states from this response, so it must never itself become a 500:
 * `EngineGateway::engineState()` is a pure probe that never throws (unlike `subscriptions()`/
 * `plans()`/`overrides()`), and the default-workspace read goes through {@see
 * SingleStoreTenant::defaultUuidOrNull()} rather than `resolve()` -- a fresh single-store install
 * with no established default pointer is a REPRESENTABLE state (`default_tenant_uuid: null`), not
 * an error this endpoint should surface as one.
 *
 * Task 15 (spec §5.1) additionally exposes the `self_serve_checkout_enabled` kill switch plus
 * `self_serve_gateway`/`self_serve_gateway_capable`/`self_serve_gateway_capable_reason` -- the
 * SAME {@see SelfServeGatewayCapability} verdict `SelfServeSettingsController` enforces before
 * allowing an enable, so the platform Billing page can explain an unavailable switch (WHICH
 * gateway is configured and why it doesn't qualify) without ever attempting -- and being
 * refused -- the write itself.
 */
final class MetaController
{
    public function __construct(
        private readonly EngineGateway $gateway,
        private readonly SystemFlags $flags,
        private readonly SingleStoreTenant $singleStore,
        private readonly SelfServeCheckoutSetting $selfServe,
        private readonly SelfServeGatewayCapability $selfServeCapability,
    ) {
    }

    #[ApiOperation(summary: 'Subscriptions engine + tenancy status', tags: ['Thallo Subscriptions'])]
    public function show(Request $request): Response
    {
        $tenancyEnabled = $this->flags->tenancyEnabled();
        $capability = $this->selfServeCapability->evaluate();

        return Response::success([
            'engine' => $this->gateway->engineState(),
            'tenancy_enabled' => $tenancyEnabled,
            // Only meaningful in single-store mode -- tenancy ON has no single "default"
            // workspace concept, so it stays null rather than reporting some arbitrary tenant.
            'default_tenant_uuid' => $tenancyEnabled ? null : $this->singleStore->defaultUuidOrNull(),
            'self_serve_checkout_enabled' => $this->selfServe->isEnabled(),
            'self_serve_gateway' => $capability['gateway'],
            'self_serve_gateway_capable' => $capability['capable'],
            'self_serve_gateway_capable_reason' => $capability['reason'],
        ], 'Subscriptions status retrieved');
    }
}
