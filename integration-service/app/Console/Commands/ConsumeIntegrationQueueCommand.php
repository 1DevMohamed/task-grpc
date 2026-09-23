<?php

namespace App\Console\Commands;

use App\Integration\Services\EventProcessingService;
use App\Services\SqsService;
use Illuminate\Console\Command;

class ConsumeIntegrationQueueCommand extends Command
{
    protected $signature = 'consume:integration
                            {--workers=1 : Number of messages to process per batch (not parallel workers)}';

    protected $description = 'Integration worker — polls SQS and routes events to handlers via the processing pipeline';

    public function handle(
        SqsService             $sqs,
        EventProcessingService $processor,
    ): void {
        $queue = config('integration.sqs.queue', 'integration-inbound');

        $this->info("Integration worker started — consuming from '{$queue}'...");

        $sqs->consume(
            queue: $queue,
            callback: function (string $body, array $sqsMessage) use ($processor): bool {
                $sqsMessageId = $sqsMessage['MessageId'] ?? 'unknown';

                $this->line("[{$sqsMessageId}] Processing message...");

                $result = $processor->process($body);

                $this->line("[{$sqsMessageId}] Result: {$result->status}"
                    . ($result->message ? " — {$result->message}" : ''));

                // Return true to acknowledge/delete, false to leave for retry
                return $result->shouldAcknowledge;
            }
        );

        $this->info('Integration worker stopped.');
    }
}