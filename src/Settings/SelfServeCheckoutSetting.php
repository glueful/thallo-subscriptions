<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Settings;

use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 15 (Phase C, workspace self-serve checkout plan, spec §5.1): the pack-owned kill switch
 * for self-serve subscription checkout, `subscriptions.self_serve_checkout_enabled`, stored in
 * the platform-scoped {@see SystemFlags} store -- readable before tenant resolution, exactly
 * like `tenancy.enabled`. A missing row (fresh/upgraded install) or any stored value other than
 * the literal `'1'` reads as OFF; `enable()`/`disable()` write the canonical `'1'`/`'0'` strings
 * SystemFlags' own boolean-ish keys compare against (see `SystemFlags::tenancyEnabled()`'s
 * identical `=== '1'` idiom).
 */
final class SelfServeCheckoutSetting
{
    private const KEY = 'subscriptions.self_serve_checkout_enabled';

    public function __construct(private readonly SystemFlags $flags)
    {
    }

    public function isEnabled(): bool
    {
        return $this->flags->get(self::KEY) === '1';
    }

    public function enable(): void
    {
        $this->flags->put(self::KEY, '1');
    }

    public function disable(): void
    {
        $this->flags->put(self::KEY, '0');
    }
}
