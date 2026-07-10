<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liv Terminal POS</title>
</head>
<body>

<h2>POS画面</h2>

<p id="account-info"></p>

<input
    id="amount"
    type="number"
    min="1"
    max="100000"
    step="1"
    placeholder="金額"
>

<button
    id="create-payment-button"
    type="button"
    onclick="createPayment()"
>
    決済開始
</button>

<button type="button" onclick="logoutStaff()">
    ログアウト
</button>

<div id="status"></div>

<div id="qr"></div>

<a id="url" target="_blank" rel="noopener noreferrer"></a>

<script src="/js/pos.js"></script>

</body>
</html>