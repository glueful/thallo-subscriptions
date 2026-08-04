<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Checkout;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;

use function app;
use function db;

/**
 * Fix round (code review, Critical C1): the lazy three-state-style access seam for Payvia's
 * checkout ledger, mirroring {@see \Thallo\Subscriptions\Engine\EngineGateway}'s own contract
 * EXACTLY -- `SelfBillingController`/`WorkspaceCheckoutCoordinator` must stay constructible (so
 * `GET /meta` can answer 200) and must degrade to a structured 409 (never a 500) even when
 * `glueful/payvia`'s own provider is not active in this host, or the specific checkout-ledger
 * services it publishes are absent.
 *
 * Deliberately holds ONLY the `ApplicationContext` -- no Payvia service is constructor-injected
 * anywhere else in this pack. `isAvailable()` probes the container fresh on every call (never
 * cached, never latched at construction) -- an operator can enable/disable the payvia extension
 * mid-process. The four services checked here are ALL published by `PayviaServiceProvider::
 * services()` together, so checking their joint presence is equivalent to checking whether that
 * provider is active at all.
 *
 * Fix round 2 (code review, Important I8 residual): `isAvailable()` ALSO probes the ledger's two
 * OWN tables directly (`hasTable()`, never a fatal query against a missing table) -- the provider
 * being bound only proves `glueful/payvia` itself is active, not that its checkout-ledger
 * migrations have actually run against THIS database. A provider-bound-but-unmigrated host would
 * otherwise have every accessor here throw a raw DB "relation does not exist" error on first use
 * instead of the structured `payvia_unavailable` 409 the controller is supposed to answer.
 * Mirrors {@see \Glueful\Extensions\Subscriptions\Schema\SubscriptionSchemaReadiness}'s own
 * never-fatal idiom: ONE try/catch around the whole probe sequence, any thrown DB error (a lost
 * connection, a locked database, an unsupported driver) resolves to unavailable, never propagates.
 */
final class PayviaCheckoutGateway
{
    public const REASON_EXTENSION_UNAVAILABLE = 'payvia_extension_unavailable';
    public const REASON_SCHEMA_NOT_READY = 'payvia_checkout_schema_not_ready';

    /** @var list<string> the checkout ledger's own tables (design spec §3.3). */
    private const REQUIRED_TABLES = [
        'subscription_checkout_originations',
        'subscription_checkout_subject_guards',
    ];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * The specific reason {@see self::isAvailable()} is false, or null when it is true. The
     * controller keeps ONE public 409 code (`payvia_unavailable`) for every unavailable case --
     * this distinguishes "the extension isn't bound at all" from "it's bound but its ledger
     * tables aren't migrated yet" in the response's `reason` detail, for operator diagnosis,
     * without multiplying the caller-facing code vocabulary.
     */
    public function unavailableReason(): ?string
    {
        $container = $this->context->getContainer();

        if (
            !$container->has(CheckoutOriginationRepository::class)
            || !$container->has(CheckoutSubjectGuardRepository::class)
            || !$container->has(SubscriptionCheckoutService::class)
            || !$container->has(PayviaTenantResolver::class)
        ) {
            return self::REASON_EXTENSION_UNAVAILABLE;
        }

        try {
            $schema = db($this->context)->getSchemaBuilder();
            foreach (self::REQUIRED_TABLES as $table) {
                if (!$schema->hasTable($table)) {
                    return self::REASON_SCHEMA_NOT_READY;
                }
            }

            return null;
        } catch (\Throwable) {
            return self::REASON_SCHEMA_NOT_READY;
        }
    }

    /** @throws PayviaUnavailableException when {@see self::isAvailable()} is false. */
    public function originations(): CheckoutOriginationRepository
    {
        $this->requireAvailable();

        return app($this->context, CheckoutOriginationRepository::class);
    }

    /** @throws PayviaUnavailableException when {@see self::isAvailable()} is false. */
    public function guards(): CheckoutSubjectGuardRepository
    {
        $this->requireAvailable();

        return app($this->context, CheckoutSubjectGuardRepository::class);
    }

    /** @throws PayviaUnavailableException when {@see self::isAvailable()} is false. */
    public function checkoutService(): SubscriptionCheckoutService
    {
        $this->requireAvailable();

        return app($this->context, SubscriptionCheckoutService::class);
    }

    /** @throws PayviaUnavailableException when {@see self::isAvailable()} is false. */
    public function tenantResolver(): PayviaTenantResolver
    {
        $this->requireAvailable();

        return app($this->context, PayviaTenantResolver::class);
    }

    /** The tenant scope Payvia's OWN ledger rows are stamped/read under for THIS request. */
    public function tenantUuid(): string
    {
        return $this->tenantResolver()->tenantUuid($this->context);
    }

    private function requireAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new PayviaUnavailableException();
        }
    }
}
