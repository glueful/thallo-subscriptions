<?php

declare(strict_types=1);

namespace Thallo\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\EngineNativeRoutesDenied;
use Thallo\Subscriptions\Http\MetaController;
use Thallo\Subscriptions\Http\PlansController;
use Thallo\Subscriptions\Http\WorkspaceBillingController;
use Thallo\Subscriptions\Purge\SubscriptionsPurgeHandler;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;

use function app;

/**
 * PLATFORM-AUTHORITY RULING (final-wave fix A, controller decision -- consistent with spec §3's own
 * "a tenant-grantable `subscriptions.manage` permission is explicitly rejected" ruling):
 *
 * glueful/subscriptions' provider unconditionally calls `loadRoutesFrom(vendor/glueful/subscriptions/
 * routes.php)` from its `boot()`, mounting a COMPLETE second plan-administration API at
 * `/subscriptions/plans*` behind nothing but `['auth', 'subscriptions_plans_manage']` -- a raw
 * `PermissionManager::can('subscriptions.plans.manage')` check. Those mounts escape every seam this
 * pack exists to enforce: the `thallo.subscriptions` capability gate, `tenant_system` +
 * `content_permission:tenancy.manage` platform authority, and the {@see EngineGateway}'s structured
 * degradation. Left alone, granting one workspace admin `subscriptions.plans.manage` would let them
 * edit the GLOBAL plan catalog -- precisely what spec §3 forbids.
 *
 * Ruling: in Thallo those native routes are NOT reachable below platform authority. They are made to
 * behave as ABSENT (404, byte-identical to `Router::dispatch()`'s own unmatched-route response), which
 * matches capability-off semantics; the only plan-administration surface in this app is
 * `/v1/admin/subscriptions/plans` ({@see PlansController}), which carries the full platform gate.
 *
 * Mechanism: {@see EnginePreemptionServiceProvider} -- a dedicated, pre-extension-tier provider in
 * this pack that loads the engine's own route file AHEAD of the engine, behind
 * {@see \Thallo\Subscriptions\Http\EngineNativeRoutesDenied} and, in the same pass, re-pins
 * `SubjectResolverInterface` to this pack's own resolver. It is separate from this provider, and
 * does its work in `boot()` rather than `register()`, for reasons that are load-bearing rather than
 * stylistic -- see that class's docblock (production boots from the extension-provider cache, where
 * NO provider's `register()` runs at all, while this provider must `loadAfter()` the engine and so
 * can never win a `boot()`-time race with it).
 */
final class SubscriptionsIntegrationServiceProvider extends ServiceProvider implements DeclaresLoadOrder
{
    /**
     * Source-verified edge (modules-not-extensions spec §5.2, mirroring
     * CommerceIntegrationServiceProvider): this pack adopts glueful/subscriptions — the
     * engine's own routes and boot state must exist first.
     */
    public static function loadAfter(): array
    {
        return [\Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class];
    }

    /**
     * Post-extension tier (modules-not-extensions spec §5.2): app-integrated modules load
     * AFTER the extension universe, exactly like every other Thallo pack. Copied verbatim from
     * CommerceIntegrationServiceProvider::loadPriority().
     */
    public static function loadPriority(): int
    {
        return 100;
    }

    /**
     * Completes a container binding glueful/subscriptions' own provider assumes but never
     * supplies. Its `services()` rebinds `TierResolverInterface` to `EntitlementTierResolver`
     * (autowired) — a decorator whose constructor takes the framework's concrete
     * `\Glueful\Api\RateLimiting\TierResolver` as `$inner` and delegates to it whenever no
     * tenant/tier applies. Framework-core's `CoreProvider` only ever registers a
     * `FactoryDefinition` for the INTERFACE (building a `TierResolver` inline, never exposing it
     * under its own class id) — so with no other binding, the container has nothing to hand
     * `$inner` and every request touching rate limiting (effectively every API route) throws
     * `ContainerException: Cannot resolve parameter inner of ... EntitlementTierResolver`.
     * Verified via the full Thallo suite: enabling the engine provider alone (no binding here)
     * turned dozens of unrelated integration tests into 500s. Binding the concrete class here —
     * autowired, since its only dependency (`TierManager`) is already container-bound — mirrors
     * exactly what `CoreProvider`'s own factory constructs, just exposed under its own class id
     * so the decorator can resolve it too.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [
            \Glueful\Api\RateLimiting\TierResolver::class => [
                'class' => \Glueful\Api\RateLimiting\TierResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Bound under its OWN id, never `SubjectResolverInterface`: the DSL merge cannot win
            // that id from this provider (the engine is ordered first and `$defs += $compiled` is
            // first-wins), so the interface is re-pinned on the runtime container by
            // {@see EnginePreemptionServiceProvider::rebindSubjectResolver()} instead -- from
            // boot(), because no provider's register() runs on the cached boots production
            // requires.
            ThalloSubjectResolver::class => [
                'class' => ThalloSubjectResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Task 7 (Phase B): the lazy three-state engine access seam every later API task
            // resolves engine services through. Own id, no merge conflict. Shared purely because
            // it holds no per-request state worth avoiding reuse of -- it probes the container
            // fresh on every call, never caches a verdict (see EngineGateway's own docblock).
            EngineGateway::class => [
                'class' => EngineGateway::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Task 8 (Phase B): the platform Plans admin API's sole controller. Autowired --
            // its only dependency is the EngineGateway bound directly above.
            PlansController::class => [
                'class' => PlansController::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Task 9 (Phase B): the workspace billing admin API + meta. Both autowired --
            // TenantAdministration/SingleStoreTenant/SystemFlags are always-on bindings from
            // glueful/tenancy's control-plane provider and this pack's own TenancyServiceProvider,
            // and EngineGateway is bound directly above.
            MetaController::class => [
                'class' => MetaController::class,
                'shared' => true,
                'autowire' => true,
            ],
            WorkspaceBillingController::class => [
                'class' => WorkspaceBillingController::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Task 10 (Phase B): the pack's fail-closed tenant-purge handler. ALWAYS
            // registered -- OUTSIDE any capability gate (this provider's services() has
            // none to begin with) -- and aliased so
            // `Thallo\Tenancy\TenancyServiceProvider::makePurgeResourceRegistry()` can pick
            // it up, the exact mechanism `thallo.commerce.purge_handler` already
            // establishes. No soft-resolve is needed for its own dependencies (unlike
            // Commerce's purge handler): `EngineGateway` is THIS provider's own
            // unconditionally-bound service, and it is EngineGateway -- not this
            // factory -- that soft-resolves the engine per call (see its own docblock),
            // so the handler stays constructible even when glueful/subscriptions' own
            // provider is inactive.
            SubscriptionsPurgeHandler::class => [
                'factory' => [self::class, 'makeSubscriptionsPurgeHandler'],
                'shared'  => true,
                'alias'   => ['thallo.subscriptions.purge_handler'],
            ],
            // Final-wave fix A: the route-middleware guard {@see EnginePreemptionServiceProvider}
            // mounts the engine's OWN native plan routes behind. Bound HERE rather than on that
            // provider deliberately -- `ContainerFactory::loadExtensionDefinitions()` resolves
            // provider defs through `ProviderClassResolver` on EVERY boot, independently of
            // `ExtensionManager`'s discovery mode, so this binding exists even on the cached boots
            // where no `register()` runs. Its alias id is this pack's own (never the engine's
            // `subscriptions_plans_manage`), so no cross-provider def merge can lose it.
            EngineNativeRoutesDenied::class => [
                'class' => EngineNativeRoutesDenied::class,
                'shared' => true,
                'autowire' => true,
                'alias' => [EngineNativeRoutesDenied::ALIAS],
            ],
        ];
    }

    /** See the {@see SubscriptionsPurgeHandler::class} services() entry above. */
    public static function makeSubscriptionsPurgeHandler(ContainerInterface $container): SubscriptionsPurgeHandler
    {
        return new SubscriptionsPurgeHandler(
            $container->get(ApplicationContext::class),
            $container->get(Connection::class),
            $container->get(EngineGateway::class),
        );
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.subscriptions',
            label: 'Subscriptions',
            description: 'Workspace SaaS billing: platform plans and per-workspace subscriptions.',
        ));

        // Gated by ENABLED state (mirrors CommerceIntegrationServiceProvider::boot()): the
        // user-facing HTTP surface only. Task 8 (Phase B): the platform Plans admin API --
        // this pack's first route file, extended by Task 9.
        if ($registry->isEnabled('thallo.subscriptions')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
        }
    }
}
