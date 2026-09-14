# Kaia Mainnet external signer runbook（Phase 9.5）

状態: AWS KMS adapterとoffline検証を実装済み。Mainnet execution/signing gate/broadcastは無効、kill switchはactive。

## 1. Decision

`livt-fee-payer`のMainnet signerにはAWS KMS asymmetric keyを採用する。

| 候補 | secp256k1 / digest / 公開鍵 | 運用評価 | 結論 |
|---|---|---|---|
| AWS KMS | `ECC_SECG_P256K1`、`Sign(MessageType=DIGEST)`、`GetPublicKey`、DER ECDSA | UbuntuからIAM roleで利用でき、専用HSM運用が不要 | 採用 |
| Google Cloud KMS | `EC_SIGN_SECP256K1_SHA256`、digest、公開鍵、DER ECDSA | secp256k1はHSM protection level限定でpilotには複雑・高コスト | 非採用 |
| Vault Transit | 標準Transitの公開対応curveにsecp256k1がない | Vault cluster運用とunseal/HA/IAM相当を追加する | 非採用 |
| 専用HSM / signing service | 実装可能 | 小規模1店舗pilotには調達・HA・監査負荷が過大 | 将来候補 |

AWS KMS private keyはKMS外へexportできず、Node.jsには公開鍵とDER署名だけが返る。AWSのalgorithm名は
`ECDSA_SHA_256`だが、`MessageType=DIGEST`ではKMSによる再hashを行わず、渡した32 byteのKaia
Keccak-256 digest自体へECDSA署名する。実KMSでの互換性は明示的なoffline commandを本番有効化前に必ず実行する。

公式資料:

- [AWS KMS asymmetric key specifications](https://docs.aws.amazon.com/kms/latest/developerguide/symm-asymm-choose-key-spec.html)
- [AWS KMS Sign API](https://docs.aws.amazon.com/kms/latest/APIReference/API_Sign.html)
- [AWS KMS GetPublicKey API](https://docs.aws.amazon.com/kms/latest/APIReference/API_GetPublicKey.html)
- [AWS KMS pricing](https://aws.amazon.com/kms/pricing/)
- [Google Cloud KMS algorithms](https://cloud.google.com/kms/docs/algorithms)
- [Vault Transit key types](https://developer.hashicorp.com/vault/docs/secrets/transit)

## 2. Exact Kaia signing semantics

対象はtype `0x31` FeeDelegatedSmartContractExecutionのsender署名済みRLPである。Kaia SDK
`KlaytnTxFactory.fromRLP(senderRaw)`へFee Payer addressとchain IDを設定し、`sigFeePayerRLP()`を得る。
署名digestは `keccak256(sigFeePayerRLP)`、curveはsecp256k1、署名値はECDSA `(r,s)` である。

KMSのASN.1 DERを厳格解析し、scalar rangeを検査する。high-sならcurve orderから減算してlow-sへ正規化する。
`yParity`の0/1をdigestから双方recoverし、設定済みFee Payer addressと一致する唯一の値を採用する。
Kaiaの`v`は `yParity + chainId * 2 + 35`。その署名をFee Payer signaturesへ追加した
`txHashRLP()`だけを後段へ渡す。recover不能、address不一致、DER不正はbroadcast前に拒否する。

Golden fixtureはKairos専用test keyと固定sender RLPを使い、local signerとexternal fakeが同じdigest、r/s/v、
最終RLPを生成することを固定している。実Mainnet鍵は使わない。

## 3. Key creation and identity

Operator権限で、対象regionに次を作成する。

- asymmetric KMS key
- KeySpec: `ECC_SECG_P256K1`
- KeyUsage: `SIGN_VERIFY`
- 状態: `Enabled`
- 専用alias（例: `alias/livt-mainnet-fee-payer`）

`GetPublicKey`のDER SPKIからuncompressed secp256k1 public keyを取得し、Ethereum/Kaia addressを導出する。
これを別経路で確認して`FEE_PAYER_KAIA_MAINNET_ADDRESS`へ設定する。readinessと各sign直前にKMS由来addressとの
一致を検査し、不一致なら`key_mismatch`でfail closedする。手入力addressだけは信用しない。

## 4. Runtime configuration and authentication

```text
BLOCKCHAIN_NETWORK=kaia-mainnet
FEE_PAYER_MAINNET_SIGNER_TYPE=external
FEE_PAYER_MAINNET_SIGNER_BACKEND=aws-kms
FEE_PAYER_AWS_REGION=<region>
FEE_PAYER_AWS_KMS_KEY_ID=<key ARN, key ID, or alias>
FEE_PAYER_SIGNER_TIMEOUT_MS=5000
FEE_PAYER_KAIA_MAINNET_ADDRESS=<derived public address>
```

EC2 instance profileなどAWS SDK default credential chainの短期credentialを使う。static AWS credentialを
application `.env`、Laravel、frontend、DBへ保存しない。KMS key IDはsecretではないが、health/logでは
SHA-256短縮fingerprintだけを表示する。

Runtime IAM policyは対象key ARNに限り `kms:DescribeKey`、`kms:GetPublicKey`、`kms:Sign`だけを許可する。
key create/delete/disable/schedule deletion、alias管理、policy管理、他key/resourceは許可しない。作成・rotation・disableは
別operator roleへ分離する。KMS key policy側でもruntime roleと対象keyを限定する。

## 5. Readiness and offline signature proof

Metadata healthは認証、key存在/state、algorithm、public key、address一致を確認するが署名しない。

```bash
cd /path/to/livt-fee-payer
corepack pnpm readiness:mainnet
```

期待状態は `READ_ONLY_READY`、`SIGNER_READY`、`broadcast=disabled`。次のcommandだけが固定・非live digestへ
実KMS署名を1回要求する。Payment/RPC/`kaia_sendRawTransaction`には触れない。

```bash
corepack pnpm signer:test-mainnet
```

出力はsigner type、key fingerprint、公開address、test digest、gate状態だけで、署名DERやraw RLPを出さない。
この操作はKMS署名課金・監査eventを発生させるためoperatorが明示実行する。

## 6. Error and attempt behavior

Signer contractは `ready`、`unavailable`、`authentication_failure`、`key_mismatch`、`invalid_key` をhealthで区別し、
signでは `SIGNER_UNAVAILABLE`、`SIGNER_TIMEOUT`、`AUTHENTICATION_FAILURE`、`SIGNING_FAILURE`、
`INVALID_SIGNATURE`、`KEY_MISMATCH`を固定codeで返す。provider message/token/raw transactionは返さない。

Fee Payerから明示されたsigner codeは、Laravel attemptを既存`failed`へ遷移し、`resolved_at`と安全なdiagnosticを保存する。
これはbroadcast前の確定失敗であり`unknown_submission`ではない。ただし`failed`はterminalとし、自動retryしない。
同一transaction identityを再試行する将来policyは、明示reviewと新attempt設計なしに追加しない。
KMS内部で署名が成立したか不明でも、署名rawを受領していない限りchain broadcastは起きない。それでもadapterは
KMS signを自動retryせず、operatorがattemptと監査eventを確認する。

`validated`から既存`submitting`へ移った後のsidecar応答で上記を分類できるため、`signing`/`signed`状態や追加migrationは
導入しない。provider request IDも現段階ではDB保存せず、KMS監査logと時刻・key fingerprintで照合する。

LaravelからFee PayerへのHTTP timeout/resetは、Fee Payerが既にbroadcastへ進んだ可能性を排除できないため従来どおり
`unknown_submission`。broadcast timeout/hash/receipt ambiguityも`unknown_submission`で、自動retryしない。

## 7. Failure and HA model

provider/IAM/network unavailable、高latency、throttle、disabled key、pending deletion、algorithm/address不一致はすべて
fail closed。local private keyやKaia Managed Fee Delegationへのfallbackはない。初回pilotでmulti-region KMSや自動failoverは
構築しない。availabilityより二重送信防止とidentity固定を優先する。監視はhealth status、latency、throttle、auth failure、
key state change、attempt diagnosticを対象にし、raw payloadやcredentialは記録しない。

## 8. Rotation

1. kill switchをactiveのまま新KMS keyをoperator roleで作る。
2. 新公開鍵から新Fee Payer addressを導出・別経路確認する。
3. outstanding/unknown/submitted attemptを照合し、in-flightをゼロにする。
4. 新addressへ切り替えたstaging configでmetadata readinessとoffline testを行う。
5. 将来の承認済みPhaseで、新addressへ極小KAIAを入れる。
6. config/address/key aliasを同一releaseとしてrolloutし、旧signerを停止する。
7. 監査履歴には旧/新addressと切替時刻を保持する。
8. rollbackはin-flightがないことを確認して旧identity/configへ明示的に戻す。無言のalias切替は禁止。

## 9. Compromise response

1. 両serviceのkill switchをactiveにし、新規sponsorshipを止める。
2. runtime roleの`kms:Sign`を失効し、必要ならkeyをdisableする。
3. attempt/evidence/KMS監査logを削除せず保全する。
4. outstanding/unknown/submitted attemptをread-only照合する。
5. 適切なら残KAIAをincident procedureに従い退避する。
6. replacement key/addressを作り、rotation手順を実施する。
7. 全readiness、offline proof、allowlist、alert、kill-switch drillを再実行する。
8. security/change approval後だけ再開する。

## 10. Pilot and enablement blockers

1 merchant、1 approved sender、最大1 JPYC、少件数、極小KAIA残高、手動監視、kill switch、fallbackなしを維持する。
Phase 9.5では全execution/signing/broadcast flagをfalse、kill switchをtrueのままにする。実AWS role/key policyのreview、
real KMS offline proof、staging monitoring/lock/DB rehearsal、key funding approval、security review、独立したexecution enablement
changeが終わるまでMainnet transaction readyとはしない。
費用はKMS keyの月額とmetadata/sign API request数で見積もり、導入時に公式pricingを再確認する。固定金額はrunbookへ
焼き付けない。Google CloudのHSM必須構成や専用HSM運用より、小規模pilotの固定運用負担を抑える。
