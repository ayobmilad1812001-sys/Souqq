<?php

declare(strict_types=1);

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:140',
                'alpha_dash',
                // Ignore this row so re-saving an unchanged slug is not a
                // uniqueness violation.
                Rule::unique('categories', 'slug')->ignore($this->route('category')),
            ],
        ];
    }
}
