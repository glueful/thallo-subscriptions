<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Engine;

/**
 * Thrown by every {@see EngineGateway} accessor (except `purger()`, which
 * degrades to null instead) whenever `engineState()` is not `READY`.
 * `$state` carries the exact reason -- `EngineGateway::DISABLED` or
 * `EngineGateway::SCHEMA_NOT_READY` -- so callers can surface a precise,
 * actionable degraded-mode response instead of a generic 500.
 */
final class EngineUnavailableException extends \RuntimeException
{
    public function __construct(public readonly string $state)
    {
        parent::__construct("subscriptions engine unavailable: {$state}");
    }
}
