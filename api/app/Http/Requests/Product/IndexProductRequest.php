<?php

declare(strict_types=1);

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates catalogue filters *before* they reach the query builder.
 *
 * `sort` is constrained to a whitelist here as well as in the model scope: the
 * request layer gives the client a helpful 422, and the scope guarantees that
 * no user-controlled string can ever reach an ORDER BY clause.
 */
final class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Either a numeric category id or a slug.
            'category' => ['sometimes', 'nullable', 'string', 'max:140'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'max_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'gte:min_price'],
            'in_stock' => ['sometimes', 'boolean'],
            'mine' => ['sometimes', 'boolean'],
            'sort' => [
                'sometimes',
                'nullable',
                Rule::in(['price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest', 'oldest']),
            ],
            // Capped so a client cannot ask for the entire catalogue in one page.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Normalised filter set handed to the service. Nulls are stripped so two
     * requests that mean the same thing produce the same cache key.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only([
                'search', 'category', 'min_price', 'max_price',
                'in_stock', 'mine', 'sort', 'per_page', 'page',
            ]),
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'max_price.gte' => 'The maximum price must be greater than or equal to the minimum price.',
        ];
    }
}
