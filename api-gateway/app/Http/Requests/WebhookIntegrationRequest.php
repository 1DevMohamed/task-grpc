<?php

namespace App\Http\Requests;

use App\DTOs\WebhookPayloadDto;
use Illuminate\Foundation\Http\FormRequest;

class WebhookIntegrationRequest extends FormRequest
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
            'message_type' => ['required', 'string'],
            'vendor_code'  => ['required', 'string'],
            'variant_sku'  => ['required', 'string'],
            'quantity'     => ['nullable', 'integer'],
            'price'        => ['nullable', 'numeric'],
            'merchant_id'  => ['nullable', 'string'],
        ];
    }

    /**
     * Transform the validated request into a DTO.
     */
    public function toDto(): WebhookPayloadDto
    {
        return WebhookPayloadDto::fromRequest($this);
    }
}
