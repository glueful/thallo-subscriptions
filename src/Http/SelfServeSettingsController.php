<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Settings\SelfServeCheckoutSetting;
use Thallo\Subscriptions\Settings\SelfServeGatewayCapability;

/**
 * Task 15 (spec §5.1): `PUT /v1/admin/subscriptions/self-serve` -- the platform-only kill switch
 * for self-serve subscription checkout. Body strictly `{enabled: bool}`; a missing key, a
 * non-boolean value, or an unparsable body is 422.
 *
 * Enabling requires the configured default Payvia gateway to support `subscription_checkout`
 * ({@see SelfServeGatewayCapability}) -- refused 409 `no_capable_gateway` otherwise (e.g. this
 * app's own default gateway, Paystack, deliberately does not implement hosted subscription
 * checkout as of payvia 2.5.0). Disabling is UNCONDITIONAL: a kill switch must always be able to
 * turn itself off, even with payvia absent from the container or its gateway degraded -- `POST
 * /checkout` rechecks the switch at request time regardless (spec §5.1), so there is no safety
 * reason to gate the OFF direction on gateway health.
 */
final class SelfServeSettingsController
{
    public function __construct(
        private readonly SelfServeCheckoutSetting $setting,
        private readonly SelfServeGatewayCapability $capability,
    ) {
    }

    #[ApiOperation(summary: 'Toggle the self-serve checkout operator switch', tags: ['Thallo Subscriptions'])]
    public function update(Request $request): Response
    {
        $payload = $this->jsonBody($request);
        if (!array_key_exists('enabled', $payload) || !is_bool($payload['enabled'])) {
            return Response::error('enabled must be a boolean', 422);
        }

        if ($payload['enabled']) {
            $verdict = $this->capability->evaluate();
            if (!$verdict['capable']) {
                return Response::error(
                    'no gateway capable of subscription checkout is configured',
                    409,
                    ['code' => 'no_capable_gateway', 'reason' => $verdict['reason'], 'gateway' => $verdict['gateway']],
                );
            }
            $this->setting->enable();
        } else {
            $this->setting->disable();
        }

        return Response::success(
            ['self_serve_checkout_enabled' => $this->setting->isEnabled()],
            'Self-serve checkout switch updated',
        );
    }

    /** @return array<string,mixed> */
    private function jsonBody(Request $request): array
    {
        $content = (string) $request->getContent();
        if ($content === '') {
            return [];
        }

        return (array) json_decode($content, true);
    }
}
