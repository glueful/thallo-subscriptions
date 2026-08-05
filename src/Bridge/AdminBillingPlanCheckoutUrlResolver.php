<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Billing\PlanCheckoutUrlResolver;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Settings\AdminUrlProvider;
use Thallo\Subscriptions\Engine\EngineGateway;

/**
 * Task 18 (Phase C, workspace self-serve checkout plan, spec §5.4): the pricing-blocks →
 * billing deep-link bridge. Bound under the CONTRACT id ({@see PlanCheckoutUrlResolver})
 * in {@see \Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider::services()} so
 * thallo-render's soft `has()` probe — its own established idiom for every optional
 * cross-pack contract (media()/site_logo()/form_render()/shop_*()) — can consult it
 * without ever importing this pack.
 *
 * Deliberately holds NO constructor dependencies: every collaborator is resolved off the
 * PASSED `$context`, per call, matching §5.4's exact interface shape
 * (`resolve(ApplicationContext $context, ...)`) — the CALLER's context is what must
 * decide the answer, never whatever context this shared singleton happened to be
 * constructed with. Mirrors {@see EngineGateway}'s own "never cache a verdict" rule one
 * level further: this class caches nothing at all, not even a context reference — every
 * probe (capability, engine, admin origin) is a fresh, per-call container read.
 *
 * Null on any of three independently-checked conditions (spec §6 failure matrix):
 *  - `thallo.subscriptions` capability off ({@see CapabilityRegistry}) — mirrors every
 *    other capability-gated surface in this pack.
 *  - the engine unavailable ({@see EngineGateway::engineState()} !== READY) — disabled
 *    provider or unmigrated schema, exactly like every other engine-backed accessor.
 *  - no configured admin origin ({@see AdminUrlProvider}, itself an established
 *    NULLABLE soft dependency — thallo-render's own idiom for the same contract, per
 *    {@see \Thallo\Subscriptions\Http\SelfBillingController}'s own docblock).
 *
 * Makes NO existence/purchasability promise about `$planKey` (spec §5.4): a well-formed
 * but unknown key still gets a deep link — the billing page revalidates everything
 * (including whether the plan exists) before checkout, rendering `plan_not_purchasable`
 * for keys that don't. Adding a render-time catalog query here would defeat the entire
 * point of a deep-link-only bridge.
 */
final class AdminBillingPlanCheckoutUrlResolver implements PlanCheckoutUrlResolver
{
    public function resolve(ApplicationContext $context, string $planKey): ?string
    {
        $container = $context->getContainer();

        if (
            !$container->has(CapabilityRegistry::class)
            || !$container->get(CapabilityRegistry::class)->isEnabled('thallo.subscriptions')
        ) {
            return null;
        }

        if (
            !$container->has(EngineGateway::class)
            || $container->get(EngineGateway::class)->engineState() !== EngineGateway::READY
        ) {
            return null;
        }

        $adminUrl = $container->has(AdminUrlProvider::class)
            ? $container->get(AdminUrlProvider::class)->adminUrl()
            : null;
        if ($adminUrl === null || $adminUrl === '') {
            return null;
        }

        return rtrim($adminUrl, '/') . '/billing?plan=' . rawurlencode($planKey);
    }
}
