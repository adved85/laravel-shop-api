<?php

namespace App\Http\Requests\Admin\V1;

use Illuminate\Contracts\Validation\ValidationRule;
// use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\ApiFormRequests;
use Illuminate\Validation\Rule;
use Str;

class BrandRequest extends ApiFormRequests
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $brand = $this->route('brand');

        $nameRule = Rule::unique('brands','name');
        $slugRule = Rule::unique('brands', 'slug');

        // on POST is null, on PUT/PATCH is a model
        if ($brand) {
            $nameRule->ignore($brand->id);
            $slugRule->ignore($brand->id);
        }

        if ($this->isMethod('POST') || $this->isMethod('PUT')) {
            return [
                'name' => ['required', 'string', 'min:2', 'max:230', $nameRule],
                'slug' => ['required', 'string', 'min:2', 'max:255', $slugRule],

                'in_use' => ['sometimes', 'boolean'],
                'order' => ['sometimes', 'integer', 'min:0'],
            ];
        }

        if ($this->isMethod('PATCH')) {
            return [
                'name' => ['sometimes', 'string', 'min:2', 'max:230', $nameRule],
                'slug' => ['sometimes', 'string', 'min:2', 'max:255', $slugRule],

                'in_use' => ['sometimes', 'boolean'],
                'order' => ['sometimes', 'integer', 'min:0'],
            ];
        }

        return [];
    }

    protected function prepareForValidation(): void {
        if (!$this->has('slug')) {
            $this->merge([
                'slug' => Str::slug($this->name)
            ]);
        }
    }

    public function attributes(): array {
        return [
            'name'      => 'brand name',
            'slug'      => 'URL slug',
            'in_use'    => 'status',
            'order'     => 'display order',
        ];
    }
}
