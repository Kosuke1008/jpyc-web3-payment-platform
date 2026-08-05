# Kairos Fee Delegation 実証手順

## 対象範囲

この実証では、LivT WalletがJPYC `transfer` transactionへ送信者署名だけを行い、Laravelが独立したLivT Fee Payer sidecarへFee Payer署名とbroadcastを依頼します。Unifi APIとKaia管理Fee Delegation Serviceは使用しません。

```text
LivT Wallet
  └─ sender-only署名（秘密情報は端末外へ出さない）
       ↓ sender_signed_tx
Laravel /api/payments/{id}/sponsor
  ├─ pending・期限・店舗Wallet・金額をDB正本から確認
  ├─ 0x31 RLP、chain 1001、JPYC、recipient、amount、value、gasを検査
  ├─ sender-only RLPへ検証専用のダミーFee Payer欄を内部付加
  ├─ kaia_recoverFromTransactionでsender署名を確認（検証用RLPはbroadcastしない）
  ├─ payment単位の試行予約で二重依頼を防止
  └─ 認証付きloopback HTTPでLivT Fee Payerへ1回だけ依頼
       ↓
LivT Fee Payer sidecar
  ├─ Kairos・0x31・JPYC・transfer・value・gasを再検査
  ├─ LivTのKairos専用Fee Payer鍵で署名
  ├─ kaia_recoverFromTransactionでsender署名を再確認
  ├─ 実Kairosへ1回だけbroadcast
  └─ receipt成功またはrevertをLaravelへ返す
       ↓ final transaction hash
LivT Wallet
  └─ 既存 /api/payments/{id}/confirm
       ↓
既存 PaymentTransactionVerifier
  └─ receiptとJPYC Transferを再検証後だけDBをconfirmedへ更新
```

スポンサーAPI自体はPaymentを更新しません。MetaMaskと既存の直接送信は従来どおりです。

## 安全境界

- 機能は既定で無効です。
- `WEB3_NETWORK=kairos`かつchain ID 1001のときだけ有効になります。
- Mainnetではfail-closedです。
- runnerが起動ごとに生成する内部BearerはLaravelとsidecarだけが保持し、Walletへ返しません。
- Fee Payer秘密鍵はsidecarの`.env`だけが保持し、Laravelへ渡しません。
- mnemonic、private key、Walletパスワード、復号済みWallet情報はLaravelへ送りません。
- Fee PayerへのHTTP呼び出し中にDB transactionやrow lockを保持しません。
- LivT Fee PayerにはWalletが生成した元のsender-only RLPだけを渡し、LaravelのRPC検証用に補ったRLPは渡しません。
- Kairos実証では既存のfile Cacheにpayment単位の試行を予約し、同一rawの成功応答だけを再利用します。別rawや結果不明rawは再送しません。
- Fee Payerへの依頼は自動retryしません。結果不明時は再署名・再送を止めます。

## 実行前提

- Laravelの現在の実DBにstaff、LivT user、店舗Walletが存在すること
- LivT Walletの送信元に1 JPYC以上あること
- 送信元のKAIAは不要
- `/home/kosuke/projects/livt-fee-payer`で`corepack pnpm setup:kairos`を一度だけ実行し、Kairos専用Fee Payerを作成すること
- Fee Payer公開アドレスにKairos Faucetから必要最小限のKAIAを用意すること
- runner生成の内部BearerとFee Payer秘密鍵をコマンド履歴、ブラウザ、ログ、ドキュメントへ貼らないこと
- `storage/framework/cache`をLaravel processが読み書きできること。`KAIA_FEE_DELEGATION_CACHE_STORE=file`を実証中は維持すること

## Edgeでの実証

Wallet repositoryで次を実行します。

```bash
cd /home/kosuke/projects/livt-wallet
corepack pnpm review:kairos-fee-delegated-live
```

runnerは既存の実Kairos reviewと同じく、migration、seed、reset、自動送金を行いません。確認語句 `LIVE KAIROS FEE DELEGATION` の入力後だけLaravelとWalletを起動します。

画面では次を確認します。

1. 支払い内容に「決済手数料はLivTが負担します」と表示される
2. 送信元のKAIAがなくても署名ボタンが有効になる
3. 必要なら署名前に「自分のKAIAで手数料を支払う」へ切り替えられる（Fee Payerの失敗後に自動切替はしない）
4. Walletパスワードでsender-only署名を行う
5. 「お支払いが確認されました」まで進む
6. runnerが実DBの`confirmed`、最終tx hash、実Kairos receipt、JPYC Transfer、Fee Payer addressを照合する

## 結果不明時

LivT Fee PayerまたはKairos RPCへの接続がbroadcast前後で切れた場合、画面は「Fee Payerの結果を確認できません」と表示します。この場合は新しいtransactionを作成せず、画面を保持して管理者がKairos上の状態を確認します。

SenderTxHashからの自動復旧は、利用RPCでsender transaction hash indexingを保証できるようにしてから別工程で追加します。

## Rollback

runnerを停止するとLaravel、Wallet、sidecarがまとめて停止します。通常環境では`KAIA_FEE_DELEGATION_ENABLED=false`に戻してLaravel processを再起動します。任意のsponsorship capability APIがfalseを返し、LivT Walletは既存の直接送信を表示します。route、DB schema、MetaMask flow、共通transaction verifierの変更やrollbackは不要です。

## Mainnetへ進む前の必須作業

- chain ID 8217とMainnet用Fee Payerを別設定として追加する
- Fee Payer署名鍵をKMS/HSMへ移し、sidecar processから秘密鍵を分離する
- raw transaction inspectorとgas policyを独立レビューする
- senderとLivT userのWallet address紐付け方針を決める
- SenderTxHashによる結果不明transactionの復旧を実装する
- Cacheのflush・eviction・複数hostに耐える永続的なsponsorship attempt管理を追加する
- rate limit、利用上限、監査ログ、残高監視を追加する

これらが完了するまでMainnetでは有効化しません。
