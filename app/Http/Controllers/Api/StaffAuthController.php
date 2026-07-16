<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_code' => ['required', 'string', 'max:255'],
            'staff_id' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string', 'max:255'],
        ]);

        $store = Store::where(
            'store_code',
            trim($validated['store_code'])
        )->first();

        if (!$store) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $staff = Staff::where('store_id', $store->id)
            ->where('staff_id', trim($validated['staff_id']))
            ->where('is_active', true)
            ->first();

        if (!$staff || !Hash::check($validated['pin'], $staff->pin)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $staff->tokens()->delete();

        $token = $staff->createToken('staff-token', [
            'payment:create',
            'payment:view',
        ])->plainTextToken;

        return response()->json([
            'token' => $token,
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
        ]);
    }
}