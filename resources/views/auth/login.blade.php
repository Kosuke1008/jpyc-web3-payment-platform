<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>店舗ログイン</title>
</head>
<body>

<h2>店舗ログイン</h2>

<input id="store_code" placeholder="店舗コード">
<input id="staff_id" placeholder="スタッフID">
<input id="pin" type="password" placeholder="PIN">

<button id="login-button" type="button">ログイン</button>

<p id="status"></p>

<script>
const API_BASE_URL = '/api';

document.getElementById('login-button').addEventListener('click', login);

async function login() {
    const statusEl = document.getElementById('status');
    const button = document.getElementById('login-button');

    const storeCode = document.getElementById('store_code').value.trim();
    const staffId = document.getElementById('staff_id').value.trim();
    const pin = document.getElementById('pin').value;

    if (!storeCode || !staffId || !pin) {
        statusEl.innerText = 'すべて入力してください';
        return;
    }

    button.disabled = true;
    statusEl.innerText = 'ログイン中...';

    try {
        const response = await fetch(`${API_BASE_URL}/staff/login`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                store_code: storeCode,
                staff_id: staffId,
                pin: pin
            })
        });

        const data = await parseJsonResponse(response);

        if (!response.ok) {
            throw new Error(data.message ?? 'ログインに失敗しました');
        }

        localStorage.setItem('staff_token', data.token);
        localStorage.setItem('staff_name', data.staff?.name ?? '');
        localStorage.setItem('store_name', data.store?.name ?? '');
        localStorage.setItem('staff_id', String(data.staff?.id ?? ''));
        localStorage.setItem('store_id', String(data.store?.id ?? ''));

        location.href = '/pos';
    } catch (error) {
        console.error(error);
        statusEl.innerText = error.message;
        button.disabled = false;
    }
}

async function parseJsonResponse(response) {
    const text = await response.text();

    try {
        return JSON.parse(text);
    } catch {
        console.error('Non-JSON response:', text);
        throw new Error(`サーバーエラーが発生しました（HTTP ${response.status}）`);
    }
}
</script>

</body>
</html>