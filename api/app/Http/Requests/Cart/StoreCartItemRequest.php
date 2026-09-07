<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                'integer',
                // Only active products can enter a cart. Availability is still
                // re-checked under lock at checkout -- this is fast feedback,
                // not the authoritative guarantee.
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('marketplace.cart.max_quantity_per_item'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.exists' => 'The selected product is unavailable.',
        ];
    }
}
