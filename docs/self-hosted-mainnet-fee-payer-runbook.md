# Self-hosted Kaia Mainnet Fee Payer runbook

状態: Phase 8構造準備済み。execution/signing/broadcast、Mainnet staging、fundingは未実施。

## 1. Architecture and ownership

```text
Wallet -- sender-signed 0x31 --> Laravel policy + DB attempt ledger
                                     |
                                     | one authenticated request
                                     v
                              LivT Fee Payer
                              signer -> primary RPC broadcast once
                                     |
                                     v
                         Laravel receipt/evidence verifier
```

LaravelがPayment、attempt、idempotency、rate/budgetの正本である。Fee PayerのMapは同一process内の
重複抑止だけで、restart後の正本ではない。Fee Payerはtransaction policy再検査、追加署名、primary RPCへの
1回のbroadcastだけを所有する。

## 2. Network, wallet, and RPC separation

- Mainnet profileにchain ID、JPYC、decimals、primary/secondary RPCと実行policyを集約する。
- Mainnet public RPCはcommercial providerとして拒否する。
- secondaryはreadiness/read-only専用で、broadcast failoverに使わない。
- Mainnet/Kairos Fee Payer addressを明示し、同一identityをdefault拒否する。
- pilotではcross-network identity overrideを使用しない。

## 3. Signer and key custody

`FeePayerSigner`がkey custodyをpolicy/RLP/broadcastから分離する。Phase 8はKairos専用
`LocalPrivateKeyFeePayerSigner`だけを実装し、Mainnet signer factoryは必ずfail closedとなる。

Mainnetはdedicated wallet、KMS/HSM/external signer、isolated service account、secret manager、最小KAIA残高を
使用する。Mainnet private keyを`.env`、repository、Laravel ledger、logへ置かない。process-local Mainnet keyが
設定されていれば起動時に拒否する。

## 4. Gates and kill switch

Phase 8ではすべて次の状態を維持する。

```text
PAYMENTS_MAINNET_ENABLED=false
MAINNET_FEE_DELEGATION_ENABLED=false
MAINNET_BROADCAST_ENABLED=false
SELF_HOSTED_MAINNET_FEE_PAYER_ENABLED=false
FEE_PAYER_MAINNET_ENABLED=false
FEE_PAYER_MAINNET_SIGNING_ENABLED=false
FEE_PAYER_MAINNET_BROADCAST_ENABLED=false
FEE_PAYER_KILL_SWITCH=true
```

kill switch activeでは新規signing/broadcastを拒否するが、Laravel resolver/reconciliationとreadinessは継続する。

## 5. Budget and rate limits

Laravelはshared cache lock内でDB attempt履歴を検査し、そのlockを保持したままattempt作成transactionをcommitする。

- max Payment JPYC / gas
- max gas priceによる保守的fee reservation
- per user/store/sender/global time-window count
- daily transaction count / KAIA budget
- Paymentごとのunique attempt

過去attemptは`max gas × max gas price`で保守的に見積もる。実費会計ではないため、本格運用前にreceipt gas evidenceを
永続化する。初回pilot案は1 merchant、allowlisted users、1 JPYC、各主体1 attempt/hour、global 5/hour以下、
最大10件/day以下、手動監視とする。

## 6. Balance monitoring and readiness

```bash
cd /home/nakas/projects/livt-fee-payer
corepack pnpm readiness:mainnet
```

primaryからchain ID、JPYC bytecode/symbol/decimals、latest block、native KAIA balanceをread-only取得する。
secondaryはchain ID/latest blockだけ確認する。最低reserve未満、timeout、public RPC、同一wallet、open execution gateで
failする。成功条件はMainnet signing/broadcast無効、kill switch active、external signer addressだけ設定済みであり、
実行許可を意味しない。

## 7. Unknown submission and RPC outage

broadcast timeout/reset/ambiguous RPC errorでは自動retryしない。Laravelは`unknown_submission`へ遷移する。

```bash
php artisan payments:resolve-fee-delegation-attempts --payment=<PAYMENT_ID>
```

RPC outage中は新規送信を止め、read-only lookupだけを再実行する。primary failure後にsecondaryやmanaged providerへ
broadcastしない。authoritativeな未broadcast証明がなければretryしない。

## 8. Emergency shutdown and key compromise

1. Laravel/Fee Payer両方のkill switchをactiveにする。
2. signing processと新規sponsorshipを停止する。
3. attempt/evidenceを削除せずunknown/submittedを照合する。
4. compromise時はfunding停止、credential失効、wallet rotate、incident-window監査を行う。
5. security review完了までexecution gateを開かない。

## 9. Diagnostics and logs

許可: Payment/attempt ID、network、sender、SenderTxHash、tx hash、Fee Payer address、fixed diagnostic、state transition。
禁止: API/private key、Authorization、raw RLP、stack trace、RPC credential、seed phrase。

## 10. Production artifact and bypass

`corepack pnpm build`は`dist/src`だけを生成し、testsと`setup-kairos`を除外する。Kairos development bypassは
Mainnet profileでは必ず例外となり、`NODE_ENV`だけでは有効化できない。production artifactへ`.env`、tests、
Kairos key generation toolを含めない。

## 11. Rollback

- attempt/evidenceを削除しない。
- resolver/reconciliationを継続する。
- Kairos/Mainnet wallet、RPC、DBを相互流用しない。
- managed providerへfallbackしない。Managed Service adapterは将来の明示選択肢として残す。

## 12. Conditions before future enablement

- Stage 2 DB audit/migration
- dedicated primary RPCと監視用secondary
- KMS/HSM external signer実装・security review
- wallet separation、shared cache lock、budget/rate/balance alert検証
- one-merchant/user allowlist、kill-switch drill、staging rehearsal
- 別途Mainnet承認
- code上のMainnet execution/signing/broadcast policy変更を含む独立review

一部のflagだけを変更しても実行可能にしてはならない。

## 13. Official references

- [Kaia fee delegation](https://docs.kaia.io/build/transactions/fee-delegation/)
- [Wallet and fee-payer responsibility](https://docs.kaia.io/build/wallets/dapp-integration/how-to-integrate-fee-delegation-features-into-wallets/)
- [Public RPC limitations](https://docs.kaia.io/references/public-en/)
