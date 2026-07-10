let pollingTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    const staffToken = localStorage.getItem('staff_token');

    if (!staffToken) {
        alert('店舗スタッフとしてログインしてください');
        location.href = '/login';
        return;
    }

    const storeName = localStorage.getItem('store_name');
    const staffName = localStorage.getItem('staff_name');

    const accountInfo = document.getElementById('account-info');

    if (accountInfo) {
        accountInfo.innerText =
            `${storeName || '店舗'} / ${staffName || 'スタッフ'}`;
    }
});

async function createPayment() {
    const amountInput = document.getElementById('amount');
    const qrEl = document.getElementById('qr');
    const urlEl = document.getElementById('url');
    const statusEl = document.getElementById('status');
    const button = document.getElementById('create-payment-button');

    const amount = Number(amountInput.value);
    const staffToken = localStorage.getItem('staff_token');

    if (!staffToken) {
        alert('ログイン情報がありません');
        location.href = '/login';
        return;
    }

    if (!Number.isFinite(amount) || amount < 1 || amount > 100000) {
        statusEl.innerText = '1〜100000の金額を入力してください';
        return;
    }

    button.disabled = true;
    statusEl.innerText = '決済を作成中...';
    qrEl.innerHTML = '';
    urlEl.innerText = '';
    urlEl.removeAttribute('href');

    try {
        const response = await fetch('/api/payments/create', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${staffToken}`,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                amount: amount
            })
        });

        const data = await parseJsonResponse(response);

        if (response.status === 401) {
            clearStaffSession();
            alert('ログインの有効期限が切れました');
            location.href = '/login';
            return;
        }

        if (!response.ok) {
            throw new Error(
                data.message ??
                data.error ??
                '決済作成に失敗しました'
            );
        }

        qrEl.innerHTML = data.qr_code;
        urlEl.innerText = data.pay_url;
        urlEl.href = data.pay_url;

        statusEl.innerText =
            `決済待機中：${Number(data.amount).toLocaleString()} JPYC`;

        startPolling(data.payment_id);
    } catch (error) {
        console.error(error);
        statusEl.innerText = error.message;
        button.disabled = false;
    }
}

function startPolling(paymentId) {
    const statusEl = document.getElementById('status');
    const button = document.getElementById('create-payment-button');

    if (pollingTimer) {
        clearInterval(pollingTimer);
    }

    pollingTimer = setInterval(async () => {
        try {
            const response = await fetch(
                `/api/payments/status/${paymentId}`,
                {
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            const data = await parseJsonResponse(response);

            if (!response.ok) {
                throw new Error(
                    data.message ??
                    data.error ??
                    '決済状態を取得できません'
                );
            }

            if (data.status === 'confirmed') {
                clearInterval(pollingTimer);
                pollingTimer = null;

                statusEl.innerText = '支払い完了';
                button.disabled = false;
                return;
            }

            if (data.status === 'failed') {
                clearInterval(pollingTimer);
                pollingTimer = null;

                statusEl.innerText = '決済に失敗しました';
                button.disabled = false;
            }
        } catch (error) {
            console.error('Polling error:', error);
        }
    }, 3000);
}

function logoutStaff() {
    clearStaffSession();
    location.href = '/login';
}

function clearStaffSession() {
    localStorage.removeItem('staff_token');
    localStorage.removeItem('staff_name');
    localStorage.removeItem('store_name');
    localStorage.removeItem('staff_id');
    localStorage.removeItem('store_id');
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