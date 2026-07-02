<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function storeLogin(Request $request)
    {
        $request->validate([
            'store_code' => 'required',
            'store_pin' => 'required',
        ]);

        $store = Store::where('store_code', $request->store_code)->first();

        if (!$store || !Hash::check($request->store_pin, $store->store_pin)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = $store->createToken('store-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'store' => $store
        ]);
    }
}