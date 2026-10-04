'use strict';

const state = {
    context: null,
    selectedAmount: null,
    pollingTimer: null,
    countdownTimer: null,
    expiresAt: null,
};

document.addEventListener('DOMContentLoaded', async () => {
    if (!staffToken()) {
        redirectToLogin();
        return;
    }

    document.getElementById('logout-button').addEventListener('click', logoutStaff);
    document.getElementById('create-payment-button').addEventListener('click', createPayment);
    document.getElementById('next-payment-button').addEventListener('click', resetCheckout);
    document.getElementById('refresh-history-button').addEventListener('click', loadHistory);
    document.getElementById('amount').addEventListener('input', selectCustomAmount);

    await Promise.all([loadContext(), loadHistory()]);
});

async function loadContext() {
    try {
        const response = await authenticatedFetch('/api/staff/pos/context');
        const data = await parseJson(response);
        if (!response.ok) {
            throw new Error(data.error ?? '店舗設定を取得できませんでした。');
        }

        state.context = data;
        document.getElementById('store-name').textContent = data.store.name;
        document.getElementById('staff-name').textContent = `${data.staff.name} さん`;
        document.getElementById('network-name').textContent = data.network.name;
        document.getElementById('network-badge').textContent = data.network.name;
        document.getElementById('network-badge').classList.toggle('is-mainnet', !data.network.testnet);
        document.getElementById('store-wallet').textContent = data.wallet
            ? abbreviateAddress(data.wallet.address)
            : '設定を確認してください';
        document.getElementById('amount-limit').textContent = `上限 ${formatAmount(data.maximum_amount)} ${data.network.token_symbol}`;
        document.getElementById('amount').setAttribute('aria-label', `その他の金額（1から${data.maximum_amount} JPYC）`);

        renderProducts(data.products);
        updateCreationAvailability();
    } catch (error) {
        showGlobalStatus(errorMessage(error), true);
        document.getElementById('create-payment-button').disabled = true;
    }
}

function renderProducts(products) {
    const container = document.getElementById('product-buttons');
    container.replaceChildren();

    for (const product of products) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'product-button';
        button.dataset.amount = String(product.amount);

        const name = document.createElement('span');
        name.textContent = product.name;
        const price = document.createElement('strong');
        price.textContent = `${formatAmount(product.amount)} JPYC`;
        button.append(name, price);
        button.addEventListener('click', () => selectProduct(product.amount, button));
        container.append(button);
    }
}

function selectProduct(amount, button) {
    document.getElementById('amount').value = '';
    document.querySelectorAll('.product-button').forEach((candidate) => {
        candidate.classList.toggle('is-selected', candidate === button);
    });
    setSelectedAmount(String(amount));
}

function selectCustomAmount(event) {
    document.querySelectorAll('.product-button').forEach((button) => button.classList.remove('is-selected'));
    setSelectedAmount(event.target.value.trim());
}

function setSelectedAmount(rawAmount) {
    const valid = isValidAmount(rawAmount);
    state.selectedAmount = valid ? Number(rawAmount) : null;
    document.getElementById('selected-amount').textContent = valid ? formatAmount(rawAmount) : '—';

    if (rawAmount !== '' && !valid) {
        showFormStatus(`1〜${formatAmount(state.context?.maximum_amount ?? 0)} JPYCの整数を入力してください。`, true);
    } else {
        showFormStatus('', false);
    }
    updateCreationAvailability();
}

function isValidAmount(rawAmount) {
    if (!state.context || !/^[1-9]\d*$/.test(String(rawAmount))) {
        return false;
    }
    const amount = Number(rawAmount);
    return Number.isSafeInteger(amount) && amount <= state.context.maximum_amount;
}

function updateCreationAvailability() {
    const button = document.getElementById('create-payment-button');
    const available = Boolean(state.context?.creation_available);
    button.disabled = !available || state.selectedAmount === null;

    if (!state.context) {
        return;
    }
    if (!state.context.ready) {
        showGlobalStatus('Store Walletまたはネットワーク設定を確認してください。決済作成は停止しています。', true);
    } else if (!available) {
        showGlobalStatus('現在は決済作成ゲートが停止中です。設定確認後に利用可能になります。', true);
    } else {
        showGlobalStatus('決済を作成できます。商品または金額を選択してください。', false);
    }
}

async function createPayment() {
    if (state.selectedAmount === null || !state.context?.creation_available) {
        updateCreationAvailability();
        return;
    }

    const button = document.getElementById('create-payment-button');
    button.disabled = true;
    showFormStatus('決済データとQRコードを作成しています。', false);

    try {
        const response = await authenticatedFetch('/api/payments/create', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({amount: state.selectedAmount}),
        });
        const data = await parseJson(response);
        if (!response.ok) {
            throw new Error(paymentCreationError(response.status, data));
        }

        showPayment(data);
        showFormStatus('', false);
        await loadHistory();
    } catch (error) {
        showFormStatus(errorMessage(error), true);
        updateCreationAvailability();
    }
}

function showPayment(payment) {
    stopTimers();
    state.expiresAt = payment.expires_at ? new Date(payment.expires_at) : null;

    document.getElementById('checkout-panel').hidden = true;
    document.getElementById('qr-panel').hidden = false;
    document.getElementById('qr-amount').textContent = formatAmount(payment.amount);
    document.getElementById('payment-id').textContent = String(payment.payment_id);
    document.getElementById('payment-recipient').textContent = abbreviateAddress(payment.recipient_address);

    const link = document.getElementById('payment-url');
    link.href = payment.pay_url;
    link.textContent = 'お客様用決済ページを開く';

    const qrImage = document.createElement('img');
    qrImage.src = `data:image/svg+xml;base64,${payment.qr_code_base64}`;
    qrImage.alt = `決済ID ${payment.payment_id} のQRコード`;
    document.getElementById('qr').replaceChildren(qrImage);

    updatePaymentStatus('pending');
    updateCountdown();
    state.countdownTimer = window.setInterval(updateCountdown, 1000);
    state.pollingTimer = window.setInterval(() => pollPayment(payment.payment_id), 3000);
}

async function pollPayment(paymentId) {
    try {
        const response = await fetch(`/api/payments/status/${encodeURIComponent(paymentId)}`, {
            headers: {Accept: 'application/json'},
        });
        const data = await parseJson(response);
        if (!response.ok) {
            return;
        }

        updatePaymentStatus(data.status);
        if (['confirmed', 'failed', 'expired'].includes(data.status)) {
            stopTimers();
            await loadHistory();
        }
    } catch (_) {
        // A transient status-fetch failure must not create or resend a payment.
    }
}

function updatePaymentStatus(status) {
    const badge = document.getElementById('payment-status-badge');
    const labels = {
        pending: '支払い待ち',
        confirmed: '支払い完了',
        failed: '決済失敗',
        expired: '期限切れ',
    };
    badge.textContent = labels[status] ?? '確認中';
    badge.className = `status-badge status-${status}`;
}

function updateCountdown() {
    const countdown = document.getElementById('countdown');
    if (!(state.expiresAt instanceof Date) || Number.isNaN(state.expiresAt.getTime())) {
        countdown.textContent = '';
        return;
    }
    const seconds = Math.max(0, Math.ceil((state.expiresAt.getTime() - Date.now()) / 1000));
    const minutes = Math.floor(seconds / 60);
    const remainder = String(seconds % 60).padStart(2, '0');
    countdown.textContent = seconds > 0 ? `有効期限まで ${minutes}:${remainder}` : 'このQRコードは期限切れです。';
}

async function loadHistory() {
    const body = document.getElementById('history-body');
    try {
        const response = await authenticatedFetch('/api/staff/payments');
        const data = await parseJson(response);
        if (!response.ok) {
            throw new Error(data.error ?? '履歴を取得できませんでした。');
        }
        renderHistory(data.payments);
    } catch (error) {
        body.replaceChildren(tableMessage(errorMessage(error)));
    }
}

function renderHistory(payments) {
    const body = document.getElementById('history-body');
    body.replaceChildren();
    if (payments.length === 0) {
        body.append(tableMessage('このネットワークの決済履歴はまだありません。'));
        return;
    }

    for (const payment of payments) {
        const row = document.createElement('tr');
        const created = document.createElement('td');
        const id = document.createElement('td');
        const amount = document.createElement('td');
        const status = document.createElement('td');

        created.textContent = formatDate(payment.created_at);
        id.textContent = `#${payment.id}`;
        amount.textContent = `${formatAmount(payment.amount)} ${payment.token_symbol}`;
        const badge = document.createElement('span');
        badge.className = `status-badge status-${payment.status}`;
        badge.textContent = statusLabel(payment.status);
        status.append(badge);
        row.append(created, id, amount, status);
        body.append(row);
    }
}

function tableMessage(message) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = 4;
    cell.className = 'empty-cell';
    cell.textContent = message;
    row.append(cell);
    return row;
}

function resetCheckout() {
    stopTimers();
    state.expiresAt = null;
    state.selectedAmount = null;
    document.getElementById('checkout-panel').hidden = false;
    document.getElementById('qr-panel').hidden = true;
    document.getElementById('amount').value = '';
    document.getElementById('selected-amount').textContent = '—';
    document.querySelectorAll('.product-button').forEach((button) => button.classList.remove('is-selected'));
    document.getElementById('qr').replaceChildren();
    updateCreationAvailability();
}

function stopTimers() {
    if (state.pollingTimer !== null) {
        window.clearInterval(state.pollingTimer);
        state.pollingTimer = null;
    }
    if (state.countdownTimer !== null) {
        window.clearInterval(state.countdownTimer);
        state.countdownTimer = null;
    }
}

async function authenticatedFetch(url, options = {}) {
    const headers = new Headers(options.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${staffToken()}`);
    const response = await fetch(url, {...options, headers});
    if (response.status === 401) {
        clearStaffSession();
        redirectToLogin();
        throw new Error('ログインの有効期限が切れました。');
    }
    return response;
}

async function parseJson(response) {
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('application/json')) {
        throw new Error('サーバーから正しい応答を受け取れませんでした。');
    }

    return response.json();
}

function paymentCreationError(status, data) {
    if (status === 422) {
        return '金額を確認してください。入力できるのは上限以内の整数です。';
    }
    if (status === 429) {
        return '決済作成回数の上限に達しました。自動で再試行せず、管理者へ確認してください。';
    }
    if (status === 503) {
        return '安全確認ゲートが停止中のため決済を作成できません。管理者へ確認してください。';
    }
    return data.message ?? data.error ?? '決済を作成できませんでした。';
}

function showGlobalStatus(message, isError) {
    const element = document.getElementById('global-status');
    element.textContent = message;
    element.classList.toggle('is-error', isError);
}

function showFormStatus(message, isError) {
    const element = document.getElementById('create-status');
    element.textContent = message;
    element.classList.toggle('is-error', isError);
}

function statusLabel(status) {
    return ({pending: '支払い待ち', confirmed: '完了', failed: '失敗', expired: '期限切れ'})[status] ?? status;
}

function formatAmount(amount) {
    return Number(amount).toLocaleString('ja-JP');
}

function formatDate(value) {
    if (!value) {
        return '—';
    }
    return new Intl.DateTimeFormat('ja-JP', {
        month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
    }).format(new Date(value));
}

function abbreviateAddress(address) {
    if (typeof address !== 'string' || address.length < 12) {
        return address ?? '—';
    }
    return `${address.slice(0, 8)}…${address.slice(-6)}`;
}

function errorMessage(error) {
    return error instanceof Error ? error.message : '処理中にエラーが発生しました。';
}

function staffToken() {
    return localStorage.getItem('staff_token');
}

function logoutStaff() {
    clearStaffSession();
    redirectToLogin();
}

function clearStaffSession() {
    localStorage.removeItem('staff_token');
    localStorage.removeItem('staff_name');
    localStorage.removeItem('store_name');
}

function redirectToLogin() {
    window.location.assign('/login');
}
