<?php

namespace App\Services;

use Aws\Sqs\SqsClient;
use App\Models\SqsPublishFailure;
use Log;
use Throwable;

class SqsService
{
    private SqsClient $client;
    private string $baseUrl;

    /** @var bool Flag for graceful shutdown */
    private bool $shouldStop = false;

    public function __construct(?SqsClient $client = null)
    {
        $this->client = $client ?? new SqsClient([
            'region' => config('services.sqs.region', 'us-east-1'),
            'version' => 'latest',
            'endpoint' => config('services.sqs.endpoint', 'http://elasticmq:9324'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => config('services.sqs.key', 'x'),
                'secret' => config('services.sqs.secret', 'x'),
            ],
        ]);

        $this->baseUrl = config('services.sqs.endpoint', 'http://elasticmq:9324')
            .'/000000000000/';
    }

    public function publish(string $queue, array $data, array $messageAttributes = []): void
    {
        $maxAttempts = config('services.sqs.max_attempts', 4);
        $lastException = null;

        // Merge default message attributes
        $attributes = array_merge([
            'message_type' => [
                'DataType' => 'String',
                'StringValue' => $data['message_type'] ?? $data['event_type'] ?? 'unknown',
            ],
        ], $messageAttributes);

        // Add event_id and correlation_id as message attributes if present
        if (isset($data['event_id'])) {
            $attributes['event_id'] = [
                'DataType' => 'String',
                'StringValue' => $data['event_id'],
            ];
        }

        if (isset($data['correlation_id'])) {
            $attributes['correlation_id'] = [
                'DataType' => 'String',
                'StringValue' => $data['correlation_id'],
            ];
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->client->sendMessage([
                    'QueueUrl' => $this->baseUrl.$queue,
                    'MessageBody' => json_encode($data),
                    'MessageAttributes' => $attributes,
                ]);

                return;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt < $maxAttempts) {
                    $delay = config('services.sqs.retry_delay_ms', 0);

                    if ($delay > 0) {
                        usleep($delay * 1000);
                    }
                }
            }
        }

        SqsPublishFailure::create([
            'queue' => $queue,
            'payload' => $data,
            'attempts' => $maxAttempts,
            'error' => $lastException?->getMessage() ?? 'Unknown SQS publish failure',
        ]);

        Log::error('SQS message publishing failed after retries', [
            'queue' => $queue,
            'attempts' => $maxAttempts,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException;
    }

    /**
     * Consume messages from an SQS queue.
     *
     * The callback receives the raw message body (string) and must return
     * a boolean: true to acknowledge/delete the message, false to leave it
     * for SQS retry.
     *
     * @param  string    $queue     Queue name
     * @param  callable  $callback  fn(string $body, array $sqsMessage): bool
     */
    public function consume(string $queue, callable $callback): void
    {
        $queueUrl = $this->baseUrl.$queue;
        $waitTime = (int) config('integration.sqs.wait_time', 20);

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        while (! $this->shouldStop) {
            try {
                $result = $this->client->receiveMessage([
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => 1,
                    'WaitTimeSeconds' => $waitTime,
                    'AttributeNames' => ['All'],
                    'MessageAttributeNames' => ['All'],
                ]);
            } catch (Throwable $e) {
                Log::error('SQS receive failed', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);

                // Brief pause before retrying the receive loop
                sleep(5);
                continue;
            }

            $messages = $result->get('Messages') ?? [];

            if (empty($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                $body = $message['Body'] ?? '';

                try {
                    // Callback returns true to acknowledge, false to skip deletion
                    $shouldDelete = $callback($body, $message);

                    if ($shouldDelete) {
                        $this->client->deleteMessage([
                            'QueueUrl' => $queueUrl,
                            'ReceiptHandle' => $message['ReceiptHandle'],
                        ]);
                    }
                    // If false: message stays invisible until VisibilityTimeout
                    // expires, then becomes visible again for retry

                } catch (Throwable $e) {
                    // Processing failed — do NOT delete the message.
                    // SQS will make it visible again after VisibilityTimeout.
                    Log::error('Message processing exception', [
                        'error' => $e->getMessage(),
                        'queue' => $queue,
                        'sqs_message_id' => $message['MessageId'] ?? 'unknown',
                    ]);
                }
            }
        }

        Log::info('SQS consumer shutting down gracefully', ['queue' => $queue]);
    }

    /**
     * Signal the consumer to stop after the current message.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Get the SQS client (for testing/health checks).
     */
    public function getClient(): SqsClient
    {
        return $this->client;
    }
}