<?php

declare(strict_types=1);

namespace Thallo\Subscriptions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

use function app;

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
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // Scaffold only — no bindings yet. Later tasks add the resolver/gateway/APIs.
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.subscriptions',
            label: 'Subscriptions',
            description: 'Workspace SaaS billing: platform plans and per-workspace subscriptions.',
        ));
    }
}
