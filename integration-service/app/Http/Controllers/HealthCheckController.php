<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthCheckController
{
    /**
     * Liveness probe — is the process alive and responding?
     *
     * Should NOT depend on external services.
     * If this fails, the container should be restarted.
     */
    public function liveness(): JsonResponse
    {
        return response()->json([
            'status'  => 'alive',
            'service' => 'integration-service',
            'time'    => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness probe — is the service ready to process messages?
     *
     * Checks that critical dependencies are reachable.
     * If this fails, traffic should be diverted but the container should NOT be restarted.
     */
    public function readiness(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database connectivity
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'failed: ' . $e->getMessage();
            $healthy = false;
        }

        // SQS connectivity (optional — don't fail readiness for SQS in dev)
        try {
            $sqsEndpoint = config('services.sqs.endpoint', '');
            if (! empty($sqsEndpoint)) {
                $client = new \Aws\Sqs\SqsClient([
                    'region'      => config('services.sqs.region', 'us-east-1'),
                    'version'     => 'latest',
                    'endpoint'    => $sqsEndpoint,
                    'use_path_style_endpoint' => true,
                    'credentials' => [
                        'key'    => config('services.sqs.key', 'x'),
                        'secret' => config('services.sqs.secret', 'x'),
                    ],
                    'http' => ['timeout' => 3],
                ]);
                $client->listQueues();
                $checks['sqs'] = 'ok';
            } else {
                $checks['sqs'] = 'not_configured';
            }
        } catch (\Throwable $e) {
            $checks['sqs'] = 'failed: ' . $e->getMessage();
            // Don't fail readiness for SQS in local dev
            if (config('app.env') === 'production') {
                $healthy = false;
            }
        }

        return response()->json([
            'status'  => $healthy ? 'ready' : 'not_ready',
            'service' => 'integration-service',
            'checks'  => $checks,
            'time'    => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
