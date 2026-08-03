<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Resolver;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Subject;
use Glueful\Extensions\Subscriptions\SubjectType;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Task 6 (thallo-subscriptions spec §4): the tenant-only host binding of the engine's
 * `SubjectResolverInterface` seam. `currentTenant()` delegates SOLELY to
 * {@see SingleStoreTenant::resolve()} -- that class already owns BOTH tenancy-enabled
 * current-context resolution (via CurrentTenantResolver) and tenancy-off default-workspace
 * resolution; this class must never re-implement that branching.
 *
 * Membership (`user` subjects) stays inert: `currentUser()` always returns `null` and
 * `validate()` always rejects a `user` subject, exactly like the engine's own
 * {@see \Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver} -- Thallo has no
 * per-user membership product yet, so binding this resolver must not silently enable one.
 *
 * `validate()` proves a `tenant` self-subject against the REAL tenant authority
 * ({@see TenantAdministration::getTenant()}), never a coherent-shape check alone: a
 * well-formed but nonexistent tenant uuid must fail. `TenantAdministration` is a mandatory,
 * non-nullable constructor dependency (bound unconditionally by the always-on
 * `TenancyControlPlaneProvider`) -- there is deliberately no shape-only fallback for when it
 * is unavailable, so a subject can never be waved through on shape alone.
 *
 * Per-call `ApplicationContext`, not the constructor-injected one, drives every method here:
 * `currentTenant()`/`currentUser()` never touch context at all (the former delegates to
 * `SingleStoreTenant`, which carries its OWN injected context; the latter is unconditionally
 * `null`), and `validate()` passes the CALLER's `$context` straight through to
 * `TenantAdministration::getTenant()`. `$context` is still accepted in the constructor (the
 * brief's mandated shape) but deliberately unused for per-call work: under a multi-boot test
 * harness (e.g. `AppTestCase::bootAppWithConfigOverride()`, which boots a SECOND
 * `ApplicationContext`/container in the same process), a resolver instance built against one
 * boot must resolve tenant authority against whichever context the CALLER hands it for that
 * call, not silently pin itself to the context it happened to be constructed with.
 */
final class ThalloSubjectResolver implements SubjectResolverInterface
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SingleStoreTenant $singleStore,
        private readonly TenantAdministration $tenants,
    ) {
    }

    public function currentTenant(ApplicationContext $context): ?string
    {
        return $this->singleStore->resolve();
    }

    public function currentUser(ApplicationContext $context): ?string
    {
        return null;
    }

    public function validate(ApplicationContext $context, Subject $subject): bool
    {
        if ($subject->type !== SubjectType::TENANT) {
            return false;
        }

        if ($subject->uuid === '' || $subject->uuid !== $subject->tenantUuid) {
            return false;
        }

        return $this->tenants->getTenant($context, $subject->uuid) !== null;
    }
}
