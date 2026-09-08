<?php

namespace App\DTOs;

use App\Http\Requests\UpdateProductStockRequest;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

final readonly class ProductStockDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public ?float $price,
        public string $updatedAt,
    ) {}

    public static function fromRequest(UpdateProductStockRequest $request): self
    {
        $validated = $request->validated();

        return new self(
            productId: (int) $validated['product_id'],
            quantity:  (int) $validated['quantity'],
            price:     isset($validated['price']) && $validated['price'] !== null ? (float) $validated['price'] : null,
            updatedAt: now()->toISOString(),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: (int) ($data['product_id'] ?? 0),
            quantity:  (int) ($data['quantity'] ?? 0),
            price:     isset($data['price']) && $data['price'] !== null ? (float) $data['price'] : null,
            updatedAt: (string) ($data['updated_at'] ?? now()->toISOString()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'quantity'   => $this->quantity,
            'price'      => $this->price,
            'updated_at' => $this->updatedAt,
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
