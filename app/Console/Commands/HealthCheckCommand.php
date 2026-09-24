<?php

namespace App\Console\Commands;

use App\Services\ReadinessChecker;
use Illuminate\Console\Command;

/**
 * `docker exec` / Docker HEALTHCHECK friendly readiness probe. Runs entirely
 * in-process — no HTTP server, no hostname, no port. The `api` container only
 * exposes php-fpm on 9000 (FastCGI), so this is how it checks its own
 * readiness without needing an HTTP listener at all:
 *
 *   HEALTHCHECK CMD php artisan health:check
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Check that the app can reach the database, Redis, and RabbitMQ';

    public function handle(ReadinessChecker $checker): int
    {
        $result = $checker->run();

        foreach ($result['checks'] as $name => $status) {
            $status === 'ok'
                ? $this->info("{$name}: ok")
                : $this->error("{$name}: error");
        }

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
