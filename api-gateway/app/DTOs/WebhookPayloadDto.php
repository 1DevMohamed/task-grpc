<?php

namespace App\DTOs;

use App\Http\Requests\WebhookIntegrationRequest;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;

final readonly class WebhookPayloadDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $messageType,
        public string $vendorCode,
        public string $variantSku,
        public ?int $quantity,
        public ?float $price,
        public string $messageId,
        public string $receivedAt,
    ) {}

    public static function fromRequest(WebhookIntegrationRequest $request): self
    {
        $validated = $request->validated();

        return new self(
            messageType: (string) $validated['message_type'],
            vendorCode:  (string) $validated['vendor_code'],
            variantSku:  (string) $validated['variant_sku'],
            quantity:    isset($validated['quantity']) && $validated['quantity'] !== null ? (int) $validated['quantity'] : null,
            price:       isset($validated['price']) && $validated['price'] !== null ? (float) $validated['price'] : null,
            messageId:   (string) Str::uuid(),
            receivedAt:  now()->toISOString(),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageType: (string) ($data['message_type'] ?? ''),
            vendorCode:  (string) ($data['vendor_code'] ?? ''),
            variantSku:  (string) ($data['variant_sku'] ?? ''),
            quantity:    isset($data['quantity']) && $data['quantity'] !== null ? (int) $data['quantity'] : null,
            price:       isset($data['price']) && $data['price'] !== null ? (float) $data['price'] : null,
            messageId:   (string) ($data['message_id'] ?? Str::uuid()),
            receivedAt:  (string) ($data['received_at'] ?? now()->toISOString()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_id'   => $this->messageId,
            'message_type' => $this->messageType,
            'vendor_code'  => $this->vendorCode,
            'variant_sku'  => $this->variantSku,
            'quantity'     => $this->quantity,
            'price'        => $this->price,
            'received_at'  => $this->receivedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
