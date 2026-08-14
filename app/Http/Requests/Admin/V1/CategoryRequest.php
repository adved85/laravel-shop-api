<?php

namespace App\Http\Requests\Admin\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Http\Requests\ApiFormRequests;

class CategoryRequest extends ApiFormRequests
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        $nameRule = Rule::unique('categories', 'name');
        $slugRule = Rule::unique('categories', 'slug');

        if ($category) {
            $nameRule = $nameRule->ignore($category->id);
            $slugRule = $slugRule->ignore($category->id);
        }

        $parentRule = ['nullable', 'integer', 'exists:categories,id', function ($attr, $value, $fail) use ($category) {
            if ($value && $category && $value == $category->id) {
                $fail('A category cannot be its own parent.');
            }
        }];

        if ($this->isMethod('POST')) {
            return [
                'name'      => ['required', 'string', 'min:2', 'max:200', $nameRule],
                'slug'      => ['required', 'string', 'min:2', 'max:255', $slugRule],
                'parent_id' => $parentRule,
                'in_use'    => ['sometimes', 'boolean'],
                'order'     => ['sometimes', 'integer', 'min:0'],
            ];
        }

        if ($this->isMethod('PUT')) {
            return [
                'name'      => ['required', 'string', 'min:2', 'max:200', $nameRule],
                'slug'      => ['required', 'string', 'min:2', 'max:255', $slugRule],
                'parent_id' => $parentRule,
                'in_use'    => ['sometimes', 'boolean'],
                'order'     => ['sometimes', 'integer', 'min:0'],
            ];
        }

        if ($this->isMethod('PATCH')) {
            return [
                'name'      => ['sometimes', 'string', 'min:2', 'max:200', $nameRule],
                'slug'      => ['sometimes', 'string', 'min:2', 'max:255', $slugRule],
                'parent_id' => array_merge(['sometimes'], $parentRule),
                'in_use'    => ['sometimes', 'boolean'],
                'order'     => ['sometimes', 'integer', 'min:0'],
            ];
        }

        return [];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('slug') && $this->has('name')) {
            $this->merge([
                'slug' => Str::slug($this->name),
            ]);
        }
    }

    public function attributes(): array
    {
        return [
            'name'      => 'category name',
            'slug'      => 'URL slug',
            'parent_id' => 'parent category',
            'in_use'    => 'status',
            'order'     => 'display order',
        ];
    }
}
