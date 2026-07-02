<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>支払い</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            text-align: center;
            font-family: sans-serif;
            padding: 20px;
        }
        button {
            font-size: 18px;
            padding: 12px 20px;
            margin-top: 20px;
            border-radius: 8px;
            border: none;
            background-color: #2563eb;
            color: white;
        }
        button:disabled {
            background-color: gray;
        }
    </style>
</head>

<body>

    <h1>お支払い</h1>

    <h2>{{ $payment->amount }} 円</h2>

    <p>{{ $payment->store->name ?? 'Store' }}</p>

    
<button onclick="connectWallet()">MetaMask接続</button>
<script src="/js/wallet.js"></script>

    <button id="pay-button">
        決済する ({{ $payment->amount }} JPYC)
    </button>

    <p id="status"></p>

    <script type="module">
    import { ethers } from "https://unpkg.com/ethers@6.9.2/dist/ethers.min.js";

    let isPaying = false;

    const TOKEN_ADDRESS = "0xff9409141aDb261CAEB1dA1F9975B1F48057D360";
    const STORE_WALLET = "0x923bFce1ac4D318441700f26Ad4ECaF39522e32A";
    const PAYMENT_AMOUNT = "{{ $payment->amount }}";
    const PAYMENT_ID = "{{ $payment->id }}";
    const EXPIRES_AT = "{{ $payment->expires_at }}";

    const ERC20_ABI = [
        "function balanceOf(address) view returns (uint256)",
        "function transfer(address to, uint256 amount) returns (bool)",
        "function decimals() view returns (uint8)"
    ];

    async function connectWallet() {
        if (!window.ethereum) {
    document.getElementById("status").innerText = "MetaMaskで開いてください";

    setTimeout(() => {
        location.href = `http://metamask.app.link/dapp/${location.host}/pay/${PAYMENT_ID}`;
    }, 1500);
}

        const accounts = await ethereum.request({ method: 'eth_requestAccounts' });
        return accounts[0];
    }

    async function payWithERC20() {

        if (isPaying) return;
        isPaying = true;

        const status = document.getElementById("status");
        const button = document.getElementById("pay-button");

        // 期限チェック
        if (EXPIRES_AT) {
            const now = new Date();
            const exp = new Date(EXPIRES_AT);
            if (exp < now) {
                alert("このQRは期限切れです");
                isPaying = false;
                return;
            }
        }

        try {
            status.innerText = "ウォレット接続中...";
            button.disabled = true;

            const userAddress = await connectWallet();
            if (!userAddress) throw new Error("ウォレット接続失敗");

            const provider = new ethers.BrowserProvider(window.ethereum);

            // ネットワークチェック（Sepolia）
            const network = await provider.getNetwork();
            if (network.chainId !== 11155111n) {
                alert("Sepoliaに切り替えてください");
                isPaying = false;
                button.disabled = false;
                return;
            }

            const signer = await provider.getSigner();
            const tokenContract = new ethers.Contract(TOKEN_ADDRESS, ERC20_ABI, signer);

            status.innerText = "金額計算中...";

            const decimals = await tokenContract.decimals();
            const amount = ethers.parseUnits(PAYMENT_AMOUNT.toString(), decimals);

            // 残高チェック
            const balance = await tokenContract.balanceOf(userAddress);
            if (balance < amount) {
                alert("残高不足です");
                isPaying = false;
                button.disabled = false;
                return;
            }

            console.log("送金元:", userAddress);
            console.log("送金先:", STORE_WALLET);

            status.innerText = "送金承認待ち...";

            const tx = await tokenContract.transfer(STORE_WALLET, amount);

            status.innerText = "ブロック承認待ち...";

            const receipt = await tx.wait();

            status.innerText = "決済確認中...";

            const userToken = localStorage.getItem("user_token");

            if (!userToken) {
                alert("ログインが必要です");
                location.href = "/user/login";
                return;
            }

            const res = await fetch(`/api/payments/${PAYMENT_ID}/confirm`, {
                method: "POST",
                headers: {
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                    "Authorization": `Bearer ${userToken}`
                },
                body: JSON.stringify({
                    tx_hash: tx.hash
                })
            });

            const data = await res.json();

            if (data.success) {
                status.innerText = "決済完了！";
            } else {
                status.innerText = "決済確認失敗";
                button.disabled = false;
                isPaying = false;
            }

        } catch (err) {
            console.error(err);
            alert("決済失敗: " + err.message);
            status.innerText = "エラーが発生しました";
            button.disabled = false;
            isPaying = false;
        }
    }

    document.getElementById("pay-button").addEventListener("click", payWithERC20);

    // ポーリング（3秒ごと）
    setInterval(async () => {
        try {
            const res = await fetch(`/api/payments/${PAYMENT_ID}`);
            const data = await res.json();

            if (data.status === 'confirmed') {
                document.getElementById("status").innerText = "決済完了！";
                document.getElementById("pay-button").disabled = true;
            }
        } catch (e) {
            console.log("polling error");
        }
    }, 3000);

    </script>

</body>
</html>