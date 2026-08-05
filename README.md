# LivT（Liv Terminal）

LivTは、店舗でJPYC決済を受け付けるLaravelベースの決済プラットフォームです。

POSでの決済作成から、ブロックチェーンtransactionの検証、DB上の決済確定までを
一貫して管理します。現在のWeb3実証環境はKaia Kairosです。

## 主な機能

- スタッフ認証とPOS向け決済作成
- 利用者認証と支払い履歴
- 店舗Wallet・金額・有効期限を含むQR/URL決済
- 既存MetaMask決済
- 非カストディ型LivT Walletとの連携
- LivT Fee PayerによるKairos gas代負担
- receiptとJPYC `Transfer` eventのサーバー側検証
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

## 技術構成

- Laravel / PHP
- MySQL
- Laravel Sanctum
- Blade / JavaScript / ethers.js
- Kaia Kairos RPC
- JPYC ERC-20

## 開発・テスト

環境変数は`.env.example`を参照し、秘密情報をGitへcommitしないでください。

```bash
composer install
php artisan test
vendor/bin/pint --test
```

## 関連リポジトリ

- `livt-wallet`: React/TypeScript製の非カストディ型ブラウザWallet
- `livt-fee-payer`: Kairos用のセルフホスト型Fee Payer sidecar

## ドキュメント

- `docs/livt-wallet-integration-plan.md`: 段階的なWallet統合計画
- `docs/livt-wallet-payment-flow.md`: 支払い工程と実装箇所
- `docs/kairos-fee-delegation-proof.md`: Kairos Fee Delegation実証手順
- `docs/kairos-fee-payer-review.md`: 3リポジトリ横断のレビュー資料

## 現在の範囲

Fee Payer機能はKairos実証限定です。Mainnetでは、KMS/HSM、永続的な冪等性、
利用上限、監査ログ、残高監視、緊急停止を追加するまで有効化しません。
