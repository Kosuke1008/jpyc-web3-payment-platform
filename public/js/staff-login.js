'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('staff-login-form');
    const button = document.getElementById('login-button');
    const status = document.getElementById('status');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const storeCode = document.getElementById('store_code').value.trim();
        const staffId = document.getElementById('staff_id').value.trim();
        const pin = document.getElementById('pin').value;

        if (!storeCode || !staffId || !pin) {
            showStatus(status, '店舗コード、スタッフID、PINをすべて入力してください。', true);
            return;
        }

        button.disabled = true;
        showStatus(status, 'ログインしています。', false);

        try {
            const response = await fetch('/api/staff/login', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    store_code: storeCode,
                    staff_id: staffId,
                    pin,
                }),
            });
            const data = await parseJson(response);

            if (!response.ok) {
                if (response.status === 401) {
                    throw new Error('店舗コード、スタッフID、またはPINを確認してください。');
                }
                if (response.status === 429) {
                    throw new Error('ログイン試行が多すぎます。しばらく待ってから再度お試しください。');
                }
                throw new Error('ログインできませんでした。店舗管理者へ確認してください。');
            }

            localStorage.setItem('staff_token', data.token);
            localStorage.setItem('staff_name', data.staff?.name ?? '');
            localStorage.setItem('store_name', data.store?.name ?? '');
            window.location.assign('/pos');
        } catch (error) {
            showStatus(status, error instanceof Error ? error.message : 'ログインできませんでした。', true);
            button.disabled = false;
        }
    });
});

function showStatus(element, message, isError) {
    element.textContent = message;
    element.classList.toggle('is-error', isError);
}

async function parseJson(response) {
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('application/json')) {
        throw new Error('サーバーから正しい応答を受け取れませんでした。');
    }

    return response.json();
}
