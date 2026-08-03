<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Engine;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\SubscriptionService;

use function app;

/**
 * Final-wave fix B: the per-ACTION engine snapshot {@see EngineGateway::requireServices()} hands back
 * once the READY verdict has been established -- exactly ONE readiness probe per controller action,
 * however many engine services that action needs.
 *
 * WHY this exists: `SubscriptionSchemaReadiness::isReady()` is 5 `hasTable()` + 27 `hasColumn()`
 * uncached introspection queries, and {@see EngineGateway}'s own contract (rightly) forbids caching a
 * verdict ACROSS calls -- the engine can be enabled/migrated or rolled back by an operation this same
 * process later serves. That ruling is about STALENESS BETWEEN requests; it says nothing about a
 * single action re-asking the same question three times in a row. `WorkspaceBillingController::show()`
 * used to resolve `subscriptions()` + `overrides()` + `plans()` and pay the full probe EACH time
 * (~96 introspection queries for one HTTP request; `index()` ~64).
 *
 * The seam this class draws is therefore precise: the state is resolved ONCE, at the top of the
 * action, and this object is the proof it was READY at that moment. It caches NOTHING itself -- each
 * accessor still resolves its service from the live container -- and it is never shared, never stored
 * on the gateway, and never outlives the action that asked for it. `EngineGateway::engineState()`
 * stays per-call fresh, and `purger()` stays soft, exactly as before.
 */
final class EngineServices
{
    /**
     * @internal Constructed ONLY by {@see EngineGateway::requireServices()}, which has already
     * verified the READY state this object stands for.
     */
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function subscriptions(): SubscriptionService
    {
        return app($this->context, SubscriptionService::class);
    }

    public function plans(): PlanManagementService
    {
        return app($this->context, PlanManagementService::class);
    }

    public function overrides(): OverrideRepository
    {
        return app($this->context, OverrideRepository::class);
    }
}
