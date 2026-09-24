<?php

namespace Tests\Feature;

use App\Services\ReadinessChecker;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthEndpointsTest extends TestCase
{
    /**
     * Swap in a checker that reports whatever we want, so these tests never
     * need a real Postgres/Redis/RabbitMQ. What's under test here is the
     * endpoint: status code, JSON shape, and no leaking of failure details.
     */
    private function fakeChecker(string $status, array $checks): void
    {
        $this->app->instance(ReadinessChecker::class, new class($status, $checks) extends ReadinessChecker
        {
            public function __construct(private string $status, private array $checks) {}

            public function run(): array
            {
                return ['status' => $this->status, 'checks' => $this->checks];
            }
        });
    }

    #[Test]
    public function health_live_returns_ok(): void
    {
        $this->getJson('/health/live')
            ->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function health_ready_returns_200_when_every_dependency_is_reachable(): void
    {
        $this->fakeChecker('ok', [
            'database' => 'ok',
            'redis' => 'ok',
            'rabbitmq' => 'ok',
        ]);

        $this->getJson('/health/ready')
            ->assertStatus(200)
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'redis' => 'ok', 'rabbitmq' => 'ok'],
            ]);
    }

    /**
     * 503 is the whole point — it is what makes `curl -f` and Docker
     * healthchecks treat the container as unhealthy.
     */
    #[Test]
    public function health_ready_returns_503_when_a_dependency_is_unreachable(): void
    {
        $this->fakeChecker('error', [
            'database' => 'ok',
            'redis' => 'ok',
            'rabbitmq' => 'error',
        ]);

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'error',
                'checks' => ['rabbitmq' => 'error'],
            ]);
    }

    /**
     * Liveness must never depend on a backing service: if it did, a database
     * outage would restart every healthy container in a loop.
     */
    #[Test]
    public function health_live_stays_ok_while_readiness_fails(): void
    {
        $this->fakeChecker('error', [
            'database' => 'error',
            'redis' => 'error',
            'rabbitmq' => 'error',
        ]);

        $this->getJson('/health/ready')->assertStatus(503);
        $this->getJson('/health/live')->assertStatus(200);
    }

    /**
     * Regression guard: these routes were originally in routes/web.php, where
     * the `web` group starts a database-backed session — making the liveness
     * probe itself depend on Postgres.
     */
    #[Test]
    public function health_routes_run_without_middleware(): void
    {
        foreach (['health/live', 'health/ready'] as $uri) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);

            $this->assertNotNull($route, "Route {$uri} is not registered");
            $this->assertSame([], $route->gatherMiddleware(), "Route {$uri} must have no middleware");
        }
    }
}
