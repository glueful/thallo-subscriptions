<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Subscriptions\Http\MetaController;
use Thallo\Subscriptions\Http\PlansController;
use Thallo\Subscriptions\Http\SelfServeSettingsController;
use Thallo\Subscriptions\Http\WorkspaceBillingController;

/** @var Router $router */

/*
 * Task 8 (Phase B): the platform Plans admin API -- this pack's first HTTP surface, and the
 * route/auth/degraded-mode conventions Task 9 (per-workspace subscriptions) reuses. Mirrors
 * `packages/thallo-commerce/routes/admin-routes.php`'s group/loadRoutesFrom idiom, loaded only
 * from inside the `thallo.subscriptions` capability gate in the provider's boot() (else 404).
 *
 * Platform authority ruling (non-negotiable, user decision): the group middleware is EXACTLY
 * `['auth', 'tenant_system', 'content_permission:tenancy.manage']` -- these are PLATFORM-scope
 * plans (the shared catalog every workspace subscribes from), not a per-workspace resource, so
 * this mount uses the SAME system-operator gate as the other `tenant_system` + `tenancy.manage`
 * routes in `routes/admin.php` (e.g. `/tenancy/hosts/cooldown/override`) rather than Commerce's
 * `tenant_profile:admin` workspace-scoped chain. Every route carries an explicit
 * `thallo.subscriptions.admin.<key>` name (mirrors Commerce's own naming convention).
 */
$router->group(
    [
        'prefix' => '/v1/admin/subscriptions',
        'middleware' => ['auth', 'tenant_system', 'content_permission:tenancy.manage'],
    ],
    function (Router $router): void {
        $router->get('/plans', [PlansController::class, 'index'])
            ->name('thallo.subscriptions.admin.plans.index');
        $router->post('/plans', [PlansController::class, 'store'])
            ->name('thallo.subscriptions.admin.plans.store');
        // Static `/plans/import-config` is registered before the dynamic `/plans/{key}` PATCH
        // (irrelevant here since the methods differ, but keeps the file's route order matching
        // the brief's own listing). `plan_key` "import-config" is itself reserved by the
        // engine's own PlanPayloadValidator, so a create/update can never collide with this path.
        $router->patch('/plans/{key}', [PlansController::class, 'update'])
            ->name('thallo.subscriptions.admin.plans.update');
        $router->post('/plans/{key}/archive', [PlansController::class, 'archive'])
            ->name('thallo.subscriptions.admin.plans.archive');
        $router->post('/plans/import-config', [PlansController::class, 'importConfig'])
            ->name('thallo.subscriptions.admin.plans.import_config');

        // Task 9 (Phase B): the workspace billing admin API + meta -- joins the tenancy
        // directory with the subscriptions engine. Same group middleware, same
        // `thallo.subscriptions.admin.*` naming convention as Task 8's Plans routes above.
        $router->get('/meta', [MetaController::class, 'show'])
            ->name('thallo.subscriptions.admin.meta');

        // Task 15 (Phase C, workspace self-serve checkout plan, spec §5.1): the
        // `self_serve_checkout_enabled` operator kill switch. Same group middleware, same
        // `thallo.subscriptions.admin.*` naming convention -- this is a PLATFORM-scope setting
        // (whether ANY workspace may self-serve checkout), never a per-workspace resource.
        $router->put('/self-serve', [SelfServeSettingsController::class, 'update'])
            ->name('thallo.subscriptions.admin.self_serve.update');

        $router->get('/workspaces', [WorkspaceBillingController::class, 'index'])
            ->name('thallo.subscriptions.admin.workspaces.index');
        $router->get('/workspaces/{uuid}', [WorkspaceBillingController::class, 'show'])
            ->name('thallo.subscriptions.admin.workspaces.show');
        $router->put('/workspaces/{uuid}/plan', [WorkspaceBillingController::class, 'setPlan'])
            ->name('thallo.subscriptions.admin.workspaces.plan');
        $router->post('/workspaces/{uuid}/cancel', [WorkspaceBillingController::class, 'cancel'])
            ->name('thallo.subscriptions.admin.workspaces.cancel');
        $router->put('/workspaces/{uuid}/overrides/{entitlement}', [WorkspaceBillingController::class, 'upsertOverride'])
            ->name('thallo.subscriptions.admin.workspaces.overrides.upsert');
        $router->delete(
            '/workspaces/{uuid}/overrides/{entitlement}',
            [WorkspaceBillingController::class, 'deleteOverride'],
        )->name('thallo.subscriptions.admin.workspaces.overrides.delete');
    },
);
