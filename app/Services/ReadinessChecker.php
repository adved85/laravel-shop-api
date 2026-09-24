<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Shared by the HTTP readiness endpoint (App\Http\Controllers\HealthController)
 * and the console healthcheck (App\Console\Commands\HealthCheckCommand), so
 * "is this instance ready" is defined exactly once.
 */
class ReadinessChecker
{
    /** Seconds to wait on a dependency before calling it unreachable. */
    private const TIMEOUT = 3.0;

    /**
     * @return array{status: 'ok'|'error', checks: array<string, 'ok'|'error'>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::connection()->getPdo()),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
            'rabbitmq' => $this->check('rabbitmq', fn () => $this->pingRabbitMq()),
        ];

        return [
            'status' => in_array('error', $checks, true) ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /**
     * The reason for a failure is logged, never returned to the caller: the
     * HTTP endpoint is public, and exception messages carry hostnames, ports
     * and credentials.
     */
    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            Log::warning("Health check failed: {$name}", ['exception' => $e->getMessage()]);

            return 'error';
        }
    }

    private function pingRabbitMq(): void
    {
        $host = config('queue.connections.rabbitmq.hosts.0');

        $connection = new AMQPStreamConnection(
            $host['host'],
            $host['port'],
            $host['user'],
            $host['password'],
            $host['vhost'],
            insist: false,
            login_method: 'AMQPLAIN',
            locale: 'en_US',
            connection_timeout: self::TIMEOUT,
            read_write_timeout: self::TIMEOUT,
        );

        $connection->close();
    }
}
