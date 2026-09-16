<?php

namespace App\Console\Commands;

use App\Dispatcher\MessageDispatcher;
use App\Services\SqsService;
use Illuminate\Console\Command;

class ConsumeIntegrationQueueCommand extends Command
{
    protected $signature = 'consume:integration';
    protected $description = 'Integration worker — polls SQS and routes to handlers';

    public function handle(
        SqsService $sqs,
        MessageDispatcher $dispatcher
    ): void {
        $this->info('Integration worker started...');

        $sqs->consume(
            queue: 'integration-inbound',
            callback: function (array $message) use ($dispatcher) {
                $this->info("Dispatching: {$message['message_type']} [{$message['message_id']}]");
                $dispatcher->dispatch($message);
                $this->info("Done: {$message['message_id']}");
            }
        );
    }
}