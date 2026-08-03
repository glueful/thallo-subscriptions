<?php

declare(strict_types=1);

namespace Thallo\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container as GluefulContainer;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ServiceProvider;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Http\PlanController as EnginePlanController;
use Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider as EngineServiceProvider;
use Glueful\Routing\Router;
use Thallo\Subscriptions\Http\EngineNativeRoutesDenied;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;

/**
 * The pack's PRE-ENGINE seam: the two things Thallo must do to glueful/subscriptions BEFORE that
 * engine's own provider boots, neither of which can live on the pack's main provider.
 *
 *  1. **Re-pin `SubjectResolverInterface` to {@see ThalloSubjectResolver}.** The engine binds that id
 *     through its own `services()` DSL, and `ContainerFactory::loadExtensionDefinitions()` merges
 *     provider defs id-by-id with `$defs += $compiled` in resolver order — first-registered wins, and
 *     the pack's main provider is ordered AFTER the engine (it `loadAfter()`s it), so a `services()`
 *     entry there is silently dropped (proven empirically during Task 6: the id resolved to the
 *     engine's `DefaultSubjectResolver`). The only way to win the id is to rebind it on the
 *     already-built runtime container — the framework's own precedent for exactly this
 *     (`Framework::registerContextServices()` re-pins `ApplicationContext`/`RequestLifecycle`
 *     post-merge the same way, behind the same `instanceof GluefulContainer` guard).
 *  2. **Deny the engine's native `/subscriptions/plans*` mounts** (the platform-authority ruling
 *     documented on {@see SubscriptionsIntegrationServiceProvider}): its `boot()` unconditionally
 *     calls `loadRoutesFrom(vendor/glueful/subscriptions/routes.php)`, mounting a complete second
 *     plan-administration API behind nothing but `['auth', 'subscriptions_plans_manage']` — outside
 *     the `thallo.subscriptions` capability gate, outside `tenant_system` +
 *     `content_permission:tenancy.manage`, and outside the `EngineGateway`. The only way to make those
 *     mounts harmless is to LOAD THAT FILE FIRST, inside a router group whose first middleware is
 *     {@see EngineNativeRoutesDenied}: `ServiceProvider::loadRoutesFrom()` latches loaded route files
 *     by realpath in a process-global `self::$loadedRoutes` shared across every subclass, so the
 *     engine's own call then becomes a silent no-op and the routes exist exactly once — ours, with the
 *     guard ahead of the engine's `auth`.
 *
 * WHY A SEPARATE PROVIDER, AND WHY `boot()` RATHER THAN `register()`:
 *
 *  - `register()` IS DEAD CODE IN PRODUCTION. `ExtensionManager::discover()` returns immediately on a
 *    cache hit (`loadFromCache()` non-null) and never calls `registerProviders()`, while
 *    `ExtensionManager::boot()` runs for every provider either way. Production REQUIRES that cache
 *    (`discover()` otherwise throws "Extension cache missing in production. Run: php glueful
 *    extensions:cache") and `EXTENSIONS_CACHE_TTL_PROD` defaults to `PHP_INT_MAX` — so in the
 *    mandatory production boot mode NO provider's `register()` runs at all. BOTH jobs above were
 *    originally done from the main provider's `register()`, which meant that in production the native
 *    routes stayed ungated AND every subject silently validated through the engine's
 *    `DefaultSubjectResolver`.
 *  - The main provider's `boot()` is too late for job 2 and unprovable for job 1: that provider must
 *    `loadAfter()` the engine (it adopts the engine's services) and sits in the post-extension tier
 *    (`loadPriority() === 100`), so the engine has already booted — and already registered its routes.
 *    `Route::middleware()` only appends, so a late guard would run BEHIND `auth` and the engine's own
 *    permission check (401 for anonymous probes) instead of being absent; and the rebind would sit
 *    behind ~20 other providers' `boot()`s, any one of which could have resolved (and singleton-cached)
 *    `SubjectResolverInterface` first.
 *
 * Hence: a provider whose ONLY declaration is "boot before the engine" ({@see self::loadPriority()}),
 * with no `loadAfter()` edge and no services of its own, doing both jobs from `boot()` — the one
 * lifecycle hook `ExtensionManager` runs unconditionally in BOTH discovery modes, and (at this
 * priority) the FIRST provider boot in the process, so nothing can have resolved the resolver id
 * before it. Audited at the time of writing: nothing in the framework, this app, or the engine
 * resolves `SubjectResolverInterface`/`SubscriptionService` during any `register()`/`boot()` — every
 * consumer (the engine's own service factories, its console commands, this pack's `EngineGateway`) is
 * lazy, resolving at request/command time. The pack's main provider keeps every service and route it
 * already owned.
 */
final class EnginePreemptionServiceProvider extends ServiceProvider implements DeclaresLoadOrder
{
    /**
     * PRE-extension tier. Everything else in this app sits at 0 (composer extensions, including
     * `glueful/subscriptions` itself, which declares no load order at all) or 100 (every Thallo
     * module). `ProviderOrderer::order()` seeds by priority ASC before applying `loadAfter()` edges,
     * and that ONE ordering feeds every phase — container compilation, live discovery, cache
     * generation and cached boot alike — so a negative priority is the portable way to guarantee
     * this provider boots before the engine in whichever mode the host happens to be in.
     */
    public static function loadPriority(): int
    {
        return -100;
    }

    /** Deliberately EMPTY: an edge to the engine is exactly what this provider must not have. */
    public static function loadAfter(): array
    {
        return [];
    }

    public function boot(ApplicationContext $context): void
    {
        $this->rebindSubjectResolver();
        $this->denyEngineNativePlanRoutes();
    }

    /**
     * Job 1 — see the class docblock for why the id cannot be won through `services()` and why this
     * must run here rather than in any `register()`.
     *
     * CAVEAT (a real trigger, not a hypothetical deploy step): `ContainerFactory::create()`
     * AUTO-COMPILES the container on every boot whenever `APP_ENV=production && !APP_DEBUG`
     * (`Framework::buildContainer()` computes `$prod` from exactly that pair) — there is no separate
     * CLI "container:compile" step to opt into or out of. Today that inline compile always THROWS and
     * falls back to the plain `GluefulContainer`, for two reasons this pack does not own:
     * `ContainerCompiler::compile()` rejects any `FactoryDefinition` outright (the engine registers
     * several), and it cannot serialize the `ApplicationContext` `ValueDefinition` `ContainerFactory`
     * itself binds. If a routine `composer update` ever fixes either upstream limitation, compilation
     * would start SUCCEEDING in production, `$this->app` would be a `CompiledContainer`, this branch
     * would silently skip, and `SubjectResolverInterface` would revert to the engine's
     * `DefaultSubjectResolver` with no error anywhere.
     * `tests/Integration/Subscriptions/SubjectResolverCompiledContainerGateTest.php` guards exactly
     * that: it drives the REAL `ContainerFactory::create($context, prod: true)` path and turns red the
     * day compilation starts succeeding, pointing straight back here.
     */
    private function rebindSubjectResolver(): void
    {
        if (!$this->app instanceof GluefulContainer) {
            return;
        }

        // Nothing to re-pin when the engine provider is inactive: the id is not bound at all, and
        // binding it would advertise an engine contract this boot cannot honour.
        if (!$this->app->has(SubjectResolverInterface::class)) {
            return;
        }

        $this->app->load([
            SubjectResolverInterface::class => new FactoryDefinition(
                SubjectResolverInterface::class,
                static fn (\Psr\Container\ContainerInterface $c): ThalloSubjectResolver
                    => $c->get(ThalloSubjectResolver::class),
            ),
        ]);
    }

    /**
     * Job 2 — loads the engine's own route file ahead of the engine, wrapped in the deny guard.
     *
     * Because we load the ENGINE's file rather than restating its paths, any route a future engine
     * release adds is covered automatically, and there is no hardcoded path list here to drift.
     *
     * REJECTED alternatives (each verified against framework source, not assumed):
     *  - Re-registering the same paths from this pack's own route file: `Router::add()` THROWS
     *    `LogicException("Route already defined")` for a duplicate STATIC route unless routes came
     *    from cache, so behaviour would differ between a warm and a cold route cache.
     *  - Appending a guard to the already-registered `Route` objects: `Route::middleware()` appends
     *    only, and there is no public setter for a route's handler or middleware list, so `auth` and
     *    the engine's permission check would still run first.
     *  - Rebinding the `subscriptions_plans_manage` middleware alias on the container: the engine
     *    binds it through its `services()` DSL, and `ContainerFactory::loadExtensionDefinitions()`
     *    merges provider defs id-by-id with `$defs += $compiled` in resolver order — the engine is
     *    resolved first, so its binding always wins and ours would be silently dropped.
     *  - An engine-provided opt-out: there is none; `boot()` calls `loadRoutesFrom()`
     *    unconditionally, and the vendor package is off-limits here.
     *
     * SKIPPED when the engine provider is inactive: nothing to pre-empt (its `boot()` never loads
     * the file, so the paths are already absent), and — load-bearing —
     * `Router::executeWithMiddleware()` resolves EVERY middleware in a matched route's stack from
     * the container BEFORE running any of them, so registering the engine's file while its
     * `subscriptions_plans_manage` alias is unbound would turn those paths into 500s instead of
     * 404s. `PlanController`'s binding is the live probe: container definitions are loaded through
     * `ProviderClassResolver` on every boot (never from the extension-provider cache), so it is
     * an accurate, mode-independent signal of whether the engine provider is active.
     */
    private function denyEngineNativePlanRoutes(): void
    {
        if (!class_exists(EngineServiceProvider::class) || !$this->app->has(Router::class)) {
            return;
        }

        if (!$this->app->has(EnginePlanController::class)) {
            return;
        }

        $providerFile = (new \ReflectionClass(EngineServiceProvider::class))->getFileName();
        if ($providerFile === false) {
            return;
        }

        // Mirrors the engine's own `loadRoutesFrom(__DIR__ . '/../routes.php')` from src/.
        $routesFile = dirname($providerFile, 2) . '/routes.php';
        if (!is_file($routesFile)) {
            return;
        }

        $router = $this->app->get(Router::class);
        if (!$router instanceof Router) {
            return;
        }

        $router->group(
            ['middleware' => [EngineNativeRoutesDenied::ALIAS]],
            function (Router $router) use ($routesFile): void {
                $this->loadRoutesFrom($routesFile);
            },
        );
    }
}
