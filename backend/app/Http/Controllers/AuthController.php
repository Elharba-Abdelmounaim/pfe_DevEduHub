<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // ── POST /api/auth/register ───────────────────────────────────────────
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'first_name'               => $request->first_name,
            'last_name'                => $request->last_name,
            'email'                    => $request->email,
            'password_hash'            => Hash::make($request->password),
            'role'                     => $request->role,          // 'teacher' | 'student'
            'github_username'          => $request->github_username,
            'email_verification_token' => Str::random(64),
            'is_active'                => true,
            'is_verified'              => false,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ], 201);
    }

    // ── POST /api/auth/login ──────────────────────────────────────────────
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Account is deactivated.'], 403);
        }

        // Revoke old tokens to prevent token accumulation
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;
        $user->update(['last_login' => now()]);

        return response()->json([
            'token' => $token,
            'user'  => new UserResource($user),
        ]);
    }

    // ── POST /api/auth/logout ─────────────────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ── GET /api/auth/me ──────────────────────────────────────────────────
    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()));
    }

    // ── GET /api/auth/verify/{token} ──────────────────────────────────────
    public function verifyEmail(string $token): JsonResponse
    {
        $user = User::where('email_verification_token', $token)->firstOrFail();

        $user->update([
            'is_verified'              => true,
            'email_verification_token' => null,
        ]);

        return response()->json(['message' => 'Email verified successfully.']);
    }
}