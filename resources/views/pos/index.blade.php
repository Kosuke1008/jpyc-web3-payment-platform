<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS</title>
</head>


<body>

<h2>POS画面</h2>

<input id="amount" placeholder="金額">
<button onclick="createPayment()">決済開始</button>

<div id="status"></div>

<div id="qr"></div>
<a id="url" target="_blank"></a>


<script src="/js/pos.js"></script>
<!-- // async function createPayment() {
//     try {
//         // ローディング表示（任意）
//         const qrEl = document.getElementById('qr');
//         const urlEl = document.getElementById('url');
//         const statusEl = document.getElementById('status');

//         // DOM存在チェック（ここ重要）
//         if (!qrEl || !urlEl) {
//             console.error("Required DOM elements missing: #qr or #url");
//             return;
//         }

//         if (statusEl) {
//             statusEl.innerText = "決済を作成中...";
//         }

//         // APIリクエスト
//         const res = await fetch('/api/payments/create', {
//             method: 'POST',
//             headers: {
//                 'Content-Type': 'application/json',
//                 'Accept': 'application/json'
//             },
//             body: JSON.stringify({ amount: 100 })
//         });

//         // HTTPエラー処理
//         if (!res.ok) {
//             throw new Error(`HTTP error: ${res.status}`);
//         }

//         const data = await res.json();

//         console.log("Payment response:", data);

//         // QRコード表示（SVG想定）
//         if (data.qr_code) {
//             qrEl.innerHTML = data.qr_code;
//         } else {
//             qrEl.innerHTML = "<p>QRコード取得失敗</p>";
//         }

//         // URL表示
//         if (data.pay_url) {
//             urlEl.innerText = data.pay_url;
//             urlEl.href = data.pay_url; // aタグ想定の場合
//         } else {
//             urlEl.innerText = "URL取得失敗";
//         }

//         // ステータス更新
//         if (statusEl) {
//             statusEl.innerText = "QR生成完了";
//         }

//         return data;

//     } catch (e) {
//         console.error("createPayment error:", e);

//         const statusEl = document.getElementById('status');
//         if (statusEl) {
//             statusEl.innerText = "エラーが発生しました";
//         }
//     }
// } -->

</body>
</html>