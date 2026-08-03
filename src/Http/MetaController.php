<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Engine\EngineGateway;
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
 */
final class MetaController
{
    public function __construct(
        private readonly EngineGateway $gateway,
        private readonly SystemFlags $flags,
        private readonly SingleStoreTenant $singleStore,
    ) {
    }

    #[ApiOperation(summary: 'Subscriptions engine + tenancy status', tags: ['Thallo Subscriptions'])]
    public function show(Request $request): Response
    {
        $tenancyEnabled = $this->flags->tenancyEnabled();

        return Response::success([
            'engine' => $this->gateway->engineState(),
            'tenancy_enabled' => $tenancyEnabled,
            // Only meaningful in single-store mode -- tenancy ON has no single "default"
            // workspace concept, so it stays null rather than reporting some arbitrary tenant.
            'default_tenant_uuid' => $tenancyEnabled ? null : $this->singleStore->defaultUuidOrNull(),
        ], 'Subscriptions status retrieved');
    }
}
