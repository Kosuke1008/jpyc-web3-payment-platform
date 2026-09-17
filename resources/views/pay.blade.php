<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>お支払い | Liv Terminal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 24px 16px;
            background: #f2f5f9;
            color: #111827;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .payment-container {
            width: 100%;
            max-width: 480px;
            margin: 32px auto;
        }

        .payment-card {
            padding: 32px 28px;
            background: #ffffff;
            border: 1px solid #e5eaf1;
            border-radius: 20px;
            box-shadow: 0 16px 42px rgba(15, 23, 42, 0.08);
            text-align: center;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 28px;
            color: #163c7a;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .brand-mark {
            display: inline-grid;
            width: 30px;
            height: 30px;
            place-items: center;
            border-radius: 9px;
            background: #163c7a;
            color: #ffffff;
            font-size: 17px;
        }

        .label {
            margin: 0 0 8px;
            color: #6b7280;
            font-size: 14px;
        }

        .store-name {
            margin: 0 0 20px;
            font-size: 18px;
            font-weight: 600;
        }

        .amount {
            margin: 0;
            font-size: clamp(40px, 10vw, 52px);
            font-weight: 800;
            letter-spacing: -0.04em;
            line-height: 1.15;
        }

        .currency {
            margin-left: 5px;
            color: #334155;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: 0;
        }

        .payment-meta {
            display: grid;
            gap: 9px;
            margin: 26px 0 0;
            padding: 18px;
            border: 1px solid #e8edf4;
            border-radius: 12px;
            background: #f8fafc;
            text-align: left;
            font-size: 13px;
        }

        .payment-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .payment-meta dt {
            color: #64748b;
        }

        .payment-meta dd {
            margin: 0;
            color: #334155;
            font-weight: 600;
            text-align: right;
        }

        .wallet-guidance {
            margin: 24px 0 0;
            color: #475569;
            font-size: 14px;
            line-height: 1.7;
            text-align: left;
        }

        button {
            width: 100%;
            min-height: 52px;
            margin-top: 12px;
            padding: 12px 20px;
            border: none;
            border-radius: 12px;
            background: #e8eef8;
            color: #163c7a;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover:not(:disabled) {
            background: #dae6f6;
        }

        button:disabled {
            background: #e5e7eb;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .wallet-launch-button {
            display: block;
            width: 100%;
            min-height: 56px;
            margin-top: 18px;
            padding: 15px 20px;
            border-radius: 12px;
            background: #163c7a;
            color: #ffffff;
            font-size: 17px;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 7px 18px rgba(22, 60, 122, 0.2);
        }

        .wallet-launch-button:hover {
            background: #102f61;
        }

        .wallet-launch-button--disabled {
            background: #9ca3af;
            box-shadow: none;
            cursor: not-allowed;
        }

        .secondary-button {
            margin-top: 14px;
            background: transparent;
            color: #475569;
            font-size: 14px;
        }

        .secondary-button:hover:not(:disabled) {
            background: #f1f5f9;
        }

        .metamask-actions {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #e8edf4;
        }

        .metamask-actions--hidden {
            display: none;
        }

        .metamask-note {
            margin: 10px 0 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }

        #status {
            min-height: 24px;
            margin-top: 20px;
            line-height: 1.6;
            font-weight: 600;
        }

        .status-success {
            color: #15803d;
        }

        .status-error {
            color: #dc2626;
        }

        .status-info {
            color: #1d4ed8;
        }

        .wallet-address {
            margin-top: 16px;
            color: #6b7280;
            font-size: 12px;
            word-break: break-all;
        }

        @media (max-width: 520px) {
            body {
                padding: 16px 12px;
            }

            .payment-container {
                margin: 8px auto;
            }

            .payment-card {
                padding: 28px 20px;
            }

            .payment-meta-row {
                align-items: baseline;
            }
        }
    </style>
</head>

<body>
    @php($isMainnet = $paymentSnapshot->network === 'kaia-mainnet')
    <main class="payment-container">
        <section class="payment-card">
            <div class="brand" aria-label="LivT">
                <span class="brand-mark" aria-hidden="true">L</span>
                <span>LivT</span>
            </div>

            <p class="label">支払先</p>

            <p class="store-name">
                {{ $payment->store->name ?? '店舗名未設定' }}
            </p>

            <p class="amount">
                {{ number_format((int) $paymentSnapshot->displayAmount) }}
                <span class="currency">{{ $paymentSnapshot->tokenSymbol }}</span>
            </p>

            <dl class="payment-meta">
                <div class="payment-meta-row">
                    <dt>ネットワーク</dt>
                    <dd>{{ $isMainnet ? 'Kaia Mainnet' : $networkProfile->chainName }}</dd>
                </div>
                <div class="payment-meta-row">
                    <dt>Payment ID</dt>
                    <dd>{{ $payment->id }}</dd>
                </div>
                <div class="payment-meta-row">
                    <dt>有効期限</dt>
                    <dd>{{ optional($paymentExpiresAt)?->timezone('Asia/Tokyo')->format('Y-m-d H:i:s') ?? '未設定' }} JST</dd>
                </div>
            </dl>

            @if ($isMainnet)
                <p class="wallet-guidance">LivT Walletでログイン後、支払い内容を確認して端末内で署名します。</p>
            @endif

            @if ($livtWalletPaymentUrl)
                <a
                    id="livt-wallet-button"
                    class="wallet-launch-button"
                    href="{{ $livtWalletPaymentUrl }}"
                >
                    LivT Walletで支払う
                </a>
            @elseif ($isMainnet)
                <span class="wallet-launch-button wallet-launch-button--disabled" aria-disabled="true">
                    LivT Walletで支払う
                </span>
            @endif

            <div class="metamask-actions{{ $isMainnet ? ' metamask-actions--hidden' : '' }}">
                <button id="connect-button" type="button" @disabled($isMainnet)>
                    MetaMaskを接続
                </button>
                <p class="metamask-note">
                    MetaMaskアプリが開いた場合は、接続後にもう一度支払うボタンを押してください。
                </p>
                <button id="pay-button" type="button" @disabled($isMainnet)>
                    {{ number_format((int) $paymentSnapshot->displayAmount) }} {{ $paymentSnapshot->tokenSymbol }}を支払う
                </button>
            </div>

            <button
                id="history-button"
                class="secondary-button"
                type="button"
                onclick="location.href='/user/payments'"
            >
                決済履歴を見る
            </button>

            <p id="status" class="status-info">
                {{ $isMainnet ? 'LivT Walletで支払いを開始してください' : 'ウォレットを接続してください' }}
            </p>

            <p id="wallet-address" class="wallet-address"></p>
        </section>
    </main>

    <script>
        window.LIVT_CONFIG = {
            network: @json($paymentSnapshot->network),
            networkProfileVersion: @json($paymentSnapshot->networkProfileVersion),
            chainId: @json((string) $paymentSnapshot->chainId),
            chainName: @json($networkProfile->chainName),
            rpcUrl: @json($isMainnet ? null : $networkProfile->rpcUrl),
            currencyName: @json($networkProfile->currencyName),
            currencySymbol: @json($networkProfile->currencySymbol),
            explorerUrl: @json($networkProfile->explorerUrl),
            tokenAddress: @json($paymentSnapshot->tokenContract),
            tokenDecimals: @json($paymentSnapshot->tokenDecimals),
            atomicAmount: @json($paymentSnapshot->atomicAmount),
            recipientAddress: @json($recipientAddress)
        };
    </script>

    <script type="module">
        import { ethers } from
            "https://unpkg.com/ethers@6.9.2/dist/ethers.min.js";

        const {
            network: TARGET_NETWORK,
            chainId,
            chainName: TARGET_CHAIN_NAME,
            rpcUrl: TARGET_RPC_URL,
            currencyName: TARGET_CURRENCY_NAME,
            currencySymbol: TARGET_CURRENCY_SYMBOL,
            explorerUrl: TARGET_EXPLORER_URL,
            tokenAddress: TOKEN_ADDRESS,
            tokenDecimals: TOKEN_DECIMALS,
            atomicAmount: PAYMENT_ATOMIC_AMOUNT,
            recipientAddress: STORE_WALLET
        } = window.LIVT_CONFIG;

        const TARGET_CHAIN_ID = BigInt(chainId);
        const TARGET_CHAIN_ID_HEX =
            "0x" + TARGET_CHAIN_ID.toString(16);

        const PAYMENT_ID = @json((string) $payment->id);
        const PAYMENT_AMOUNT = @json($paymentSnapshot->displayAmount);
        const PAYMENT_STATUS = @json((string) $payment->status);
        const HAS_LIVT_WALLET_OPTION = @json($livtWalletPaymentUrl !== null);
        const EXPIRES_AT = @json(
            optional($paymentExpiresAt)->toIso8601String()
        );

        let isPaying = false;
        let connectedAddress = null;
        let pollingTimer = null;

        const statusElement = document.getElementById("status");
        const walletAddressElement =
            document.getElementById("wallet-address");

        const connectButton =
            document.getElementById("connect-button");

        const payButton =
            document.getElementById("pay-button");

        /*
        |--------------------------------------------------------------------------
        | 初期化
        |--------------------------------------------------------------------------
        */

        initializePage();

        function initializePage() {
            if (PAYMENT_STATUS === "confirmed") {
                showSuccess("この決済は完了しています");
                disablePaymentButtons();
                return;
            }

            if (isExpired()) {
                showError("このQRコードは期限切れです");
                disablePaymentButtons();
                return;
            }

            /*
             * 支払い前にログイン状態を確認する。
             *
             * 送金後にtokenがないことへ気づくと、
             * ブロックチェーン上では送金済みなのにDBがpendingになるため、
             * 必ず送金より前に確認する。
             */
            const userToken = localStorage.getItem("user_token");

            if (!userToken && !HAS_LIVT_WALLET_OPTION) {
                saveReturnPathAndRedirectToLogin();
                return;
            }

            if (!userToken && TARGET_NETWORK !== "kaia-mainnet") {
                showInfo(
                    "MetaMaskで支払う場合はLivTへのログインが必要です。"
                );
            }

            startPolling();
        }

        //

        function openInMetaMask() {
            const dappUrl =
                window.location.host +
                window.location.pathname +
                window.location.search;

            const metaMaskDeepLink =
                `https://link.metamask.io/dapp/${dappUrl}`;

            window.location.href = metaMaskDeepLink;
        }

        async function ensureSepoliaNetwork() {
            if (!window.ethereum) {
                throw new Error(
                    "MetaMaskが見つかりません。MetaMaskアプリ内ブラウザで開いてください。"
                );
            }

            const currentChainId = await window.ethereum.request({
                method: "eth_chainId"
            });

            console.log("現在のchainId:", currentChainId);

            if (currentChainId.toLowerCase() === TARGET_CHAIN_ID_HEX) {
                return;
            }

            try {
                await window.ethereum.request({
                    method: "wallet_switchEthereumChain",
                    params: [
                        {
                            chainId: TARGET_CHAIN_ID_HEX
                        }
                    ]
                });
            } catch (error) {
                console.error(`${TARGET_CHAIN_NAME}切り替えエラー:`, error);

                // 4902は「MetaMaskにネットワークが登録されていない」
                if (error.code === 4902) {
                    await window.ethereum.request({
                        method: "wallet_addEthereumChain",
                        params: [
                            {
                                chainId: TARGET_CHAIN_ID_HEX,
                                chainName: TARGET_CHAIN_NAME,
                                nativeCurrency: {
                                    name: TARGET_CURRENCY_NAME,
                                    symbol: TARGET_CURRENCY_SYMBOL,
                                    decimals: 18
                                },
                                rpcUrls: [
                                    TARGET_RPC_URL
                                ],
                                blockExplorerUrls: [
                                    TARGET_EXPLORER_URL
                                ]
                            }
                        ]
                    });
const chainId = await window.ethereum.request({
    method: "eth_chainId"
});

console.log("chainId =", chainId);

statusElement.textContent = chainId;
                    await window.ethereum.request({
                        method: "wallet_switchEthereumChain",
                        params: [
                            {
                                chainId: TARGET_CHAIN_ID_HEX
                            }
                        ]
                    });

                    return;
                }

                if (error.code === 4001) {
                    throw new Error(
                        ` ${TARGET_CHAIN_NAME}への切り替えがキャンセルされました。`
                    );
                }

                throw new Error(
                    `${TARGET_CHAIN_NAME}への切り替えに失敗しました。MetaMaskで切り替えを承認してください。`
                );
            }

            // 実際に切り替わったか再確認
            const switchedChainId = await window.ethereum.request({
                method: "eth_chainId"
            });

            if (switchedChainId.toLowerCase() !== TARGET_CHAIN_ID_HEX) {
                throw new Error(
                    `ネットワークが${TARGET_CHAIN_NAME}ではありません。現在: ${switchedChainId}`
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | ウォレット接続
        |--------------------------------------------------------------------------
        */

        async function connectWallet() {
            try {
                if (!window.ethereum) {
                    showInfo("MetaMaskアプリを開いています...");
                    openInMetaMask();
                    return null;
                }

                statusElement.textContent =
                    "MetaMaskに接続しています...";

                await ensureSepoliaNetwork();

                const accounts = await window.ethereum.request({
                    method: "eth_requestAccounts"
                });

                if (!accounts || accounts.length === 0) {
                    throw new Error(
                        "接続可能なアカウントがありません。"
                    );
                }

                const provider =
                    new ethers.BrowserProvider(window.ethereum);

                const network = await provider.getNetwork();

                if (network.chainId !== TARGET_CHAIN_ID) {
                    throw new Error(
                        `${TARGET_CHAIN_NAME}への接続を確認できませんでした。chainId: ${network.chainId}`
                    );
                }

                const signer = await provider.getSigner();
                connectedAddress = await signer.getAddress();

                walletAddressElement.textContent =
                    `接続中：${shortenAddress(connectedAddress)}`;

                showSuccess(`${TARGET_CHAIN_NAME}に接続しました`);

                return {
                    provider,
                    signer,
                    address: connectedAddress
                };
            } catch (error) {
                console.error("ウォレット接続エラー:", error);

                showError(
                    error.message ||
                    "MetaMaskへの接続に失敗しました。"
                );

                throw error;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | ERC20決済
        |--------------------------------------------------------------------------
        */

        async function payWithERC20() {
            if (isPaying) {
                return;
            }

            if (!window.ethereum) {
                showInfo("MetaMaskアプリを開いています...");
                openInMetaMask();
                return;
            }

            const userToken = localStorage.getItem("user_token");

            if (!userToken) {
                saveReturnPathAndRedirectToLogin();
                return;
            }

            if (PAYMENT_STATUS === "confirmed") {
                showSuccess("この決済はすでに完了しています");
                disablePaymentButtons();
                return;
            }

            if (isExpired()) {
                showError("このQRコードは期限切れです");
                disablePaymentButtons();
                return;
            }

            isPaying = true;
            payButton.disabled = true;
            connectButton.disabled = true;

            try {
                showInfo("ウォレットに接続しています...");

                await ensureSepoliaNetwork();

                const provider =
                    new ethers.BrowserProvider(window.ethereum);

                const network = await provider.getNetwork();

                if (network.chainId !== TARGET_CHAIN_ID) {
                    throw new Error(
                        `MetaMaskのネットワークを${TARGET_CHAIN_NAME}へ切り替えてください`
                    );
                }

                const signer = await provider.getSigner();
                const userAddress = await signer.getAddress();

                connectedAddress = userAddress;

                walletAddressElement.textContent =
                    `接続中：${shortenAddress(userAddress)}`;

                const tokenContract = new ethers.Contract(
                    TOKEN_ADDRESS,
                    [
                        "function balanceOf(address) view returns (uint256)",
                        "function transfer(address to, uint256 amount) returns (bool)",
                        "function decimals() view returns (uint8)"
                    ],
                    signer
                );

                showInfo("残高を確認しています...");

                const decimals = await tokenContract.decimals();

                if (Number(decimals) !== TOKEN_DECIMALS) {
                    throw new Error(
                        "JPYCのdecimalsが支払い作成時の条件と一致しません"
                    );
                }

                const transferAmount = BigInt(PAYMENT_ATOMIC_AMOUNT);

                const balance =
                    await tokenContract.balanceOf(userAddress);

                if (balance < transferAmount) {
                    throw new Error("JPYC残高が不足しています");
                }

                console.log("送信元:", userAddress);
                console.log("送信先:", STORE_WALLET);
                console.log("支払額:", PAYMENT_AMOUNT);

                showInfo("MetaMaskで送金を承認してください...");

                const transaction =
                    await tokenContract.transfer(
                        STORE_WALLET,
                        transferAmount
                    );

                console.log(
                    "Transaction hash:",
                    transaction.hash
                );

                showInfo(
                    "ブロックチェーンの承認を待っています..."
                );

                await transaction.wait();

                showInfo("サーバーで決済を確認しています...");

                await confirmPayment(
                    transaction.hash,
                    userToken
                );

                showSuccess("決済が完了しました");
                disablePaymentButtons();

                if (pollingTimer) {
                    clearInterval(pollingTimer);
                    pollingTimer = null;
                }
            } catch (error) {
                console.error("Payment error:", error);

                showError(
                    error.shortMessage ||
                    error.reason ||
                    error.message ||
                    "決済処理中にエラーが発生しました"
                );

                payButton.disabled = false;
                connectButton.disabled = false;
            } finally {
                isPaying = false;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | confirm API
        |--------------------------------------------------------------------------
        */

        async function confirmPayment(txHash, userToken) {
            // [Flow M] 既存MetaMaskも同じbackend確認APIへtxHashを渡す。
            const response = await fetch(
                `/api/payments/${PAYMENT_ID}/confirm`,
                {
                    method: "POST",
                    headers: {
                        "Authorization": `Bearer ${userToken}`,
                        "Accept": "application/json",
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        tx_hash: txHash
                    })
                }
            );

            const data = await parseJsonResponse(response);

            if (response.status === 401) {
                clearUserSession();

                sessionStorage.setItem(
                    "redirect_after_login",
                    location.pathname
                );

                throw new Error(
                    "ログインの有効期限が切れています。ログインし直してください"
                );
            }

            if (!response.ok) {
                throw new Error(
                    data.message ??
                    data.error ??
                    `決済確認に失敗しました（HTTP ${response.status}）`
                );
            }

            if (!data.success) {
                throw new Error(
                    data.error ?? "決済確認に失敗しました"
                );
            }

            return data;
        }

        /*
        |--------------------------------------------------------------------------
        | 決済状態ポーリング
        |--------------------------------------------------------------------------
        */

        function startPolling() {
            if (pollingTimer) {
                clearInterval(pollingTimer);
            }

            pollingTimer = setInterval(
                checkPaymentStatus,
                3000
            );
        }

        async function checkPaymentStatus() {
            try {
                const response = await fetch(
                    `/api/payments/${PAYMENT_ID}`,
                    {
                        headers: {
                            "Accept": "application/json"
                        }
                    }
                );

                const data =
                    await parseJsonResponse(response);

                if (!response.ok) {
                    console.error(
                        "Payment status error:",
                        data
                    );
                    return;
                }

                if (data.status === "confirmed") {
                    showSuccess("決済が完了しました");
                    disablePaymentButtons();

                    clearInterval(pollingTimer);
                    pollingTimer = null;
                    return;
                }

                if (data.status === "failed") {
                    showError("決済に失敗しました");
                    disablePaymentButtons();

                    clearInterval(pollingTimer);
                    pollingTimer = null;
                    return;
                }

                if (isExpired()) {
                    showError("このQRコードは期限切れです");
                    disablePaymentButtons();

                    clearInterval(pollingTimer);
                    pollingTimer = null;
                }
            } catch (error) {
                console.error("Polling error:", error);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | レスポンス処理
        |--------------------------------------------------------------------------
        */

        async function parseJsonResponse(response) {
            const responseText = await response.text();

            try {
                return JSON.parse(responseText);
            } catch (error) {
                console.error(
                    "Non-JSON response:",
                    responseText
                );

                throw new Error(
                    `サーバーがJSONを返しませんでした（HTTP ${response.status}）`
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 共通処理
        |--------------------------------------------------------------------------
        */

        function isExpired() {
            if (!EXPIRES_AT) {
                return false;
            }

            return new Date(EXPIRES_AT).getTime() <
                Date.now();
        }

        function saveReturnPathAndRedirectToLogin() {
            sessionStorage.setItem(
                "redirect_after_login",
                location.pathname
            );

            alert("支払いを行うにはログインが必要です");

            location.href = "/user/login";
        }

        function clearUserSession() {
            localStorage.removeItem("user_token");
            localStorage.removeItem("user_name");
            localStorage.removeItem("user_id");
        }

        function disablePaymentButtons() {
            payButton.disabled = true;
            connectButton.disabled = true;
        }

        function shortenAddress(address) {
            if (!address || address.length < 12) {
                return address ?? "";
            }

            return `${address.slice(0, 6)}...${address.slice(-4)}`;
        }

        function showInfo(message) {
            statusElement.className = "status-info";
            statusElement.innerText = message;
        }

        function showSuccess(message) {
            statusElement.className = "status-success";
            statusElement.innerText = message;
        }

        function showError(message) {
            statusElement.className = "status-error";
            statusElement.innerText = message;
        }

        /*
        |--------------------------------------------------------------------------
        | イベント登録
        |--------------------------------------------------------------------------
        */

        connectButton.addEventListener(
            "click",
            async () => {
                try {
                    connectButton.disabled = true;

                    await connectWallet();
                } catch (error) {
                    console.error(error);
                    showError(
                        error.message ??
                        "ウォレット接続に失敗しました"
                    );
                } finally {
                    if (PAYMENT_STATUS !== "confirmed") {
                        connectButton.disabled = false;
                    }
                }
            }
        );

        payButton.addEventListener(
            "click",
            payWithERC20
        );

        window.addEventListener("beforeunload", () => {
            if (pollingTimer) {
                clearInterval(pollingTimer);
            }
        });
    </script>
</body>
</html>
