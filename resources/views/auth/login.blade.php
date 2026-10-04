<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>店舗スタッフログイン | LivT</title>
    <link rel="stylesheet" href="/css/pos.css">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-card" aria-labelledby="login-title">
        <div class="brand-mark" aria-hidden="true">L</div>
        <p class="eyebrow">LivT STORE</p>
        <h1 id="login-title">店舗スタッフログイン</h1>
        <p class="lead">店舗コードとスタッフ情報を入力してください。</p>

        <form id="staff-login-form" novalidate>
            <label for="store_code">店舗コード</label>
            <input id="store_code" name="store_code" type="text" autocomplete="organization" maxlength="255" required>

            <label for="staff_id">スタッフID</label>
            <input id="staff_id" name="staff_id" type="text" autocomplete="username" maxlength="255" required>

            <label for="pin">PIN</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="current-password" maxlength="255" required>

            <button id="login-button" class="primary-button" type="submit">ログイン</button>
        </form>

        <p id="status" class="form-status" role="status" aria-live="polite"></p>
    </section>
</main>
<script src="/js/staff-login.js" defer></script>
</body>
</html>
