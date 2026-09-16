<?php

namespace App\Console\Commands;


use App\Grpc\Vendor\VendorServiceInterface;
use Illuminate\Console\Command;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

class RoadRunnerServeCommand extends Command
{
    protected $signature = 'roadrunner:serve';

    protected $description = 'Start the RoadRunner gRPC server';

    public function handle(): int
    {
        $server = new Server();

        $server->registerService(
            VendorServiceInterface::class,
            app(VendorServiceInterface::class)
        );

        $worker = Worker::create();

        $server->serve($worker);

        return self::SUCCESS;
    }
}