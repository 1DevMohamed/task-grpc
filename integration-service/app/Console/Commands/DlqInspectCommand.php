<?php

namespace App\Console\Commands;

use App\Services\SqsService;
use Illuminate\Console\Command;

class DlqInspectCommand extends Command
{
    protected $signature = 'dlq:inspect
                            {--limit=10 : Maximum number of messages to inspect}';

    protected $description = 'Inspect messages in the integration DLQ without consuming them';

    public function handle(SqsService $sqs): void
    {
        $dlqQueue = config('integration.sqs.dlq', 'integration-dlq');
        $limit    = (int) $this->option('limit');

        $this->info("Inspecting DLQ: {$dlqQueue} (max {$limit} messages)");
        $this->newLine();

        $baseUrl  = config('services.sqs.endpoint', 'http://elasticmq:9324') . '/000000000000/';
        $queueUrl = $baseUrl . $dlqQueue;

        $client = $sqs->getClient();
        $found  = 0;

        // Use short visibility timeout so messages become visible again quickly
        for ($i = 0; $i < $limit; $i++) {
            $result = $client->receiveMessage([
                'QueueUrl'            => $queueUrl,
                'MaxNumberOfMessages' => 1,
                'WaitTimeSeconds'     => 1,
                'VisibilityTimeout'   => 5,   // Short — we're just peeking
                'AttributeNames'      => ['All'],
                'MessageAttributeNames' => ['All'],
            ]);

            $messages = $result->get('Messages') ?? [];

            if (empty($messages)) {
                break;
            }

            foreach ($messages as $message) {
                $found++;
                $body = json_decode($message['Body'], true);

                $this->line("── Message #{$found} ──");
                $this->line("  SQS Message ID:  " . ($message['MessageId'] ?? 'N/A'));
                $this->line("  Event ID:        " . ($body['event_id'] ?? 'N/A'));
                $this->line("  Event Type:      " . ($body['event_type'] ?? 'N/A'));
                $this->line("  Correlation ID:  " . ($body['correlation_id'] ?? 'N/A'));
                $this->line("  Aggregate ID:    " . ($body['aggregate_id'] ?? 'N/A'));
                $this->line("  Occurred At:     " . ($body['occurred_at'] ?? 'N/A'));
                $this->line("  Source:          " . ($body['source'] ?? 'N/A'));
                $this->line("  Receive Count:   " . ($message['Attributes']['ApproximateReceiveCount'] ?? 'N/A'));

                if (isset($body['payload'])) {
                    $this->line("  Payload:         " . json_encode($body['payload'], JSON_PRETTY_PRINT));
                }

                // Check inbox for error context
                $inboxEvent = \App\Integration\Models\InboxEvent::where('event_id', $body['event_id'] ?? '')->first();
                if ($inboxEvent) {
                    $this->line("  Inbox Status:    " . $inboxEvent->status);
                    $this->line("  Inbox Attempts:  " . $inboxEvent->attempts);
                    $this->line("  Error Category:  " . ($inboxEvent->error_category ?? 'N/A'));
                    $this->line("  Error Message:   " . ($inboxEvent->error_message ?? 'N/A'));
                }

                $this->newLine();

                // Make message visible again (change visibility to 0)
                try {
                    $client->changeMessageVisibility([
                        'QueueUrl'          => $queueUrl,
                        'ReceiptHandle'     => $message['ReceiptHandle'],
                        'VisibilityTimeout' => 0,
                    ]);
                } catch (\Throwable) {
                    // Best effort — the message will become visible after the short timeout anyway
                }
            }
        }

        $this->info("Found {$found} message(s) in DLQ.");
    }
}
