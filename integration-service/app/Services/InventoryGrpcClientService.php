<?php

namespace App\Services;

use App\Grpc\Inventory\InventoryServiceClient;
use App\Grpc\Inventory\SetStockRequest;
use App\Grpc\Inventory\SetStockResponse;
use App\Integration\Exceptions\ErrorClassifier;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Exceptions\TransientFailureException;
use App\Integration\Logging\IntegrationLogger;
use App\Integration\Metrics\MetricsCollector;
use Grpc\ChannelCredentials;
use RuntimeException;
use const Grpc\STATUS_OK;

class InventoryGrpcClientService
{
    private InventoryServiceClient $stub;
    private int $timeoutMs;
    private readonly ErrorClassifier   $errorClassifier;
    private readonly IntegrationLogger $logger;
    private readonly MetricsCollector  $metrics;

    public function __construct(
        ?ErrorClassifier   $errorClassifier = null,
        ?IntegrationLogger $logger = null,
        ?MetricsCollector  $metrics = null,
    ) {
        $host = config('integration.rpc.inventory.host', 'inventory-svc:9001');
        $this->timeoutMs = config('integration.rpc.inventory.timeout_ms', 5000);

        $this->stub = new InventoryServiceClient(
            $host,
            ['credentials' => ChannelCredentials::createInsecure()]
        );

        $this->errorClassifier = $errorClassifier ?? new ErrorClassifier();
        $this->logger          = $logger ?? new IntegrationLogger();
        $this->metrics         = $metrics ?? new MetricsCollector();
    }

    /**
     * Set absolute stock via Inventory Service gRPC.
     *
     * @throws PermanentFailureException for business/validation errors
     * @throws TransientFailureException for transient/retryable errors
     */
    public function setAbsoluteStock(
        int    $productId,
        int    $quantity,
        string $correlationId = '',
        string $idempotencyKey = '',
    ): SetStockResponse {
        $request = new SetStockRequest();
        $request->setProductId($productId);
        $request->setQuantity($quantity);

        // Set idempotency fields if the proto supports them
        if (method_exists($request, 'setIdempotencyKey')) {
            $request->setIdempotencyKey($idempotencyKey);
        }
        if (method_exists($request, 'setCorrelationId')) {
            $request->setCorrelationId($correlationId);
        }

        $metadata = $this->buildMetadata($correlationId, $idempotencyKey);
        $startTime = microtime(true);

        try {
            [$response, $status] = $this->stub->SetAbsoluteStock($request, $metadata, [
                'timeout' => $this->timeoutMs * 1000, // microseconds
            ])->wait();

            $durationMs = (microtime(true) - $startTime) * 1000;

            if ($status->code !== STATUS_OK) {
                $this->logger->rpcCall('inventory', 'SetAbsoluteStock', $correlationId, $idempotencyKey, 'failed', $durationMs, $status->details);
                $this->metrics->rpcFailure('inventory', 'SetAbsoluteStock');
                $this->throwClassifiedError("InventoryService SetAbsoluteStock failed [{$status->code}]: {$status->details}", $status->code);
            }

            // Check business-level success flag
            if ($response instanceof SetStockResponse && !$response->getSuccess()) {
                $this->logger->rpcCall('inventory', 'SetAbsoluteStock', $correlationId, $idempotencyKey, 'business_error', $durationMs, $response->getMessage());
                $this->metrics->rpcFailure('inventory', 'SetAbsoluteStock');
                throw new PermanentFailureException(
                    "InventoryService SetAbsoluteStock business error: {$response->getMessage()}",
                    errorCategory: 'business_error',
                );
            }

            $this->logger->rpcCall('inventory', 'SetAbsoluteStock', $correlationId, $idempotencyKey, 'success', $durationMs);
            $this->metrics->rpcSuccess('inventory', 'SetAbsoluteStock', $durationMs);

            return $response;

        } catch (PermanentFailureException|TransientFailureException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logger->rpcCall('inventory', 'SetAbsoluteStock', $correlationId, $idempotencyKey, 'error', $durationMs, $e->getMessage());

            if (str_contains(strtolower($e->getMessage()), 'deadline exceeded')
                || str_contains(strtolower($e->getMessage()), 'timeout')) {
                $this->metrics->rpcTimeout('inventory', 'SetAbsoluteStock');
            } else {
                $this->metrics->rpcFailure('inventory', 'SetAbsoluteStock');
            }

            throw $this->errorClassifier->wrapException($e);
        }
    }

    /**
     * Build gRPC metadata for correlation/idempotency.
     */
    private function buildMetadata(string $correlationId, string $idempotencyKey): array
    {
        $metadata = [];

        if ($correlationId !== '') {
            $metadata['x-correlation-id'] = [$correlationId];
        }

        if ($idempotencyKey !== '') {
            $metadata['x-idempotency-key'] = [$idempotencyKey];
        }

        return $metadata;
    }

    /**
     * Throw a typed exception based on gRPC status code.
     */
    private function throwClassifiedError(string $message, int $grpcStatusCode): never
    {
        if (in_array($grpcStatusCode, [1, 4, 8, 10, 14], true)) {
            throw new TransientFailureException($message, errorCategory: 'grpc_transient');
        }

        if (in_array($grpcStatusCode, [3, 5, 6, 7, 9, 12, 16], true)) {
            throw new PermanentFailureException($message, errorCategory: 'grpc_permanent');
        }

        throw (new ErrorClassifier())->wrapException(new RuntimeException($message));
    }
}