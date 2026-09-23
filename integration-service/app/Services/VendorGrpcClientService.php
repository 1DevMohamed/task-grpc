<?php

namespace App\Services;

use App\Grpc\Vendor\ResolveIdsRequest;
use App\Grpc\Vendor\ResolveIdsResponse;
use App\Grpc\Vendor\UpdatePriceRequest;
use App\Grpc\Vendor\UpdatePriceResponse;
use App\Grpc\Vendor\VendorServiceClient;
use App\Integration\Exceptions\ErrorClassifier;
use App\Integration\Exceptions\PermanentFailureException;
use App\Integration\Exceptions\TransientFailureException;
use App\Integration\Logging\IntegrationLogger;
use App\Integration\Metrics\MetricsCollector;
use Grpc\ChannelCredentials;
use RuntimeException;
use const Grpc\STATUS_OK;

class VendorGrpcClientService
{
    private VendorServiceClient $stub;
    private int $timeoutMs;
    private readonly ErrorClassifier   $errorClassifier;
    private readonly IntegrationLogger $logger;
    private readonly MetricsCollector  $metrics;

    public function __construct(
        ?ErrorClassifier   $errorClassifier = null,
        ?IntegrationLogger $logger = null,
        ?MetricsCollector  $metrics = null,
    ) {
        $host = config('integration.rpc.vendor.host', 'vendor-svc:50054');
        $this->timeoutMs = config('integration.rpc.vendor.timeout_ms', 5000);

        $this->stub = new VendorServiceClient(
            $host,
            ['credentials' => ChannelCredentials::createInsecure()]
        );

        $this->errorClassifier = $errorClassifier ?? new ErrorClassifier();
        $this->logger          = $logger ?? new IntegrationLogger();
        $this->metrics         = $metrics ?? new MetricsCollector();
    }

    /**
     * Update price via Vendor Service gRPC.
     *
     * @throws PermanentFailureException for business/validation errors
     * @throws TransientFailureException for transient/retryable errors
     */
    public function updatePrice(
        string $vendorCode,
        string $variantSku,
        float  $price,
        string $correlationId = '',
        string $idempotencyKey = '',
    ): UpdatePriceResponse {
        $request = new UpdatePriceRequest();
        $request->setVendorCode($vendorCode);
        $request->setVariantSku($variantSku);
        $request->setPrice($price);

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
            [$response, $status] = $this->stub->UpdatePrice($request, $metadata, [
                'timeout' => $this->timeoutMs * 1000, // microseconds
            ])->wait();

            $durationMs = (microtime(true) - $startTime) * 1000;

            if ($status->code !== STATUS_OK) {
                $this->logger->rpcCall('vendor', 'UpdatePrice', $correlationId, $idempotencyKey, 'failed', $durationMs, $status->details);
                $this->metrics->rpcFailure('vendor', 'UpdatePrice');
                $this->throwClassifiedError("VendorService UpdatePrice failed: {$status->details}", $status->code);
            }

            // Check business-level success flag
            if ($response instanceof UpdatePriceResponse && !$response->getSuccess()) {
                $this->logger->rpcCall('vendor', 'UpdatePrice', $correlationId, $idempotencyKey, 'business_error', $durationMs, $response->getMessage());
                $this->metrics->rpcFailure('vendor', 'UpdatePrice');
                throw new PermanentFailureException(
                    "VendorService UpdatePrice business error: {$response->getMessage()}",
                    errorCategory: 'business_error',
                );
            }

            $this->logger->rpcCall('vendor', 'UpdatePrice', $correlationId, $idempotencyKey, 'success', $durationMs);
            $this->metrics->rpcSuccess('vendor', 'UpdatePrice', $durationMs);

            return $response;

        } catch (PermanentFailureException|TransientFailureException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logger->rpcCall('vendor', 'UpdatePrice', $correlationId, $idempotencyKey, 'error', $durationMs, $e->getMessage());

            if (str_contains(strtolower($e->getMessage()), 'deadline exceeded')
                || str_contains(strtolower($e->getMessage()), 'timeout')) {
                $this->metrics->rpcTimeout('vendor', 'UpdatePrice');
            } else {
                $this->metrics->rpcFailure('vendor', 'UpdatePrice');
            }

            throw $this->errorClassifier->wrapException($e);
        }
    }

    /**
     * Resolve internal product/vendor IDs via Vendor Service gRPC.
     *
     * @throws PermanentFailureException for business/validation errors
     * @throws TransientFailureException for transient/retryable errors
     */
    public function resolveIds(
        string $vendorCode,
        string $variantSku,
        string $correlationId = '',
        string $idempotencyKey = '',
    ): ResolveIdsResponse {
        $request = new ResolveIdsRequest();
        $request->setVendorCode($vendorCode);
        $request->setVariantSku($variantSku);

        if (method_exists($request, 'setIdempotencyKey')) {
            $request->setIdempotencyKey($idempotencyKey);
        }
        if (method_exists($request, 'setCorrelationId')) {
            $request->setCorrelationId($correlationId);
        }

        $metadata = $this->buildMetadata($correlationId, $idempotencyKey);
        $startTime = microtime(true);

        try {
            [$response, $status] = $this->stub->ResolveIds($request, $metadata, [
                'timeout' => $this->timeoutMs * 1000,
            ])->wait();

            $durationMs = (microtime(true) - $startTime) * 1000;

            if ($status->code !== STATUS_OK) {
                $this->logger->rpcCall('vendor', 'ResolveIds', $correlationId, $idempotencyKey, 'failed', $durationMs, $status->details);
                $this->metrics->rpcFailure('vendor', 'ResolveIds');
                $this->throwClassifiedError("VendorService ResolveIds failed: {$status->details}", $status->code);
            }

            $this->logger->rpcCall('vendor', 'ResolveIds', $correlationId, $idempotencyKey, 'success', $durationMs);
            $this->metrics->rpcSuccess('vendor', 'ResolveIds', $durationMs);

            return $response;

        } catch (PermanentFailureException|TransientFailureException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logger->rpcCall('vendor', 'ResolveIds', $correlationId, $idempotencyKey, 'error', $durationMs, $e->getMessage());

            if (str_contains(strtolower($e->getMessage()), 'deadline exceeded')
                || str_contains(strtolower($e->getMessage()), 'timeout')) {
                $this->metrics->rpcTimeout('vendor', 'ResolveIds');
            } else {
                $this->metrics->rpcFailure('vendor', 'ResolveIds');
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
     * Throw a typed exception based on gRPC status code analysis.
     */
    private function throwClassifiedError(string $message, int $grpcStatusCode): never
    {
        // Transient gRPC codes
        if (in_array($grpcStatusCode, [1, 4, 8, 10, 14], true)) {
            throw new TransientFailureException($message, errorCategory: 'grpc_transient');
        }

        // Permanent gRPC codes
        if (in_array($grpcStatusCode, [3, 5, 6, 7, 9, 12, 16], true)) {
            throw new PermanentFailureException($message, errorCategory: 'grpc_permanent');
        }

        // Unknown codes — classify by message content
        throw (new ErrorClassifier())->wrapException(new RuntimeException($message));
    }
}