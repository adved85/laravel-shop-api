<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Enums\HTTPCodes;

abstract class ApiFormRequests extends FormRequest {

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(

            response()->json([
                'success' => false,
                'message' => 'Forbidden',
                'code' => HTTPCodes::UNAUTHORIZED->value
            ], HTTPCodes::UNAUTHORIZED->value)
        );
    }

    protected function failedValidation(Validator $validator) : void
    {

        throw new HttpResponseException(

            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => HTTPCodes::VALIDATION_ERROR->value
            ], HTTPCodes::VALIDATION_ERROR->value)
        );
    }
}
