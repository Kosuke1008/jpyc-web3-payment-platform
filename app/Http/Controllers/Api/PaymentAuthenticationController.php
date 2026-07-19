<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PaymentAuthenticationController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $expirationMinutes = config(
            'services.livt_wallet.payment_token_expiration_minutes',
            30
        );

        if ((! is_int($expirationMinutes) && ! is_string($expirationMinutes))
            || preg_match('/\A[0-9]+\z/', (string) $expirationMinutes) !== 1
            || gmp_cmp(gmp_init((string) $expirationMinutes, 10), 1) < 0
            || gmp_cmp(gmp_init((string) $expirationMinutes, 10), 60) > 0) {
            return response()->json([
                'message' => 'Payment authentication is unavailable',
            ], 500);
        }

        $expirationMinutes = (int) $expirationMinutes;
        $expiresAt = now()->addMinutes($expirationMinutes);
        $token = $user->createToken(
            'payment-confirmation',
            ['payment:confirm'],
            $expiresAt
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);
    }

    public function session(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
            ],
        ]);
    }
}
