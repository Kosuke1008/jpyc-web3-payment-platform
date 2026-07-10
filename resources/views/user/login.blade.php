<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザーログイン</title>
</head>
<body>

<h1>ログイン</h1>

<input id="email" type="email" placeholder="メールアドレス">
<input id="password" type="password" placeholder="パスワード">

<button id="login-button" type="button">ログイン</button>

<p id="status"></p>

<script>
document.getElementById('login-button').addEventListener('click', login);

document.getElementById('password').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        login();
    }
});

async function login() {
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const statusEl = document.getElementById('status');
    const button = document.getElementById('login-button');

    if (!email || !password) {
        statusEl.innerText = 'メールアドレスとパスワードを入力してください';
        return;
    }

    button.disabled = true;
    statusEl.innerText = 'ログイン中...';

    try {
        const response = await fetch('/api/user/login', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                email,
                password
            })
        });

        const data = await parseJsonResponse(response);

        if (!response.ok) {
            throw new Error(
                data.message ??
                data.errors?.email?.[0] ??
                'ログインに失敗しました'
            );
        }

        if (!data.token || !data.user) {
            throw new Error('ログインレスポンスが不正です');
        }

        localStorage.setItem('user_token', data.token);
        localStorage.setItem('user_name', data.user.name ?? '');
        localStorage.setItem('user_id', String(data.user.id ?? ''));

        statusEl.innerText = 'ログイン成功';

        const redirectTo =
            sessionStorage.getItem('redirect_after_login');

        sessionStorage.removeItem('redirect_after_login');

        location.href = isSafeRedirectPath(redirectTo)
            ? redirectTo
            : '/user/home';

    } catch (error) {
        console.error('Login error:', error);
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

        throw new Error(
            `サーバーがJSONを返しませんでした（HTTP ${response.status}）`
        );
    }
}

function isSafeRedirectPath(path) {
    if (!path || typeof path !== 'string') {
        return false;
    }

    return path.startsWith('/') && !path.startsWith('//');
}
</script>

</body>
</html>