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

    public function __construct(?SqsClient $client = null)
    {
        $this->client = $client ?? new SqsClient([
            'region' => config('services.sqs.region', 'us-east-1'),
            'version' => 'latest',
            'endpoint' => config('services.sqs.endpoint', 'http://elasticmq:9324'),
            'credentials' => [
                'key' => config('services.sqs.key', 'x'),
                'secret' => config('services.sqs.secret', 'x'),
            ],
        ]);

        $this->baseUrl = config('services.sqs.endpoint', 'http://elasticmq:9324')
            .'/000000000000/';
    }

    public function publish(string $queue, array $data): void
    {
        $maxAttempts = config('services.sqs.max_attempts', 4);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->client->sendMessage([
                    'QueueUrl' => $this->baseUrl.$queue,
                    'MessageBody' => json_encode($data),
                    'MessageAttributes' => [
                        'message_type' => [
                            'DataType' => 'String',
                            'StringValue' => $data['message_type'] ?? 'unknown',
                        ],
                    ],
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

    public function consume(string $queue, callable $callback): void
    {
        $queueUrl = $this->baseUrl.$queue;

        while (true) {
            $result = $this->client->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => 1,
                'WaitTimeSeconds' => 20,
                'AttributeNames' => ['All'],
            ]);

            $messages = $result->get('Messages') ?? [];

            if (empty($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                $data = json_decode($message['Body'], true);

                try {
                    $callback($data);

                    $this->client->deleteMessage([
                        'QueueUrl' => $queueUrl,
                        'ReceiptHandle' => $message['ReceiptHandle'],
                    ]);

                } catch (Throwable $e) {
                    Log::error('Message processing failed', [
                        'error' => $e->getMessage(),
                        'message' => $data,
                    ]);
                }
            }
        }
    }
}