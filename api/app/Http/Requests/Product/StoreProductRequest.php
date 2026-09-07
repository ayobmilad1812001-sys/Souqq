<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // decimal:0,2 matches the DECIMAL(10,2) column exactly, so a value
            // like 9.999 is rejected rather than silently rounded on insert.
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'sku' => ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('products', 'sku')],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
