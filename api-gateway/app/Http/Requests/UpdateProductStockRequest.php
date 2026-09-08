<?php

namespace App\Http\Requests;

use App\DTOs\ProductStockDto;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductStockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'quantity'   => ['required', 'integer'],
            'price'      => ['nullable', 'numeric'],
        ];
    }

    /**
     * Transform the validated request into a DTO.
     */
    public function toDto(): ProductStockDto
    {
        return ProductStockDto::fromRequest($this);
    }
}
