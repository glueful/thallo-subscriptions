<?php

declare(strict_types=1);

namespace Thallo\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ServiceProvider;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Billing\PlanCheckoutUrlResolver;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Subscriptions\Bridge\AdminBillingPlanCheckoutUrlResolver;
use Thallo\Subscriptions\Checkout\PayviaCheckoutGateway;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Http\EngineNativeRoutesDenied;
use Thallo\Subscriptions\Http\MetaController;
use Thallo\Subscriptions\Http\PlansController;
use Thallo\Subscriptions\Http\SelfBillingController;
use Thallo\Subscriptions\Http\SelfServeSettingsController;
use Thallo\Subscriptions\Http\WorkspaceBillingController;
use Thallo\Subscriptions\Purge\SubscriptionsPurgeHandler;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;

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
            // and EngineGateway is bound directly above. Task 15 additionally wires
            // SelfServeCheckoutSetting/SelfServeGatewayCapability (bound below), which
            // MetaController now also depends on to expose the switch + capability state.
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
            // Task 15 (Phase C, workspace self-serve checkout plan, spec §5.1): the
            // `self_serve_checkout_enabled` operator kill switch. SelfServeCheckoutSetting's only
            // dependency is the always-on SystemFlags binding above; SelfServeGatewayCapability's
            // is the always-on ApplicationContext -- both autowire cleanly regardless of whether
            // payvia's own provider is active (it soft-probes the container per call, per its own
            // docblock). SelfServeSettingsController depends on both.
            SelfServeCheckoutSetting::class => [
                'class' => SelfServeCheckoutSetting::class,
                'shared' => true,
                'autowire' => true,
            ],
            SelfServeGatewayCapability::class => [
                'class' => SelfServeGatewayCapability::class,
                'shared' => true,
                'autowire' => true,
            ],
            SelfServeSettingsController::class => [
                'class' => SelfServeSettingsController::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Task 16 (Phase C, workspace self-serve checkout plan, spec §5.2), fix round
            // (code review, Critical C1): the lazy soft-resolution seam over Payvia's checkout
            // ledger, mirroring EngineGateway exactly. Its only dependency is the always-on
            // ApplicationContext -- never Payvia's own services directly -- so it autowires
            // cleanly whether or not glueful/payvia's provider is active; `isAvailable()` is
            // what tells callers whether it actually is.
            PayviaCheckoutGateway::class => [
                'class' => PayviaCheckoutGateway::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The workspace-scoped self-serve billing API's sole controller
            // (`/v1/admin/billing/{meta,checkout}`). Autowired -- EVERY dependency here is
            // unconditionally constructible regardless of whether glueful/payvia,
            // glueful/subscriptions, or glueful/users are enabled (see this controller's own
            // docblock, fix round C1): EngineGateway/SelfServeGatewayCapability/
            // PayviaCheckoutGateway all soft-probe their extension per call, `AdminUrlProvider`
            // is an established nullable soft dependency (thallo-render's own idiom for the same
            // contract), and `UserRepository` is resolved lazily inside the controller rather
            // than constructor-injected. `WorkspaceCheckoutCoordinator` is deliberately NOT bound
            // here at all -- see its own docblock for why it is built with `new` per request
            // instead.
            SelfBillingController::class => [
                'class' => SelfBillingController::class,
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
            // Task 18 (Phase C, spec §5.4): the pricing-blocks -> billing deep-link bridge,
            // bound under the CONTRACT id (never this pack's own class id) so thallo-render's
            // soft `has(PlanCheckoutUrlResolver::class)` probe picks it up without importing
            // this pack. Own id -- no merge conflict possible. Holds no constructor
            // dependencies at all (see its own docblock: every collaborator is resolved off
            // the ApplicationContext passed to resolve(), per call), so it autowires
            // trivially regardless of whether payvia, glueful/subscriptions, or thallo-render
            // itself are active.
            PlanCheckoutUrlResolver::class => [
                'class' => AdminBillingPlanCheckoutUrlResolver::class,
                'shared' => true,
                'autowire' => true,
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
            owningPackage: 'glueful/subscriptions',
        ));

        // Gated by ENABLED state (mirrors CommerceIntegrationServiceProvider::boot()): the
        // user-facing HTTP surface only. Task 8 (Phase B): the platform Plans admin API --
        // this pack's first route file, extended by Task 9.
        if ($registry->isEnabled('thallo.subscriptions')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
            // Task 16 (Phase C, spec §5.2): the workspace-scoped self-serve billing API. Same
            // capability gate as the platform admin routes above -- capability off means BOTH
            // route files are entirely absent (404).
            $this->loadRoutesFrom(__DIR__ . '/../routes/billing-routes.php');
        }

        // Task 17 (design spec §3.8/§5.2): the platform-authority `subscriptions:checkout:resolve`
        // console command, discovered OUTSIDE the `thallo.subscriptions` capability gate --
        // mirrors thallo-tenancy's/thallo-commerce's own established `discoverCommands()`
        // convention for maintenance/operator surfaces (see their own `boot()` methods): a
        // console command is never mounted through routes at all, so it has no capability-off
        // 404 to preserve, and every command class in this pack's `Console/` directory resolves
        // ITS OWN dependencies lazily inside `execute()` (never eagerly here), so `php glueful
        // ...` stays safe to run even when this pack's capability is off or glueful/payvia/
        // glueful/subscriptions happen to be inactive.
        $this->discoverCommands('Thallo\\Subscriptions\\Console', __DIR__ . '/Console');
    }
}
