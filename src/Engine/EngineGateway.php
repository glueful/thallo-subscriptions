<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Engine;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger;
use Glueful\Extensions\Subscriptions\Plans\PlanManagementService;
use Glueful\Extensions\Subscriptions\Repositories\OverrideRepository;
use Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness;
use Glueful\Extensions\Subscriptions\SubscriptionService;

use function app;

/**
 * Task 7 (Phase B): the lazy three-state engine access seam every later API
 * task resolves engine services through, so Thallo surfaces degrade
 * gracefully instead of 500ing when glueful/subscriptions is off or its
 * schema isn't migrated.
 *
 * Deliberately holds ONLY the `ApplicationContext` -- no engine service is
 * constructor-injected, ANYWHERE. Degraded mode is lazy all the way: every
 * accessor probes the container fresh, per call, rather than caching a
 * verdict computed once at construction (when the engine might not have been
 * ready yet) or trusting a state that could go stale mid-process. Registered
 * shared in the provider purely because it holds no per-request state worth
 * avoiding reuse of -- NOT because state is cached between calls.
 *
 * Three states, in probe order:
 *  - DISABLED: the container doesn't even have `SubscriptionService::class`
 *    bound -- glueful/subscriptions' own provider isn't in
 *    config/extensions.php's enabled list.
 *  - SCHEMA_NOT_READY: the engine provider IS bound, but its own
 *    {@see SubscriptionSchemaReadiness} authority reports the live database
 *    doesn't (yet, or no longer) have the complete minimum 2.x runtime shape.
 *  - READY: both checks pass; every accessor may resolve real engine
 *    services from the container.
 */
final class EngineGateway
{
    public const DISABLED = 'engine_disabled';
    public const SCHEMA_NOT_READY = 'schema_not_ready';
    public const READY = 'ready';

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * Probes the container fresh on every call -- never cached. See the
     * class docblock for why: a construction-time snapshot could be wrong
     * for the rest of the process (the engine could be enabled/migrated, or
     * disabled/rolled back, by an operation this same process later serves).
     */
    public function engineState(): string
    {
        if (!$this->context->getContainer()->has(SubscriptionService::class)) {
            return self::DISABLED;
        }

        if (!app($this->context, SubscriptionSchemaReadiness::class)->isReady()) {
            return self::SCHEMA_NOT_READY;
        }

        return self::READY;
    }

    /**
     * The single-probe-per-ACTION entry point (final-wave fix B): resolves the engine state ONCE and
     * hands back an {@see EngineServices} snapshot whose accessors never re-probe. Controllers that
     * need more than one engine service in a single action MUST use this instead of calling
     * `subscriptions()`/`plans()`/`overrides()` repeatedly -- each of those pays a full
     * {@see SubscriptionSchemaReadiness} probe (5 `hasTable()` + 27 `hasColumn()` uncached queries).
     *
     * This does NOT cache across calls or across requests: every `requireServices()` call probes
     * fresh, exactly like the individual accessors do. See {@see EngineServices}' docblock for why
     * that is the whole and only relaxation of this class's no-caching rule.
     *
     * @throws EngineUnavailableException when the engine is disabled or its schema isn't ready.
     */
    public function requireServices(): EngineServices
    {
        $this->requireReady();

        return new EngineServices($this->context);
    }

    /**
     * Single-service convenience for actions that need exactly ONE engine service (and therefore
     * already pay exactly one probe). Kept as the gateway's original public surface.
     */
    public function subscriptions(): SubscriptionService
    {
        $this->requireReady();

        return app($this->context, SubscriptionService::class);
    }

    public function plans(): PlanManagementService
    {
        $this->requireReady();

        return app($this->context, PlanManagementService::class);
    }

    public function overrides(): OverrideRepository
    {
        $this->requireReady();

        return app($this->context, OverrideRepository::class);
    }

    /**
     * Soft accessor for Task 10 (host-neutral subject/tenant data purge):
     * returns null rather than throwing when the engine isn't fully ready,
     * so a purge sweep can simply skip the subscriptions resource instead of
     * failing the whole operation.
     */
    public function purger(): ?SubscriptionSubjectDataPurger
    {
        if ($this->engineState() !== self::READY) {
            return null;
        }

        return app($this->context, SubscriptionSubjectDataPurger::class);
    }

    private function requireReady(): void
    {
        $state = $this->engineState();
        if ($state !== self::READY) {
            throw new EngineUnavailableException($state);
        }
    }
}
