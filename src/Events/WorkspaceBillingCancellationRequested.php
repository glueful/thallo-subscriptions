<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Events;

use Glueful\Events\Contracts\BaseEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEvent;
use Glueful\Extensions\Audit\Contracts\AuditableEventDefaults;

/**
 * Task 17 (design spec §5.2's `POST /cancel` bullet: "the request and actor are written to the
 * existing audit sink"). This app has no bespoke commerce/audit table of its own -- the
 * established idiom (Task 9-era, {@see \Thallo\Commerce\Events\ProductLinkChanged}) is to
 * implement {@see AuditableEvent} on a plain domain event and dispatch it through
 * `Glueful\Events\EventService`: the `glueful/audit` extension's `AuditSubscriber` subscribes to
 * `AuditableEvent` generically (never this class by name), so dispatching this event records a
 * `billing`-category audit row with NO extra wiring in this pack, exactly like Commerce's own
 * precedent, and stays a harmless no-op (never an error) if `glueful/audit` happens to be inactive
 * in a given host.
 *
 * A `subscription_events` row is deliberately NOT used for this (unlike
 * `reserveCheckoutFor()`'s own `checkout_reservation` audit stamp) -- webhooks are the sole
 * projection authority for the subscription's OWN event log (design spec §5.2/§3), and a cancel
 * request eagerly mutates nothing there; recording it as a `subscription_events` row would
 * misrepresent an unconfirmed provider request as a projected outcome.
 *
 * Dispatched synchronously (never `db()->afterCommit()`) -- this pack makes ZERO local writes for
 * `POST /cancel` (spec §5.2: "ZERO eager mutation of the subscription row"), so there is no commit
 * to defer past.
 */
final class WorkspaceBillingCancellationRequested extends BaseEvent implements AuditableEvent
{
    use AuditableEventDefaults;

    public function __construct(
        public readonly string $workspaceUuid,
        public readonly string $actorUuid,
        public readonly string $subscriptionUuid,
        public readonly string $mode,
        public readonly string $providerGateway,
        public readonly string $providerSubscriptionId,
    ) {
        parent::__construct();
    }

    public function auditAction(): string
    {
        return 'cancellation_requested';
    }

    public function auditCategory(): string
    {
        return 'billing';
    }

    /** @return array{type:string,uuid:string} */
    public function auditTarget(): array
    {
        return ['type' => 'subscription', 'uuid' => $this->subscriptionUuid];
    }

    /** @return array<string,mixed> */
    public function auditMetadata(): array
    {
        return [
            'workspace_uuid' => $this->workspaceUuid,
            'mode' => $this->mode,
            'provider_gateway' => $this->providerGateway,
            'provider_subscription_id' => $this->providerSubscriptionId,
        ];
    }

    /** @return array{uuid:string} */
    public function auditActor(): array
    {
        return ['uuid' => $this->actorUuid];
    }
}
