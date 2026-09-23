<?php

namespace App\Console\Commands;

use App\Integration\Repositories\InboxRepository;
use App\Services\SqsService;
use Illuminate\Console\Command;

class DlqReplayCommand extends Command
{
    protected $signature = 'dlq:replay
                            {event_id : The event_id to replay}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Replay a failed event from DLQ by requeueing it to the main queue';

    public function handle(SqsService $sqs, InboxRepository $inboxRepo): void
    {
        $eventId    = $this->argument('event_id');
        $mainQueue  = config('integration.sqs.queue', 'integration-inbound');

        $this->info("Replaying event: {$eventId}");

        // Reset inbox status so the event can be reprocessed
        $inbox = $inboxRepo->resetForReplay($eventId);

        if ($inbox === null) {
            $this->error("Event '{$eventId}' not found in inbox.");
            return;
        }

        $this->line("  Event Type:      {$inbox->event_type}");
        $this->line("  Aggregate ID:    {$inbox->aggregate_id}");
        $this->line("  Previous Status: FAILED → reset to RECEIVED");

        if (! $this->option('force') && ! $this->confirm('Requeue this event to the main queue?')) {
            $this->info('Aborted.');
            return;
        }

        // Get the original event payload from the inbox
        $envelope = $inbox->payload;

        if (empty($envelope)) {
            $this->error('Inbox event has no stored payload — cannot replay.');
            return;
        }

        // Publish back to the main queue
        try {
            $sqs->publish($mainQueue, $envelope);

            $this->info("Event '{$eventId}' requeued to '{$mainQueue}' successfully.");
            $this->info('The integration worker will pick it up for reprocessing.');

        } catch (\Throwable $e) {
            $this->error("Failed to requeue event: {$e->getMessage()}");
        }
    }
}
