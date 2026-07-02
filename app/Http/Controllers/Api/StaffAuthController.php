<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\Staff;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'store_code' => 'required|string',
            'staff_id'   => 'required|integer',
            'pin'        => 'required|string|min:4|max:6',
        ]);

        // 店舗取得
        $store = Store::where('store_code', $request->store_code)->first();

        if (!$store) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // スタッフ取得（先にやる）
$staff = Staff::where('store_id', $store->id)
    ->where('staff_id', (string)$request->staff_id)
    ->first();

// nullチェック（超重要）
if (!$staff) {
    \Log::info('STAFF NOT FOUND', [
        'input_staff_id' => $request->staff_id
    ]);
    return response()->json(['message' => 'Invalid credentials'], 401);
}

// ここで初めて使う
\Log::info('DEBUG PIN', [
    'input_pin' => $request->pin,
    'hash' => $staff->pin,
    'result' => Hash::check($request->pin, $staff->pin)
]);

// PINチェック
if (!Hash::check($request->pin, $staff->pin)) {
    return response()->json(['message' => 'Invalid credentials'], 401);
}

        // 既存トークン削除（任意：1端末1ログイン）
        $staff->tokens()->delete();

        // トークン発行
        $token = $staff->createToken('staff-token', [
            'payment:create',
            'payment:view'
        ])->plainTextToken;

        return response()->json([
            'token' => $token,
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
            ],
            'store' => [
                'id' => $store->id,
                'name' => $store->name
            ]
        ]);
    }
}