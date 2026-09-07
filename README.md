# LivT（Liv Terminal）

LivTは、店舗でJPYC決済を受け付けるLaravelベースの決済プラットフォームです。

POSでの決済作成から、ブロックチェーンtransactionの検証、DB上の決済確定までを
一貫して管理します。現在のWeb3実証環境はKaia Kairosです。

新規Paymentは作成時のnetwork、chain ID、JPYC contract/symbol/decimals、店舗送金先、
表示金額、atomic amount、有効期限を不変snapshotとして保存します。作成後にactive
network、店舗Wallet、token設定が変わっても、既存Paymentの意味は変わりません。

## 主な機能

- スタッフ認証とPOS向け決済作成
- 利用者認証と支払い履歴
- 店舗Wallet・金額・有効期限を含むQR/URL決済
- 既存MetaMask決済
- 非カストディ型LivT Walletとの連携
- LivT Fee PayerによるKairos gas代負担
- receiptとJPYC `Transfer` eventのサーバー側検証
- transaction・receipt・canonical blockの整合検査と確認証拠の保存
- confirmed Paymentのread-only reconciliation
- 重複tx hash、誤送金、期限切れ決済の拒否

## 決済フロー

```text
POS
  └─ pending決済を作成
       ↓ payment ID / URL
MetaMask または LivT Wallet
  └─ 利用者がtransactionへ署名・送信
       ↓ transaction hash
LivT Laravel
  ├─ chain IDとreceiptをRPCから取得
  ├─ JPYC contract・送金先・金額・statusを検証
  └─ 検証成功後だけDBをconfirmedへ更新
```

LivT WalletのFee Delegated決済では、送信前にLaravelがsender署名済みtransactionを
検査し、同一ホストのLivT Fee Payerへ追加署名とbroadcastを依頼します。最終確定は
MetaMaskと同じ共通verifierを利用します。

## セキュリティ方針

- clientが申告する「成功」を信用しません。
- tx hashだけでは支払い完了と判定しません。
- chain、receipt status、JPYC contract、送金先、金額、重複利用を検証します。
- RPC検証が完了するまでDB transactionやrow lockを保持しません。
- Walletのmnemonic、秘密鍵、パスワード、復号済み情報を受け取りません。
- Fee Payer秘密鍵はLaravelで保持しません。
- RPC・receipt・Transfer検証に失敗した場合、paymentはpendingのまま維持します。

## 対応する支払い方式

| 方式 | 署名・gas負担 | 最終確認 |
|:---|:---|:---|
| MetaMask | MetaMask利用者 | 共通`PaymentTransactionVerifier` |
| LivT Wallet直接送信 | Wallet利用者 | 共通`PaymentTransactionVerifier` |
| LivT Wallet Fee Delegated | senderはWallet、gasはLivT Fee Payer | 共通`PaymentTransactionVerifier` |

## 主なAPI

```text
POST /api/staff/login
POST /api/user/login
POST /api/payments/create
GET  /api/payments/{id}
GET  /api/payments/{id}/sponsorship
POST /api/payments/{id}/sponsor
POST /api/payments/{id}/confirm
GET  /api/user/payments
```

`GET /api/payments/{id}`の支払い条件（`network`、`chain_id`、
`token_contract`、`token_decimals`、`recipient_address`、`display_amount`、
`atomic_amount`、`expires_at`）はPayment snapshotから返します。snapshotを持たない
legacy Paymentを現在設定から推測することはせず、安全に表示・検証できない場合は拒否します。

確認成功時はtx hash、観測chain ID、block number/hash、receipt status、実payer、
Transfer log index、block timestamp、検証時刻をPaymentへ保存します。`paid_at`はLivTが
確認を受理した時刻、`chain_confirmed_at`はchain上のblock timestampです。

## 技術構成

- Laravel / PHP
- MySQL
- Laravel Sanctum
- Blade / JavaScript / ethers.js
- Kaia Kairos RPC
- JPYC ERC-20

## 開発・テスト

環境変数は`.env.example`を参照し、秘密情報をGitへcommitしないでください。

Blockchain networkは`APP_ENV`から推測せず、明示的に選びます。既存のKairos
実行には次を設定します。`kaia-mainnet` profileも解決できますが、Phase 4でも
決済作成とFee Delegationをコード上で無効にしています。

```dotenv
BLOCKCHAIN_NETWORK=kairos
BLOCKCHAIN_KAIROS_RPC_URL=https://public-en-kairos.node.kaia.io
PAYMENTS_MAINNET_ENABLED=false
MAINNET_FEE_DELEGATION_ENABLED=false
MAINNET_BROADCAST_ENABLED=false
```

```bash
composer install
php artisan test
vendor/bin/pint --test
```

保存済み確認証拠は、transactionを送信しない次のcommandで再照合できます。

```bash
php artisan payments:reconcile 123
```

Legacy PaymentのMainnet移行監査（既定はread-only）:

```bash
php artisan payments:audit-mainnet-migration
```

Kaia Mainnetのread-only RPC readiness:

```bash
php artisan blockchain:mainnet-readiness
```

必要な分離設定と実行順序は`docs/mainnet-readiness-runbook.md`を参照してください。readiness成功時もMainnet Payment、署名、Fee Delegation、broadcastは無効です。

## 関連リポジトリ

- `livt-wallet`: React/TypeScript製の非カストディ型ブラウザWallet
- `livt-fee-payer`: Kairos用のセルフホスト型Fee Payer sidecar

## ドキュメント

- `docs/livt-wallet-integration-plan.md`: 段階的なWallet統合計画
- `docs/livt-wallet-payment-flow.md`: 支払い工程と実装箇所
- `docs/kairos-fee-delegation-proof.md`: Kairos Fee Delegation実証手順
- `docs/kairos-fee-payer-review.md`: 3リポジトリ横断のレビュー資料
- `docs/mainnet-migration-plan.md`: Kaia Mainnet移行の段階設計

## 現在の範囲

Fee Payer機能はKairos実証限定です。Mainnetでは、KMS/HSM、永続的な冪等性、
利用上限、監査ログ、残高監視、緊急停止を追加するまで有効化しません。
Phase 1でNetwork Profile、Phase 2でPayment Snapshot、Phase 3でConfirmation
Evidence / Kaia Finality検証、Phase 4でDB hardeningとread-only readinessを実装しました。Mainnetでは
決済作成、confirmation、Wallet署名、Fee Delegation、broadcastを引き続き無効にしており、
Mainnet対応完了または本番readyではありません。
