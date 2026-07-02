<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function confirm($id, Request $request)
    {

        if (!$request->user()) {
            return response()->json([
                'error' => 'Unauthenticated user'
            ], 401);
        }

        // ① バリデーション
        $request->validate([
            'tx_hash' => 'required|string|size:66'
        ]);

        $txHash = strtolower($request->tx_hash);

        // ② payment取得
        $payment = DB::table('payments')->where('id', $id)->first();

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        if ($payment->status === 'confirmed') {
            return response()->json(['error' => 'Already paid'], 400);
        }

        if ($payment->expires_at && now()->gt($payment->expires_at)) {
            return response()->json(['error' => 'Expired'], 400);
        }

        // ③ tx_hash重複チェック（リプレイ攻撃対策）
        if (DB::table('payments')->where('tx_hash', $txHash)->exists()) {
            return response()->json(['error' => 'Duplicate tx_hash'], 400);
        }

        // ④ receipt取得
        $receipt = null;

        for ($i = 0; $i < 10; $i++) {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ]
                ])
                ->post(env('WEB3_RPC_URL'), [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getTransactionReceipt',
                    'params' => [$txHash],
                    'id' => 1
                ]);

            $data = $response->json();

            if (!empty($data['result'])) {
                $receipt = $data['result'];
                break;
            }

            usleep(500000); // 0.5秒
        }

        if (!$receipt) {
            return response()->json(['error' => 'Transaction not found'], 400);
        }

        // ⑤ トランザクション成功確認
        if (!isset($receipt['status']) || $receipt['status'] !== '0x1') {
            return response()->json(['error' => 'Transaction failed'], 400);
        }

        // ⑥ Transferイベント検証
        $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $valid = false;

        foreach ($receipt['logs'] as $log) {

            // コントラクトチェック
            if (strtolower($log['address']) !== strtolower(env('ERC20_CONTRACT_ADDRESS'))) {
                continue;
            }

            // Transferイベントチェック
            if (strtolower($log['topics'][0]) !== $transferTopic) {
                continue;
            }

            // toアドレス取得
            $to = '0x' . substr(strtolower($log['topics'][2]), 26);

            // amount取得（16進数 → 10進数）
            $amount = gmp_strval(gmp_init($log['data'], 16));

            // 店舗ウォレット
            $storeWallet = strtolower(DB::table('wallets')
                ->where('store_id', $payment->store_id)
                ->value('address'));

            // expected amount（decimal対応）
            $expected = bcmul((string)$payment->amount, bcpow('10', '18'));

            // 検証
            if (
                $to === $storeWallet &&
                bccomp($amount, $expected) === 0
            ) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            return response()->json(['error' => 'Invalid transaction'], 400);
        }

        // ⑦ DB更新
        DB::table('payments')
            ->where('id', $id)
            ->update([
                'status' => 'confirmed',
                'tx_hash' => $txHash,
                'paid_at' => now(),
                'user_id' => $request->user()->id,
            ]);

        return response()->json(['success' => true]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1|max:100000'
        ]);


        $staff = $request->user();

        $storeId = $staff->store_id;
        $staffId = $staff->id;

        $payment = Payment::create([
            'store_id' => $storeId,
            'staff_id' => $staffId,
            'amount' => $request->amount,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10)
        ]);

        $payUrl = config('app.url') . "/pay/" . $payment->id;

        try {
            $qr = (string) QrCode::format('svg')->size(300)->generate($payUrl);
        } catch (\Exception $e) {
            \Log::error('QR ERROR', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'QR generation failed'], 500);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'amount' => $payment->amount,
            'pay_url' => $payUrl,
            'qr_code' => $qr
        ]);
    }

    public function show($id)
    {
        $payment = Payment::with('store')->findOrFail($id);

        return response()->json([
            'id' => $payment->id,
            'amount' => $payment->amount,
            'status' => $payment->status,
            'store_name' => $payment->store->name ?? 'Store',
            'expires_at' => $payment->expires_at
        ]);
    }

    public function status($id)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json([
                'error' => 'Not found'
            ], 404);
        }

        return response()->json([
            'status' => $payment->status
        ]);
    }
}