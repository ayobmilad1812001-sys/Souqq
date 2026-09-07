<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // An absolute quantity, not a delta. Removing a line is DELETE.
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('marketplace.cart.max_quantity_per_item'),
            ],
        ];
    }
}
