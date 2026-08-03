<?php

declare(strict_types=1);

namespace Thallo\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container as GluefulContainer;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Database\Connection;
use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
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
 * Mechanism: {@see EngineRouteGuardServiceProvider} -- a dedicated, pre-extension-tier provider in
 * this pack whose only job is to load the engine's own route file AHEAD of the engine, behind
 * {@see \Thallo\Subscriptions\Http\EngineNativeRoutesDenied}. It is separate from this provider,
 * and does its work in `boot()` rather than `register()`, for reasons that are load-bearing rather
 * than stylistic -- see that class's docblock (production boots from the extension-provider cache,
 * where NO provider's `register()` runs at all, while this provider must `loadAfter()` the engine
 * and so can never win a `boot()`-time race with it).
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
            // Bound under its OWN id (never SubjectResolverInterface here — see register()'s
            // docblock for why the DSL merge can't win that id from this provider).
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
            // Final-wave fix A: the route-middleware guard {@see EngineRouteGuardServiceProvider}
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

    public function register(ApplicationContext $context): void
    {
        // Boot-order deviation (Task 6, flagged in review): a plain services()/defs() entry for
        // SubjectResolverInterface::class here CANNOT win over the engine's own DefaultSubjectResolver
        // binding, even though this provider loads AFTER the engine (loadAfter() above). Container
        // extension-defs are merged id-by-id across providers in LOAD order via `$defs += $compiled`
        // (Glueful\Container\Bootstrap\ContainerFactory::loadExtensionDefinitions -- first-registered
        // id wins); `loadAfter`/`loadPriority`(100) place this provider's services() AFTER the
        // engine's in that same merge, so the engine's id would always be registered first and ours
        // silently dropped. Proven empirically: a plain SubjectResolverInterface entry in services()
        // above resolved to Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver, not
        // ThalloSubjectResolver.
        //
        // Fix: rebind the id directly on the already-built runtime container from register(), which
        // runs AFTER ContainerFactory::create() has finished merging every provider's defs and BEFORE
        // anything resolves/caches SubjectResolverInterface as a singleton (register() itself never
        // touches it, and neither this provider's nor the engine's boot() resolves it eagerly).
        // Mirrors the framework's OWN precedent for this exact pattern -- Framework::
        // registerContextServices() re-pins ApplicationContext/RequestLifecycle post-merge the same
        // way, guarded by the same `instanceof GluefulContainer` check.
        //
        // CAVEAT (real trigger, not a hypothetical future deploy step): `ContainerFactory::create()`
        // AUTO-COMPILES the container on every single boot whenever `APP_ENV=production &&
        // !APP_DEBUG` (Glueful\Framework::buildContainer() computes `$prod` from exactly that pair,
        // then calls `ContainerFactory::create($context, $prod)`) -- there is no separate CLI
        // "container:compile" step to opt into or out of. Today that inline compile always THROWS
        // and falls back to the plain (`GluefulContainer`) container, for two reasons this pack does
        // NOT own: (1) `ContainerCompiler::compile()` rejects any `FactoryDefinition` outright, and
        // the engine registers several (`PlanCatalog`, `EntitlementResolver`, ...); (2) the compiler
        // cannot serialize the `ApplicationContext` `ValueDefinition` `ContainerFactory` itself binds
        // (see vendor `StrictLaneCompiledContainerGateTest`'s docblock for the same finding). If a
        // routine `composer update` ever fixes either upstream limitation, compilation would start
        // SUCCEEDING in production: `$this->app` would then be a `CompiledContainer`, this whole
        // `instanceof GluefulContainer` branch would silently skip, and `SubjectResolverInterface`
        // would resolve to the engine's `DefaultSubjectResolver` in production with no error anywhere.
        // `tests/Integration/Subscriptions/SubjectResolverCompiledContainerGateTest.php` guards
        // exactly this: it drives the REAL `ContainerFactory::create($context, prod: true)` path and
        // turns red the day compilation starts succeeding, pointing straight back here.
        if ($this->app instanceof GluefulContainer) {
            $this->app->load([
                SubjectResolverInterface::class => new FactoryDefinition(
                    SubjectResolverInterface::class,
                    static fn (\Psr\Container\ContainerInterface $c): ThalloSubjectResolver
                        => $c->get(ThalloSubjectResolver::class),
                ),
            ]);
        }
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
