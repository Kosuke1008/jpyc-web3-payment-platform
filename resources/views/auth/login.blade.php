<h2>店舗ログイン</h2>

<input id="store_code" placeholder="店舗コード">
<input id="staff_id" placeholder="スタッフID">
<input id="pin" type="password" placeholder="PIN">

<button onclick="login()">ログイン</button>

<script>
async function login() {
    const store_code = document.getElementById('store_code').value;
    const staff_id   = document.getElementById('staff_id').value;
    const pin        = document.getElementById('pin').value;

    const res = await fetch('/api/staff/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ store_code, staff_id, pin })
    });

    const text = await res.text();
    console.log("Raw response:", text);

    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        alert("サーバーがJSONを返していません");
        return;
    }

    if (!res.ok) {
        alert(data.message || "ログイン失敗");
        return;
    }

    localStorage.setItem('token', data.token);
    window.location.href = '/pos';
}
</script>