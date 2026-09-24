<?php

namespace App\Http\Controllers;

use App\Services\ReadinessChecker;
use Illuminate\Http\JsonResponse;

/**
 * Infrastructure probes. Deliberately NOT wrapped in the ApiResponse envelope —
 * these are consumed by Docker/k8s/uptime monitors, which expect flat JSON.
 */
class HealthController extends Controller
{
    public function __construct(private ReadinessChecker $checker) {}

    /**
     * Liveness: is the PHP process up and able to boot the framework?
     * Must not touch any external service, or an outage elsewhere will
     * get this container killed and restarted for no reason.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: can this instance actually serve traffic — i.e. reach
     * every backing service it needs? Returns 503 when any check fails so
     * `curl -f` and orchestrators treat it as unhealthy.
     */
    public function ready(): JsonResponse
    {
        $result = $this->checker->run();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}
