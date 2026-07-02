async function createPayment() {
    try {
        const amount = document.getElementById('amount').value;

        const qrEl = document.getElementById('qr');
        const urlEl = document.getElementById('url');
        const statusEl = document.getElementById('status');

        if (!qrEl || !urlEl) return;

        statusEl.innerText = "決済作成中...";

        const res = await fetch('/api/payments/create', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ amount })
        });

        const data = await res.json();

        qrEl.innerHTML = data.qr_code;
        urlEl.innerText = data.pay_url;
        urlEl.href = data.pay_url;

        statusEl.innerText = "QR表示完了";

        // 重要：状態監視開始
        startPolling(data.payment_id);

    } catch (e) {
        console.error(e);
    }
}

async function startPolling(paymentId) {
    const statusEl = document.getElementById('status');

    const interval = setInterval(async () => {

        const res = await fetch(`/api/payments/status/${paymentId}`);
        const data = await res.json();

        console.log(data);

        if (data.status === 'paid') {
            clearInterval(interval);
            statusEl.innerText = "支払い完了";
        }

        if (data.status === 'failed') {
            clearInterval(interval);
            statusEl.innerText = "失敗";
        }

    }, 3000);
}