<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Purge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Subscriptions\Lifecycle\SubscriptionSubjectDataPurger;
use Glueful\Extensions\Subscriptions\Subject;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Tenancy\Purge\PurgeHandler;

/**
 * Task 10 (Phase B): the pack's {@see PurgeHandler} for a hard workspace
 * deletion -- removes every subscription, override, event, and provider-event
 * receipt row under the tenant, plus the workspace's own member plan catalog
 * (`Subject::tenant()`'s hard-purge form, {@see SubscriptionSubjectDataPurger}'s
 * own docblock).
 *
 * Deliberately fail-closed, NOT the soft-degrade posture {@see EngineGateway}
 * gives every other caller. Every method starts with exactly ONE branch: does
 * `subscriptions` (the table, checked directly against the injected
 * {@see Connection}'s schema builder -- never through readiness or the
 * gateway) exist at all?
 *
 *  - NO: there is provably no subscriptions data of any shape for this
 *    tenant -- a brand-new install, or one where glueful/subscriptions was
 *    never migrated in the first place. Zero-artifact/no-op/true, without
 *    ever consulting {@see EngineGateway} -- a disabled or unmigrated engine
 *    must never turn "nothing to purge" into a thrown error.
 *  - YES: the table exists, so there COULD be tenant data in it (or in its
 *    sibling tables) -- whether that data is 1.x-shaped legacy rows, a
 *    partially-applied 2.x schema, or a complete 2.x schema whose engine
 *    happens to be disabled in THIS process, is irrelevant: all three are
 *    data-bearing, fail-closed states. `EngineGateway::purger()` MUST then
 *    resolve or every method throws -- a purge run must never report success
 *    while quietly leaving a tenant's billing data behind, which would be
 *    indistinguishable from a clean purge to every caller of this handler.
 *    `SubscriptionSchemaReadiness::isReady() === false` never means "schema
 *    absent" here; it means "schema present but not the complete minimum 2.x
 *    shape", which is exactly the case this handler must refuse to silently
 *    skip.
 */
final class SubscriptionsPurgeHandler implements PurgeHandler
{
    private const TABLE = 'subscriptions';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly EngineGateway $gateway,
    ) {
    }

    public function id(): string
    {
        return 'subscriptions';
    }

    /**
     * No dependency in either direction: subscriptions rows carry no DB
     * foreign key into any core Thallo table, and nothing else in the
     * registry references this handler's id.
     *
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function prepare(ApplicationContext $context, string $tenantUuid): array
    {
        if (!$this->hasSchema()) {
            return ['counts' => []];
        }

        return ['counts' => $this->requirePurger()->countSubjectRows(Subject::tenant($tenantUuid))];
    }

    /** @param array<string, mixed> $artifacts */
    public function purge(ApplicationContext $context, string $tenantUuid, array $artifacts): void
    {
        if (!$this->hasSchema()) {
            return;
        }

        $this->requirePurger()->purgeSubject(Subject::tenant($tenantUuid));
    }

    /** @param array<string, mixed> $artifacts */
    public function verify(ApplicationContext $context, string $tenantUuid, array $artifacts): bool
    {
        if (!$this->hasSchema()) {
            return true;
        }

        foreach ($this->requirePurger()->countSubjectRows(Subject::tenant($tenantUuid)) as $count) {
            if ($count !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ONLY thing that may produce the zero-pass (see class docblock):
     * a direct schema-builder check against the injected {@see Connection},
     * never a readiness probe and never the gateway.
     */
    private function hasSchema(): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable(self::TABLE);
    }

    /**
     * Fail-closed gate, run at the top of every branch that already proved
     * `subscriptions` exists. Data-bearing schema + no purger = refuse, with
     * the exact message every one of prepare()/purge()/verify() must throw.
     */
    private function requirePurger(): SubscriptionSubjectDataPurger
    {
        $purger = $this->gateway->purger();
        if ($purger !== null) {
            return $purger;
        }

        throw new \RuntimeException(
            'subscriptions data exists but the subscriptions engine is disabled or not ready — '
            . 'enable and migrate the extension before purging this tenant'
        );
    }
}
