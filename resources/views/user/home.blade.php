<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ユーザーホーム</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family:sans-serif; padding:20px; text-align:center;">

<h1>ユーザーホーム</h1>

<p id="user-name"></p>

<a href="/user/payments-page">決済履歴を見る</a>

<br><br>

<button onclick="logout()">ログアウト</button>

<script>
const token = localStorage.getItem("user_token");
const name = localStorage.getItem("user_name");

if (!token) {
    location.href = "/user/login";
}

document.getElementById("user-name").innerText = `${name ?? "ユーザー"} さん`;

function logout() {
    localStorage.removeItem("user_token");
    localStorage.removeItem("user_name");
    location.href = "/user/login";
}
</script>

</body>
</html>