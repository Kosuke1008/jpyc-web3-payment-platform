<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>店舗決済 | LivT</title>
    <link rel="stylesheet" href="/css/pos.css">
</head>
<body class="pos-page">
<header class="topbar">
    <a class="brand" href="/pos" aria-label="LivT 店舗決済ホーム">
        <span class="brand-mark" aria-hidden="true">L</span>
        <span>LivT Store</span>
    </a>
    <div class="topbar-actions">
        <span id="network-badge" class="network-badge">確認中</span>
        <button id="logout-button" class="text-button" type="button">ログアウト</button>
    </div>
</header>

<main class="pos-shell">
    <section class="store-summary" aria-labelledby="store-name">
        <div>
            <p class="eyebrow">STORE TERMINAL</p>
            <h1 id="store-name">店舗情報を確認中</h1>
            <p id="staff-name" class="muted"></p>
        </div>
        <dl class="wallet-summary">
            <div>
                <dt>受取先 Store Wallet</dt>
                <dd id="store-wallet">—</dd>
            </div>
            <div>
                <dt>利用ネットワーク</dt>
                <dd id="network-name">—</dd>
            </div>
        </dl>
    </section>

    <p id="global-status" class="global-status" role="status" aria-live="polite">店舗設定を確認しています。</p>

    <div class="pos-grid">
        <section id="checkout-panel" class="panel" aria-labelledby="checkout-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">NEW PAYMENT</p>
                    <h2 id="checkout-title">お会計を作成</h2>
                </div>
                <span id="amount-limit" class="small-note"></span>
            </div>

            <p class="field-label">商品を選ぶ</p>
            <div id="product-buttons" class="product-grid" aria-label="商品一覧"></div>

            <div class="custom-amount">
                <label for="amount">その他の金額</label>
                <div class="amount-input-wrap">
                    <input id="amount" type="text" inputmode="numeric" autocomplete="off" placeholder="例: 10" aria-describedby="amount-help">
                    <span>JPYC</span>
                </div>
                <p id="amount-help" class="small-note">1 JPYC単位の整数で入力してください。</p>
            </div>

            <div class="selection-summary">
                <span>決済金額</span>
                <strong><span id="selected-amount">—</span> JPYC</strong>
            </div>

            <button id="create-payment-button" class="primary-button" type="button" disabled>QRコードを作成</button>
            <p id="create-status" class="form-status" role="status" aria-live="polite"></p>
        </section>

        <section id="qr-panel" class="panel qr-panel" aria-labelledby="qr-title" hidden>
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">PAYMENT QR</p>
                    <h2 id="qr-title">お客様に提示</h2>
                </div>
                <span id="payment-status-badge" class="status-badge status-pending">待機中</span>
            </div>

            <p class="qr-amount"><strong id="qr-amount">—</strong> <span>JPYC</span></p>
            <div id="qr" class="qr-box" aria-label="決済QRコード"></div>
            <p id="countdown" class="countdown"></p>
            <a id="payment-url" class="payment-link" target="_blank" rel="noopener noreferrer"></a>

            <dl class="payment-meta">
                <div><dt>決済ID</dt><dd id="payment-id">—</dd></div>
                <div><dt>受取先</dt><dd id="payment-recipient">—</dd></div>
            </dl>

            <button id="next-payment-button" class="secondary-button" type="button">次のお会計へ</button>
        </section>
    </div>

    <section class="panel history-panel" aria-labelledby="history-title">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">RECENT PAYMENTS</p>
                <h2 id="history-title">決済履歴</h2>
            </div>
            <button id="refresh-history-button" class="text-button" type="button">更新</button>
        </div>
        <p class="small-note">現在の店舗・ネットワークの履歴だけを表示します。</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>日時</th><th>決済ID</th><th>金額</th><th>状態</th></tr>
                </thead>
                <tbody id="history-body">
                    <tr><td colspan="4" class="empty-cell">履歴を読み込んでいます。</td></tr>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script src="/js/pos.js" defer></script>
</body>
</html>
