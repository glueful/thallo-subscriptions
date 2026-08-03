<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Http\Response;
use Thallo\Subscriptions\Engine\EngineUnavailableException;

/**
 * Task 8 (Phase B): the one structured-409 shape every admin controller resolving
 * {@see \Thallo\Subscriptions\Engine\EngineGateway} renders when the engine isn't ready --
 * `{code: 'engine_disabled'|'schema_not_ready'}` under `error.details`, taken verbatim from
 * {@see EngineUnavailableException::$state}. Extracted here so this task's `PlansController` and
 * Task 9's controller(s) share ONE rendering, never two independently-drifting copies.
 */
trait RespondsEngineUnavailable
{
    private function engineUnavailable(EngineUnavailableException $e): Response
    {
        return Response::error('subscriptions engine unavailable', 409, ['code' => $e->state]);
    }
}
