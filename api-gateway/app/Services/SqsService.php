<?php

namespace App\Services;

use Aws\Sqs\SqsClient;
use Throwable;

class SqsService
{
    private SqsClient $client;
    private string    $baseUrl;

    public function __construct()
    {
        $this->client = new SqsClient([
            'region'      => config('services.sqs.region', 'us-east-1'),
            'version'     => 'latest',
            'endpoint'    => config('services.sqs.endpoint', 'http://elastic:9324'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => config('services.sqs.key',    'x'),
                'secret' => config('services.sqs.secret', 'x'),
            ],
        ]);

        $this->baseUrl = config('services.sqs.endpoint', 'http://elasticmq:9324')
            . '/000000000000/';
    }

    /**
     * Publish an event envelope to SQS.
     *
     * @param  string  $queue  Queue name
     * @param  array   $data   Event envelope data
     *
     * @throws Throwable if publishing fails after retries
     */
    public function publish(string $queue, array $data): void
    {
        $messageAttributes = [
            'event_type' => [
                'DataType'    => 'String',
                'StringValue' => $data['event_type'] ?? $data['message_type'] ?? 'unknown',
            ],
        ];

        // Add event_id and correlation_id as message attributes for observability
        if (isset($data['event_id'])) {
            $messageAttributes['event_id'] = [
                'DataType'    => 'String',
                'StringValue' => $data['event_id'],
            ];
        }

        if (isset($data['correlation_id'])) {
            $messageAttributes['correlation_id'] = [
                'DataType'    => 'String',
                'StringValue' => $data['correlation_id'],
            ];
        }

        $this->client->sendMessage([
            'QueueUrl'          => $this->baseUrl . $queue,
            'MessageBody'       => json_encode($data),
            'MessageAttributes' => $messageAttributes,
        ]);
    }
}
