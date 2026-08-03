<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Final-wave fix A: the ONE guard that neutralises glueful/subscriptions' own native plan-management
 * routes inside Thallo.
 *
 * The engine's `routes.php` mounts `GET|POST /subscriptions/plans`,
 * `POST /subscriptions/plans/import-config`, `GET|PATCH /subscriptions/plans/{key}` and
 * `POST /subscriptions/plans/{key}/archive` behind `['auth', 'subscriptions_plans_manage']` -- a raw
 * `PermissionManager::can('subscriptions.plans.manage')` check that knows nothing about this pack's
 * `thallo.subscriptions` capability gate, nothing about `tenant_system`/`tenancy.manage` platform
 * authority, and nothing about the {@see \Thallo\Subscriptions\Engine\EngineGateway} degradation
 * contract. Spec §3 explicitly REJECTS a tenant-grantable plan-administration permission ("it would
 * let one workspace admin edit global plans"), so those mounts must not be reachable in Thallo at
 * all: the platform Plans surface is `/v1/admin/subscriptions/plans`, and nothing else.
 *
 * This middleware answers the framework's OWN unmatched-route response byte-for-byte
 * (`Response::error('Not Found', 404)` -- exactly what `Router::dispatch()` returns when `match()`
 * finds nothing), so the native mounts are indistinguishable from absent for every caller,
 * authenticated or not. It is deliberately the FIRST middleware on those routes (see
 * {@see \Thallo\Subscriptions\EnginePreemptionServiceProvider::denyEngineNativePlanRoutes()}),
 * so neither `auth` nor the engine's own permission check ever runs -- an anonymous probe cannot
 * even tell the engine is installed by getting a 401 where an unknown path would 404.
 */
final class EngineNativeRoutesDenied implements RouteMiddleware
{
    /** Route-middleware alias this guard is registered under (see the pack provider's services()). */
    public const ALIAS = 'thallo.subscriptions.engine_native_denied';

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        return Response::error('Not Found', Response::HTTP_NOT_FOUND);
    }
}
