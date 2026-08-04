# LivT Wallet 支払いフロー実装対応表

この資料の`Flow A`〜`Flow U`は、LaravelとLivT Walletのコードコメントで共通利用するレビュー用識別子です。Walletが表示する成功状態をLaravelは信用せず、`Flow N`以降でKairosの実データを再検証します。

## フローチャート

```mermaid
flowchart TD
    A["A スタッフがPOSへログイン<br/>StaffAuthController::login"] --> B["B pending支払いを作成<br/>PaymentController::create"]
    B --> C["C payment IDと支払いURLを返す<br/>PaymentController::create"]
    C --> D["D payment_idでWallet支払い画面を開く<br/>PayController::livtWalletPaymentUrl<br/>App::readStartupPaymentIntegration"]

    D --> E["E LivT APIから正本の支払い情報を取得<br/>PaymentController::show<br/>apiClient.getPaymentDetails"]
    E --> F{"F 支払い状態・chain・token・金額を検証<br/>createLivtPaymentIntent"}
    F -- "不正・期限切れ" --> G["G 送金せずエラー表示<br/>LivtPaymentPanel"]
    F -- "有効" --> H["H 店舗・金額・送金先などを表示<br/>LivtPaymentPanel"]

    H --> I["I LivT利用者を決済限定tokenで認証<br/>PaymentAuthenticationController::login<br/>apiClient.login"]
    I --> J["J ブラウザ内で署名<br/>executeJpycTransfer"]
    J --> K["K 署名済みJPYC TransferをKairosへ送信<br/>rpcClient.sendRawTransaction"]
    K --> L["L txHashを取得・保存<br/>executeJpycTransfer<br/>rememberTransactionHash"]

    L --> M["M 共通確認APIへtxHashを送る<br/>apiClient.confirmPayment<br/>PaymentController::confirm"]
    M --> N["N backendがKairos RPCを照会<br/>PaymentTransactionVerifier::verifyAndConfirm"]
    N --> O{"O receiptが存在し成功しているか<br/>fetchSuccessfulReceipt"}
    O -- "未確定・失敗・RPC異常" --> P["P DBを更新せずpendingを維持<br/>PaymentVerificationException"]
    O -- "成功" --> Q["Q JPYC contract・recipient・amountを照合<br/>assertMatchingTransfer"]

    Q --> R["R DB transaction内で状態を再確認<br/>DB::transaction + lockForUpdate"]
    R --> S["S paymentをconfirmedへ更新<br/>verifyAndConfirm"]
    S --> T["T tx_hash・user_id・paid_atを記録<br/>verifyAndConfirm"]
    T --> U["U Walletへ確認完了を表示<br/>LivtPaymentPanel"]
```

## 工程と実装箇所

表内の矢印（`→`）は、同じFlow内での呼び出し順を示します。

### 1. POSで支払いを作成してWalletを開く

| Flow | 主体 | 処理 | 実装（呼び出し順） |
|:---:|---|---|---|
| **A** | Laravel | POSスタッフを認証 | `app/Http/Controllers/Api/StaffAuthController.php`<br>`login()` |
| **B** | Laravel | pending支払いを作成 | `app/Http/Controllers/Api/PaymentController.php`<br>`create()` |
| **C** | Laravel | payment ID・支払いURL・QRを返す | `app/Http/Controllers/Api/PaymentController.php`<br>`create()`のresponse |
| **D** | Laravel → Wallet | `payment_id`だけをWalletへ渡して支払い画面を開く | `app/Http/Controllers/PayController.php` `livtWalletPaymentUrl()`<br>→ `apps/web/src/app/App.tsx` `readStartupPaymentIntegration()` |
| **E** | Wallet ↔ Laravel | backendを正本として支払い情報を取得 | `apps/web/src/payments/livtPaymentApi.ts` `getPaymentDetails()`<br>→ `app/Http/Controllers/Api/PaymentController.php` `show()` |

### 2. Walletで内容を検証・表示・送信する

| Flow | 主体 | 処理 | 実装（呼び出し順） |
|:---:|---|---|---|
| **F** | Wallet | ID・状態・期限・chain・token・金額・残高を検証 | `apps/web/src/payments/livtPaymentIntent.ts`<br>`createLivtPaymentIntent()` |
| **G** | Wallet | 検証失敗時は送金せずエラー表示 | `apps/web/src/app/LivtPaymentPanel.tsx`<br>`paymentValidation` → `displayedDetailsError` |
| **H** | Wallet | backend由来の支払い内容を表示 | `apps/web/src/app/LivtPaymentPanel.tsx`<br>`LivtPaymentPanel()`の確認画面 |
| **I** | Wallet ↔ Laravel | LivT利用者を決済限定tokenで認証 | `LivtPaymentPanel.tsx` `handleLogin()`<br>→ `livtPaymentApi.ts` `login()`<br>→ `PaymentAuthenticationController.php` `login()` |
| **J** | Wallet | 秘密情報を外へ出さず端末内で署名 | `apps/web/src/tokens/jpycTransfer.ts`<br>`executeJpycTransfer()` → `account.signTransaction()` |
| **K** | Wallet → Kairos | 署名済みtransactionを一度だけ送信 | `apps/web/src/tokens/jpycTransfer.ts`<br>`rpcClient.sendRawTransaction()` |
| **L** | Wallet | txHashを算出・取得してブラウザへ保存 | `jpycTransfer.ts` `executeJpycTransfer()`<br>→ `LivtPaymentPanel.tsx` `rememberTransactionHash()` |

### 3. 共通backendでtransactionを検証・確定する

| Flow | 主体 | 処理 | 実装（呼び出し順） |
|:---:|---|---|---|
| **M** | Wallet / MetaMask → Laravel | txHashを共通確認APIへ送る | Wallet: `livtPaymentFlow.ts` `executeLivtPayment()` → `livtPaymentApi.ts` `confirmPayment()`<br>MetaMask: `resources/views/pay.blade.php` `confirmPayment()`<br>共通入口: `PaymentController.php` `confirm()` |
| **N** | Laravel → Kairos | chain IDとreceiptをRPC照会 | `app/Services/Payments/PaymentTransactionVerifier.php`<br>`verifyAndConfirm()` → `verifyChainId()` / `rpcResult()` |
| **O** | Laravel → Kairos | receiptを既存上限まで待ち、成功状態を確認 | `PaymentTransactionVerifier.php`<br>`fetchSuccessfulReceipt()` |
| **P** | Laravel | RPC・receipt・Transfer検証失敗時はDBを更新しない | `PaymentTransactionVerifier.php`<br>`verifyAndConfirm()`のDB transaction開始前 |
| **Q** | Laravel | JPYC contract・recipient・amountを照合 | `PaymentTransactionVerifier.php`<br>`assertMatchingTransfer()` |
| **R** | Laravel / DB | row lock下で支払い状態とTransferを再確認 | `PaymentTransactionVerifier.php`<br>`DB::transaction()` → `lockForUpdate()` |
| **S** | Laravel / DB | paymentを`confirmed`へ更新 | `PaymentTransactionVerifier.php`<br>`verifyAndConfirm()`の`update()` |
| **T** | Laravel / DB | `tx_hash`・`user_id`・`paid_at`を保存 | `PaymentTransactionVerifier.php`<br>`verifyAndConfirm()`の同じ`update()` |

### 4. Walletへ確定結果を表示する

| Flow | 主体 | 処理 | 実装 |
|:---:|---|---|---|
| **U** | Wallet | backend確認後に確定状態とtxHashを表示 | `apps/web/src/app/LivtPaymentPanel.tsx`<br>`submissionStatus === 'confirmed'` |

## 手動Kairosレビュー時の入口

今回のEdge手動レビューでは、`apps/web/e2e/payment/support/run-live-kairos-review.mjs`が入力されたpayment IDを安全確認してから、`Flow D`と同じ`payment_id`付きWallet URLを開きました。これは本番支払いロジックではなく、実DB・実Kairosを使ってA〜Uを追跡するためのレビュー補助です。

## セキュリティ境界

- `Flow J`では署名がブラウザ内で完結し、mnemonic・private key・WalletパスワードをLaravelへ送りません。
- `Flow M`で送られるのは決済限定Bearer tokenとtxHashです。
- `Flow N`〜`Q`は送金元UIに依存しないため、MetaMaskとLivT Walletが同じ検証経路を使います。
- RPC呼び出しは`Flow R`のDB transactionより前に完了します。
- `Flow O`または`Q`で失敗した場合、`Flow S`〜`T`へ進まずpaymentはpendingのままです。
