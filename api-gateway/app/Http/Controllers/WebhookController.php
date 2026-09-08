<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebhookIntegrationRequest;
use App\Services\SqsService;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    public function __construct(private readonly SqsService $sqs) {}

    public function receive(WebhookIntegrationRequest $request): JsonResponse
    {
        $dto = $request->toDto();

        $this->sqs->publish('integration-inbound', $dto->toArray());

        return response()->json([
            'message'    => 'Accepted',
            'message_id' => $dto->messageId,
        ], 202);
    }
}
