<?php

declare(strict_types=1);

namespace Thallo\Subscriptions\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Subscriptions\Engine\EngineGateway;
use Thallo\Subscriptions\Engine\EngineUnavailableException;

/**
 * Task 8 (Phase B): the platform Plans admin API -- `GET/POST /v1/admin/subscriptions/plans`,
 * `PATCH .../plans/{key}`, `POST .../plans/{key}/archive`, `POST .../plans/import-config`. Thin
 * delegation only: every action resolves {@see EngineGateway::plans()} fresh (never cached, never
 * constructor-injected -- see the gateway's own docblock) and forwards straight to
 * {@see \Glueful\Extensions\Subscriptions\Plans\PlanManagementService}'s unqualified (platform-scope)
 * methods.
 *
 * Error mapping:
 *  - the engine not being READY (disabled or unmigrated) -> 409, {@see RespondsEngineUnavailable}.
 *  - every validation failure the engine's own {@see
 *    \Glueful\Extensions\Subscriptions\Plans\PlanPayloadValidator}/`PlanManagementService` raise
 *    (missing/malformed fields, unknown plan, duplicate plan_key, an attempted plan_key change,
 *    an illegal status transition) -> 422, carrying the upstream `\InvalidArgumentException`
 *    message VERBATIM -- the engine is the single source of truth for what is or isn't a valid
 *    plan mutation; this controller never re-derives or rewords its rules.
 */
final class PlansController
{
    use RespondsEngineUnavailable;

    public function __construct(private readonly EngineGateway $gateway)
    {
    }

    #[ApiOperation(summary: 'List platform plans', tags: ['Thallo Subscriptions'])]
    public function index(Request $request): Response
    {
        try {
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        return Response::success(['plans' => $plans->list()], 'Plans retrieved');
    }

    #[ApiOperation(summary: 'Create a platform plan', tags: ['Thallo Subscriptions'])]
    public function store(Request $request): Response
    {
        try {
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $payload = $this->jsonBody($request);
        try {
            $plan = $plans->create($payload);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return new Response(['success' => true, 'message' => 'Plan created', 'data' => $plan], 201);
    }

    #[ApiOperation(summary: 'Update a platform plan (plan_key is immutable)', tags: ['Thallo Subscriptions'])]
    public function update(Request $request, string $key): Response
    {
        try {
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $payload = $this->jsonBody($request);
        try {
            $plan = $plans->update($key, $payload);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($plan, 'Plan updated');
    }

    #[ApiOperation(summary: 'Archive a platform plan', tags: ['Thallo Subscriptions'])]
    public function archive(Request $request, string $key): Response
    {
        try {
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        try {
            $plan = $plans->archive($key);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($plan, 'Plan archived');
    }

    /**
     * Seeds the platform catalog from `subscriptions.plans` config (spec §10 -- seed data only).
     * Body is optional: `{force?: bool, status?: 'draft'|'active'|'archived'}`; omitted fields
     * default to `force: false, status: 'active'`, matching
     * {@see \Glueful\Extensions\Subscriptions\Plans\PlanManagementService::importConfig()}'s own
     * defaults.
     */
    #[ApiOperation(summary: 'Import/seed platform plans from config', tags: ['Thallo Subscriptions'])]
    public function importConfig(Request $request): Response
    {
        try {
            $plans = $this->gateway->plans();
        } catch (EngineUnavailableException $e) {
            return $this->engineUnavailable($e);
        }

        $payload = $this->jsonBody($request);
        $force = (bool) ($payload['force'] ?? false);
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : 'active';

        try {
            $imported = $plans->importConfig($force, $status);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success(['plans' => $imported], 'Plans imported');
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
