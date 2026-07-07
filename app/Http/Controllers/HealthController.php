<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Quick environment check — confirms both databases are reachable.
     * Hit GET /api/health after starting the server.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'leaveforms_db' => $this->pingConnection(config('database.default')),
            'kdtphdb' => $this->pingConnection('kdtphdb'),
            'kdtphdb_new' => $this->pingConnection('kdtphdb_new'),
        ];

        $allOk = collect($checks)->every(fn (array $check) => $check['ok']);

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'checks' => $checks,
        ], $allOk ? 200 : 503);
    }

    private function pingConnection(string $name): array
    {
        try {
            $database = config("database.connections.{$name}.database");

            DB::connection($name)->getPdo();
            DB::connection($name)->select('SELECT 1 AS ok');

            return [
                'ok' => true,
                'connection' => $name,
                'database' => $database,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'connection' => $name,
                'database' => config("database.connections.{$name}.database"),
                'error' => $e->getMessage(),
            ];
        }
    }
}
