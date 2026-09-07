# Kairos managed Fee Delegation single-test runbook

状態: Phase 7準備完了。live transaction未実施。Mainnet実行は禁止。

## 1. Preconditions

- Phase 6 migration `payment_fee_delegation_attempts`が対象Kairos DBへ適用済みである。
- 試験専用のpending Paymentを1件だけ作成し、金額を1 JPYC、networkを`kairos`、chain IDを1001にする。
- 送信wallet、店舗recipient、JPYC contract、Payment IDをoperator二名で照合する。
- 同じPaymentにattemptが存在しないことを確認する。存在する場合は新規送信せずresolverを使う。
- Walletはtype `0x31`のsender-signed RLPを生成できる。
- Kairos RPCでSenderTxHash indexingが有効である。

## 2. Kaia application and access

Kaiaの公式application formにはまだ送信しない。申請時は次を依頼・確認する。

- Kairos Fee Delegation ServiceへのDApp access
- backend専用API key
- Kairos JPYC contract `0xe7c3d8c9a439fede00d2600032d5db0be71c3c29`のwhitelist
- 必要なDApp/project/account identifierと連絡先
- Kairosで利用可能なsponsorship balance、補充方法、alert条件
- type `0x31` `FeeDelegatedSmartContractExecution`とJPYC `transfer()`の受理可否
- duplicate、timeout、5xx、内部retry、status lookupの契約

公式資料ではTestnetはvalidationなしとも記載されるが、Mainnet移行前提の互換性確認としてJPYC contract
whitelistを依頼する。whitelistはLivTのPayment policyの代替ではない。

## 3. Required backend environment variables

以下はserver backendだけに設定する。API keyの値をshell history、chat、issue、repositoryへ貼らない。

```text
BLOCKCHAIN_NETWORK=kairos
BLOCKCHAIN_KAIROS_RPC_URL=<SenderTxHash indexing対応Kairos RPC>
KAIA_FEE_DELEGATION_ENABLED=true
FEE_DELEGATION_MODE=kaia-managed
KAIA_FEE_DELEGATION_URL=https://fee-delegation-kairos.kaia.io
KAIA_FEE_DELEGATION_API_KEY=<secret managerから注入>
KAIROS_MANAGED_LIVE_TEST_ENABLED=true
KAIROS_MANAGED_LIVE_TEST_PAYMENT_ID=<選択した1件のPayment ID>
KAIROS_MANAGED_LIVE_TEST_MAX_JPYC=1
PAYMENTS_MAINNET_ENABLED=false
MAINNET_FEE_DELEGATION_ENABLED=false
MAINNET_BROADCAST_ENABLED=false
```

`VITE_*`へAPI keyを設定しない。設定変更後はdeploy方式に従ってLaravel config cacheを再生成する。

## 4. Funding assumptions

- 送信者はJPYC 1枚を保有する。native KAIAはmanaged serviceがnetwork feeを負担するため原則不要だが、
  最初の試験前にKaia teamへ確認する。
- 公式`GET /api/balance`はsponsorship balanceが0.1 KAIA超かをbooleanで返す。readiness commandは
  providerへ接続しないため、残高はKaia dashboardまたは承認済みの別read-only手順で確認する。
- balance不足、DApp未activation、whitelist未反映なら試験を開始しない。

## 5. Readiness

```bash
cd /home/nakas/projects/jpyc-web3-payment-platform
php artisan optimize:clear
php artisan blockchain:kairos-managed-readiness
```

全checkが`ready`で、末尾が次になることを要求する。

```text
provider_call=not_performed
mainnet_execution=disabled
readiness=ready
```

このcommandはKairos chain ID、latest block、SenderTxHash indexingをread-only RPCで照会する。
`signAsFeePayer`、署名、broadcast、`/api/balance`は呼ばない。API key値も表示しない。

## 6. Pure dry run

```bash
php artisan payments:prepare-managed-kairos-test <PAYMENT_ID>
```

promptへsender-signed type `0x31` RLPを非表示入力する。次をoperatorがWallet表示と照合する。

- Payment ID
- sender address
- recipient address
- JPYC amount = 1
- chain ID = 1001
- SenderTxHash
- request fingerprint

期待する末尾:

```text
attempt_written=no
provider_call=not_performed
broadcast=not_performed
dry_run=ready
```

dry runはPayment/attemptを変更しない。RLPは出力・log保存しない。

## 7. The future single live action

Phase 7では実行しない。別途明示承認を受けたwindowでのみ、次を1回だけ行う。

1. browserで選択済みPaymentをLivT Walletに開く。
2. network、sender、recipient、1 JPYCを再確認する。
3. fee delegationを選択し、最終送信buttonを**1回だけ**押す。
4. spinner、timeout、errorのいずれでも再度押さない。別providerへ切り替えない。

Laravelはprovider call前にattemptを`reserved -> validated -> submitting`へcommitする。成功時は
`submitted -> receipt_observed -> confirmed`となる。managed responseのtransaction hashだけではPaymentを
confirmedにせず、既存on-chain verifierがreceipt、Transfer event、sender、recipient、amountを検証する。

## 8. Expected evidence

- managed service: `status=true`, `message`, transaction receipt data、valid transaction hash
- attempt: Paymentに一意な1行、provider=`kaia-managed`
- Kairos RPC: SenderTxHashとtransaction hashが同じfee-delegated transactionを指す
- Payment: verifier成功後だけ`confirmed`
- reconciliation: `php artisan payments:reconcile <PAYMENT_ID>`がanomalyなし

収集するlog fieldsはPayment ID、attempt ID、provider、SenderTxHash、transaction hash、diagnostic code、
state transition、RPC resolution resultだけとする。

## 9. Unknown submission

timeout、connection reset、429、5xx、malformed response、response lossでは**再送しない**。

```bash
php artisan payments:resolve-fee-delegation-attempts --payment=<PAYMENT_ID>
```

1. attemptが`unknown_submission`であることを確認する。
2. resolverを実行し、SenderTxHashでKairos RPCをread-only照会する。
3. `not_found`なら待って同じresolverだけを再実行する。provider POSTは行わない。
4. transaction/receipt発見後は既存verifierへ進める。
5. broadcast不成立を証明するauthoritative evidenceがない限り、安全なretryとは分類しない。

## 10. Stop conditions

次のいずれかでlive action前に停止する。

- readinessまたはdry runのcheckが1件でもfailed
- Paymentが1 JPYCでない、pendingでない、snapshot不完全または期限切れ
- sender、recipient、contract、chain ID、SenderTxHashの不一致
- attemptが既に存在する
- SenderTxHash indexing無効
- API key、whitelist、balance、DApp activationが未確認
- Mainnet flagが1つでもtrue
- endpointがKairos managed endpointでない
- browserに再送を促す挙動、secret露出、予期しないprovider callを検出

## 11. Rollback / shutdown

1. `KAIROS_MANAGED_LIVE_TEST_ENABLED=false`に戻し、config cacheを再生成する。
2. `KAIA_FEE_DELEGATION_ENABLED=false`に戻す。
3. attemptやPayment evidenceを削除・書換えしない。
4. unknown/submitted attemptはresolver/reconciliationによるread-only確認を継続する。
5. self-hostedへ自動fallbackしない。別Paymentでの再試験は新たな承認を必要とする。

## 12. Redaction and Mainnet prohibition

収集物からAPI key、Authorization header、private key、seed phrase、cookie、access token、raw RLPを除外する。
Mainnet keyを読み込まず、production Mainnet Fee Delegation endpointを呼ばず、3つのMainnet execution flagを
falseのまま維持する。

## 13. Official references

- [Kaia Managed Fee Delegation Service integration](https://docs.kaia.io/build/tutorials/integrate-fee-delegation-service/)
- [Kaia fee-delegated transaction types](https://docs.kaia.io/build/transactions/fee-delegation/)
- [SenderTxHash transaction lookup](https://docs.kaia.io/references/json-rpc/kaia/get-transaction-by-sender-tx-hash/)
