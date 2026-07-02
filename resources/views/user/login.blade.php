<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ユーザーログイン</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:sans-serif; padding:20px; text-align:center;">

<h1>ログイン</h1>

<input id="email" type="email" placeholder="メールアドレス"><br><br>
<input id="password" type="password" placeholder="パスワード"><br><br>

<button onclick="login()">ログイン</button>

<p id="status"></p>

<script>
async function login() {
    const status = document.getElementById("status");

    const res = await fetch("/api/user/login", {
        method: "POST",
        headers: {
            "Accept": "application/json",
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            email: document.getElementById("email").value,
            password: document.getElementById("password").value
        })
    });

    const data = await res.json();

    if (res.ok && data.token) {
        localStorage.setItem("user_token", data.token);
        localStorage.setItem("user_name", data.user.name);
        status.innerText = "ログイン成功";
        location.href = "/user/home";
    } else {
        status.innerText = data.message ?? "ログイン失敗";
    }
}
</script>

</body>
</html>