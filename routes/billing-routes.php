<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Subscriptions\Http\SelfBillingController;

/** @var Router $router */

/*
 * Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2): the workspace-scoped
 * self-serve billing API -- `GET /meta` + `POST /checkout` at `/v1/admin/billing`.
 *
 * Mirrors `packages/thallo-commerce/routes/admin-routes.php`'s workspace chain -- `auth` +
 * `tenant_profile:admin` + `tenant_bootstrap` + `admin_tenant_binding` binds the operator's
 * selected workspace server-side (never trusted from request input, spec §1) -- rather than this
 * pack's OWN `/v1/admin/subscriptions` group above (`tenant_system` + `content_permission:
 * tenancy.manage`), which is platform-scoped. `billing.manage` is a disjoint, per-workspace
 * DELEGABLE authority (spec §5.1/§1) applied per-route here, matching Commerce's own per-route
 * `content_permission` grading rather than a single group-wide requirement.
 *
 * Loaded from the provider's `boot()` INSIDE the `thallo.subscriptions` capability gate (mirrors
 * `admin-routes.php`) -- capability off means these routes are entirely absent (404), matching
 * §6's failure matrix ("Capability thallo.subscriptions off -> routes 404").
 */
$router->group(
    [
        'prefix' => '/v1/admin/billing',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/meta', [SelfBillingController::class, 'meta'])
            ->middleware('content_permission:billing.manage')
            ->name('thallo.subscriptions.billing.meta');
        $router->post('/checkout', [SelfBillingController::class, 'checkout'])
            ->middleware('content_permission:billing.manage')
            ->name('thallo.subscriptions.billing.checkout');
    },
);
