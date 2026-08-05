<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Subscriptions\Http\SelfBillingController;

/** @var Router $router */

/*
 * Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2): the workspace-scoped
 * self-serve billing API -- `GET /meta` + `POST /checkout` at `/v1/admin/billing`. Task 17 adds
 * `POST /cancel` + `POST /checkout/abandon` to the SAME group/middleware/permission -- cancel is
 * never gated by the operator switch (spec §1), but it is still workspace-scoped `billing.manage`
 * authority, exactly like every other route in this file.
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
 * §6's failure matrix ("Capability thallo.subscriptions off -> routes 404"). The platform-authority
 * `subscriptions:checkout:resolve` console command (spec §3.8/§5.2) is deliberately NOT mounted
 * here at all -- it is a CLI-only surface, never exposed through workspace routes.
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
        $router->post('/cancel', [SelfBillingController::class, 'cancel'])
            ->middleware('content_permission:billing.manage')
            ->name('thallo.subscriptions.billing.cancel');
        $router->post('/checkout/abandon', [SelfBillingController::class, 'abandon'])
            ->middleware('content_permission:billing.manage')
            ->name('thallo.subscriptions.billing.checkout.abandon');
    },
);
