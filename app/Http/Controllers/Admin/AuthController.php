<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

use App\Models\User;
use App\Http\Requests\Admin\AuthRequest;

use App\Support\API\ApiResponse;

class AuthController extends Controller
{
    public function __construct(private ApiResponse $apiResponse) {}

    public function login(AuthRequest $request)
    {
        $validated = $request->validated();

        $email = $validated['email'];
        $password = $validated['password'];
        $token_name = $request->input('token_name', 'auth_token');

        if (Auth::attempt(['email' => $email, 'password' => $password])) {

            $user = Auth::user();
            $user->tokens()->where('name', 'auth_token')->delete();
            $token = $user->createToken($token_name)->plainTextToken;

            return $this->apiResponse->ok([
                'user'  => $user,
                'token' => $token,
            ], 'Login successful');

        } else {
            return $this->apiResponse->unauthenticated('Either email/password is incorrect');
        }
    }

    public function register(AuthRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token_name = $request->input('token_name', 'auth_token');
        $token = $user->createToken($token_name)->plainTextToken;

        return $this->apiResponse->created([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request) {
        $request->user()->currentAccessToken()->delete();

        return $this->apiResponse->noContent();
    }
}
