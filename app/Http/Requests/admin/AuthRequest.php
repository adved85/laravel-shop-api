<?php

namespace App\Http\Requests\admin;

use Illuminate\Contracts\Validation\ValidationRule;
// use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\ApiFormRequests;

class AuthRequest extends ApiFormRequests
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

        $login = str_contains(request()->uri(), 'login');
        $register = str_contains(request()->url(), 'register');

        if ($login) {
            return [
                'email' => 'required|email',
                'password' => 'required|string'
            ];
        }
        if ($register) {
            return [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users',
                'password' => 'required|min:8|confirmed',
            ];
        }

        return [];
    }
}
