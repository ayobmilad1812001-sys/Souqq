<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            // The `hashed` cast on the model handles the bcrypt call.
            'password' => $request->string('password')->value(),
            'role' => $request->enum('role', UserRole::class) ?? UserRole::Customer,
        ]);

        return ApiResponse::created([
            'user' => UserResource::make($user),
            'token' => $user->createToken($this->deviceName($request))->plainTextToken,
        ], 'Registration successful.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->value())->first();

        // One generic message for both "no such user" and "wrong password":
        // distinguishing them would let an attacker enumerate accounts. The
        // Hash::check on a null user is skipped, but the throttle on this route
        // is what actually blunts timing analysis.
        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return ApiResponse::success([
            'user' => UserResource::make($user),
            'token' => $user->createToken($this->deviceName($request))->plainTextToken,
        ], 'Login successful.');
    }

    /**
     * Revokes only the token that made this request, so signing out on a phone
     * does not sign the user out of their laptop.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    public function user(Request $request): JsonResponse
    {
        return ApiResponse::success(
            UserResource::make($request->user()),
            'Authenticated user retrieved.'
        );
    }

    private function deviceName(Request $request): string
    {
        return $request->string('device_name')->value()
            ?: substr((string) $request->userAgent(), 0, 100)
            ?: 'api-token';
    }
}
