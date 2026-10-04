<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>決済履歴</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }

        h1 {
            text-align: center;
        }

        .payment-card {
            background: white;
            padding: 16px;
            margin: 12px 0;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .amount {
            font-size: 22px;
            font-weight: bold;
        }

        .store {
            font-size: 16px;
            margin-top: 6px;
        }

        .date {
            color: #666;
            font-size: 14px;
            margin-top: 6px;
        }

        .status {
            color: green;
            font-weight: bold;
            margin-top: 6px;
        }

        .tx {
            font-size: 12px;
            color: #555;
            word-break: break-all;
            margin-top: 8px;
        }

        button {
            width: 100%;
            padding: 12px;
            margin-top: 16px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-size: 16px;
        }
    </style>
</head>

<body>

<h1>決済履歴</h1>

<div id="status">読み込み中...</div>
<div id="payments"></div>

<button onclick="location.href='/user/home'">ホームへ戻る</button>

<script>
async function loadPayments() {
    const token = localStorage.getItem("user_token");
    const status = document.getElementById("status");
    const paymentsDiv = document.getElementById("payments");

    if (!token) {
        alert("ログインしてください");
        location.href = "/user/login";
        return;
    }

    try {
        const res = await fetch("/api/user/payments", {
            method: "GET",
            headers: {
                "Authorization": `Bearer ${token}`,
                "Accept": "application/json"
            }
        });

        const data = await res.json();

        if (!res.ok) {
            status.textContent = data.message ?? "決済履歴の取得に失敗しました";
            return;
        }

        status.textContent = "";

        if (!Array.isArray(data.payments) || data.payments.length === 0) {
            const emptyMessage = document.createElement("p");
            emptyMessage.textContent = "決済履歴はありません。";
            paymentsDiv.replaceChildren(emptyMessage);
            return;
        }

        const cards = data.payments.map(payment => {
            const card = document.createElement("div");
            card.className = "payment-card";

            appendTextElement(card, "amount", `${payment.amount ?? ""} JPYC`);
            appendTextElement(card, "store", payment.store_name ?? "");
            appendTextElement(card, "status", payment.status ?? "");
            appendTextElement(card, "date", payment.paid_at ?? "");
            appendTextElement(card, "tx", `tx: ${payment.tx_hash ?? ""}`);

            return card;
        });

        paymentsDiv.replaceChildren(...cards);

    } catch (error) {
        console.error(error);
        status.textContent = "通信エラーが発生しました";
    }
}

function appendTextElement(parent, className, value) {
    const element = document.createElement("div");
    element.className = className;
    element.textContent = String(value);
    parent.appendChild(element);
}

loadPayments();
</script>

</body>
</html>
