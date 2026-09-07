# LivT Mainnet Fee Delegationアーキテクチャ決定

最終更新: 2026-09-07
状態: Phase 7 live Kairos managed test準備済み。live送信・外部利用申請・Mainnet接続は未実施

## 1. 決定

Mainnetの初期one-store pilotには、**A: Kaia公式Fee Delegation Service**を採用する。

- Kaia managed serviceはfee-payer署名とbroadcastだけを担当する。
- LaravelはPaymentの正本、sender-signed transactionのpolicy gateway、最終chain evidence検証を担当し続ける。
- Walletはユーザー鍵を端末内に保持し、現在と同じsender-signed RLPを生成する。
- `livt-fee-payer`は削除せず、Kairosの開発・回帰試験用として残す。Mainnet fallbackにはしない。
- Mainnet execution flagは、Phase 5では変更しない。

Hybrid/fallbackは採用しない。結果不明時に別providerへfallbackすると、同じnonceの異なる
fee-payer署名済みtransactionを二重送信する危険があり、鍵・台帳・監視も二重化するためである。

この決定は「すぐ接続可能」という意味ではない。Kaiaへの利用申請時に第14節の不明点を解消し、
永続attempt ledgerとunknown submission回復を実装・検証してから、別承認で接続する。

## 2. 公式サービスの現行契約

2026-09-07にKaia公式資料を再確認した。

| 項目 | 公式仕様 |
|---|---|
| Mainnet endpoint | `https://fee-delegation.kaia.io` |
| Kairos endpoint | `https://fee-delegation-kairos.kaia.io` |
| API | `POST /api/signAsFeePayer` |
| request | `{"userSignedTx":{"raw":"<sender-signed RLP>"}}` |
| authentication | `Authorization: Bearer <API_KEY>`。API key設定DAppでは必須 |
| success | HTTP応答内の`status: true`と`data`のTransactionReceipt |
| balance | `GET /api/balance`。API keyまたはaddress queryを使用し、0.1 KAIA超の十分性をbooleanで返す |
| Mainnet access | API keyとcontract/sender whitelist。API keyだけの構成も可能 |
| Testnet access | 文書上はtransaction validationなし |
| application | 公式integration guideからGoogle Formを提出し、Kaia側がDAppを設定して通知 |
| Swagger | Mainnet/Kairosそれぞれ`/api/docs` |

公式資料:

- [Integrate Kaia Fee Delegation Service](https://docs.kaia.io/build/tutorials/integrate-fee-delegation-service/)
- [Implementing Transactions: Fee Delegation and SenderTxHash](https://docs.kaia.io/build/transactions/)
- [`kaia_getTransactionBySenderTxHash`](https://docs.kaia.io/references/json-rpc/kaia/get-transaction-by-sender-tx-hash/)
- [`kaia_getTransactionReceiptBySenderTxHash`](https://docs.kaia.io/references/json-rpc/kaia/get-transaction-receipt-by-sender-tx-hash/)
- [FeeDelegatedSmartContractExecution SDK example](https://docs.kaia.io/references/sdk/web3js-ext/fee-delegated-transaction/smart-contract-execution/)

API key使用時はbackendから呼ぶことが公式にも推奨されている。LivTでは必須要件とし、Wallet、
`VITE_*`、browser storage、response、log、artifactへAPI keyを出さない。

## 3. 現在のLivTフロー

```text
LivT Wallet
  -> Laravel Payment API
  -> self-hosted livt-fee-payer
  -> Kaia Kairos
  -> Laravel confirmation verifier
  -> MySQL Payment confirmed
```

1. WalletはPayment snapshotからchain、JPYC contract、recipient、atomic amountを検査する。
2. Walletがsender accountのpending nonceとgas priceをRPCから取得する。nonceの所有者はsender/Walletであり、Fee Payerではない。
3. `transfer(recipient, atomicAmount)` calldataを作り、estimate gasにfee-delegation intrinsic gas 10,000を加える。
4. Walletは`TxType.FeeDelegatedSmartContractExecution`（type `0x31`）をユーザー端末内でsender署名する。
5. 生成物はfee-payer address/signatureを含まない、sender署名済みKaia RLPである。Wallet自身はこの経路ではbroadcastしない。
6. WalletはLaravelへ`{"sender_signed_tx":"0x31..."}`を認証付きで送る。
7. LaravelはPayment status/snapshot/expiration/network gateを検査し、RLPをcanonical decodeする。
8. Laravel inspectorはtype、chain ID、sender signature、gas上限、value 0、JPYC contract、`transfer` selector、recipient、atomic amountを検査する。
9. Laravelはlowercase rawのSHA-256 fingerprintでPayment単位のreservationを作る。現在はcacheであり、本番永続台帳ではない。
10. 現在のgatewayはself-hosted serviceへ`{"userSignedTx":{"raw":"0x31..."}}`とBearer keyを送る。
11. `livt-fee-payer`も同じtransaction policyを再検査し、fee-payer署名後にsender fieldsが不変であることを検査する。
12. `livt-fee-payer`がfull transaction hashを事前計算し、`kaia_sendRawTransaction`を1回だけ実行してreceiptをpollする。
13. Laravelは返却receiptの`hash`または`transactionHash`を厳格に読み、Walletへtx hashを返す。
14. Walletはtx hashをLaravelの共通confirmation APIへ渡す。
15. Laravelの`PaymentTransactionVerifier` / `OnChainPaymentEvidenceVerifier`がtransaction、receipt、canonical block、JPYC `Transfer` log、snapshot、duplicateをchain RPCから再検証した場合だけPaymentをconfirmedにする。

現在の自動retry方針は安全側である。確定的なprovider rejectionだけreservationを解放し、timeout、
5xx、矛盾した/malformed responseは「broadcast済みかもしれない」としてunknownに保持する。
Fee Payer gatewayはsubmissionを自動retryしない。

## 4. Managed service後のtrust boundary

```text
LivT Wallet
  -> sender-signed RLP
Laravel policy gateway
  -> validated sender-signed RLP
Kaia Fee Delegation Service
  -> fee-payer sign + broadcast
Kaia Mainnet
  -> transaction / receipt / block / Transfer event
Laravel confirmation verifier
  -> MySQL Payment confirmed
```

Kaia managed serviceをPayment validationの正本にしない。managed serviceがcontract whitelistを
検査しても、Laravelでは少なくとも次を保持する。

- Payment ID、認証、status、Payment snapshot、expiration policy
- network、chain ID、transaction type `0x31`
- canonical RLP integrityとsender signature recovery
- JPYC contract、native value 0、`transfer(address,uint256)` selector
- recipient、atomic amount、signed sender、gas上限
- Payment単位とsender transaction単位のduplicate/replay protection
- managed responseの形・success receipt・returned tx hash検査
- chain dataによる最終confirmation evidence検証

現行`livt-fee-payer`の署名前policyは、すでにLaravel inspectorにもほぼ同等に存在する。
managed移行時は二重検査の片側を削らず、Laravel側を唯一の外部送信前policy boundaryとして
contract testで固定する。managed serviceはfee-payer signer + broadcasterに限定する。

## 5. Payload / transaction互換性

LivT gatewayと公式APIのrequestは同形である。

```json
{
  "userSignedTx": {
    "raw": "0x31..."
  }
}
```

hex/base64変換、RLP再encode、Wallet transaction format変更は不要と判断する。公式Kaia SDK資料は
`FeeDelegatedSmartContractExecution`のsenderTxHashRLPが`0x31`から始まることを示し、LivTの生成物と
一致する。現在のLaravel gatewayもBearer header、上記body、`data.hash`と
`data.transactionHash`の両方を扱える。

ただし、公式service integration guideの実例は`FeeDelegatedValueTransfer`であり、serviceがMainnetで
`FeeDelegatedSmartContractExecution`を受けることをtransaction-type一覧として明記していない。
Kaia chain/SDKとしてのtype `0x31`対応と、managed serviceのDApp policyとしての受理は別問題である。
利用申請時にJPYC `transfer()`のサンプルrawを送信せず構造として提示し、明示確認を得るまでは
compatibilityを「条件付き」とする。

## 6. Whitelist戦略

**API key + Mainnet JPYC contract whitelist**を採用候補とする。

- 多数の利用者addressを事前登録するsender whitelistはLivTに適さない。
- API key onlyはmanaged service側の権限が広すぎる。
- contract whitelist onlyは第三者がLivTの残高を消費できると公式資料が警告している。
- API key + fixed JPYC contractは、backend認証と宛先contract制約を組み合わせられる。

ただしJPYC contract whitelistは「JPYCの正しい支払い」まで保証しない。Laravelを迂回できた場合、
`approve`、`transferFrom`、任意recipient/amountの`transfer`など意図しないcontract callもgas補助対象に
なり得る。したがって、Laravelのcalldata/snapshot policy、rate limit、budget、kill switchは必須である。
Kaia側がfunction selectorやamountまでcustom制限できるなら追加防御として依頼するが、LivT policyの
代替にはしない。

## 7. API keyと設定

将来実装では概念上、次を使う。

```text
FEE_DELEGATION_MODE=kaia-managed
KAIA_FEE_DELEGATION_URL=https://fee-delegation.kaia.io
KAIA_FEE_DELEGATION_API_KEY=
```

- secretはMainnet backendのsecret manager/権限制限済みEnvironmentFileだけへ注入する。
- browser用`VITE_*`、Wallet repository、database、source controlへ置かない。
- AuthorizationはLaravel gatewayが`Bearer`として付与する。
- request/response/error logからAuthorizationとraw transactionをredactする。
- URLは固定allowlistまたは承認済みHTTPS originとして検証し、redirectを許可しない。
- key rotationは新旧keyの切替、readiness確認、旧key失効をrunbook化する。
- missing key、invalid endpoint、wrong network、disabled flagは外部call前にfail closedにする。

既存設定名とgatewayはAPI形状上再利用可能だが、誤ってKairos/self-hostedとMainnet/managedを
取り違えないよう、実装時には明示的modeとnetwork別設定を導入する。Phase 5では`.env`も
`.env.example`も変更しない。

## 8. Unknown submission / idempotency

### 8.1 Provider契約の評価

公式資料は500時にservice内部で「5 try」後のfailureを返す例と、`KNOWN transaction`時に現在の
transactionを再確認する案内を載せている。しかし、次は公開契約から確定できない。

- POSTのidempotency key/replay guarantee
- response消失後にprovider側requestを検索するAPI
- 5回の内訳と、各errorでbroadcastが行われた可能性
- returned hashが常に`hash`か`transactionHash`か
- timeout/connection reset後の安全な再送条件

したがって、HTTP 500を含むtransport/provider不明応答を確定的失敗とは扱わない。公式の一般的な
retry推奨をblind POST retryとして実装してはならない。

### 8.2 永続状態

Mainnet接続前にLaravel DBへattempt ledgerを追加する。

```text
reserved -> validated -> submitting -> receipt_observed -> confirmed
    |          |             |                 |
 rejected   rejected   unknown_submission    reverted
```

最低限保存するもの:

- `payment_id`, `network`, `chain_id`, provider、policy/profile version
- sender rawのSHA-256 fingerprint（raw自体は保存・logしない）
- `sender_tx_hash`, sender address, sender nonce
- providerから取得できたfull transaction hash
- state、秘密を含まないdiagnostic code、各時刻

`submitting`をHTTP callより前にcommitする。timeout、connection reset、5xx、429、malformed response、
success/hash contradictionは`unknown_submission`へ遷移し、同じrawも新しいrawも自動POSTしない。
同一Paymentの別fingerprintはconflictにする。DB unique constraintとrow/advisory lockで複数process間も
single-flightにする。

### 8.3 回復

sender-signed RLPから`SenderTxHash`を外部call前に計算し保存する。Kaiaではfee-payer情報を除いた
hashからfull transaction/receiptを検索できる。ただしRPC nodeの
`--sendertxhashindexing`が必要で、`kaia_isSenderTxHashIndexingEnabled`で確認する。

回復workerは次の順でread-only照会する。

1. full tx hashが分かればprimary/secondary RPCでtransaction/receiptを照会する。
2. 分からなければ`kaia_getTransactionBySenderTxHash`と
   `kaia_getTransactionReceiptBySenderTxHash`を両providerへ照会する。
3. 見つかったfull hash、sender、nonce、type、token/calldataとattemptを照合する。
4. 成功receiptなら通常の`OnChainPaymentEvidenceVerifier`へ渡す。
5. reverted receiptなら`reverted`、provider不一致や未発見ならunknownのまま継続照会する。

「一定時間見つからない」だけでは未送信の証明にならない。再送または新nonceを許すには、Kaia側の
明示的なidempotency/recovery契約、独立RPCの一致、operator承認を含むrunbookが必要である。
sender nonceはWalletが所有するため、unknown解決まで同senderの新規支払いを安全側で抑止/警告する。

## 9. Responseとfail-closed規則

- HTTP 200だけで成功にしない。top-level `status === true`を要求する。
- `data`が成功TransactionReceiptで、receipt statusが成功であることを要求する。
- `data.hash` / `data.transactionHash`は厳格な32-byte hashとし、両方あれば一致を要求する。
- response chainを直接証明できないため、返却hashをactive snapshot chain RPCへ照会して確定する。
- malformed body、unexpected hash、wrong chain、balance/readiness不明は成功にしない。
- explicit whitelist/API key/policy rejectionだけを`rejected`候補にし、broadcast可能性のある応答はunknownにする。
- sponsorship成功だけではPayment statusを変更しない。

## 10. A/Bセキュリティ・運用比較

| 観点 | A: Kaia managed（採用） | B: self-hosted Mainnet |
|---|---|---|
| fee-payer鍵 | Kaia側がcustody。LivTはAPI keyのみ | LivTがMainnet鍵をcustody。KMS/HSM adapter必須 |
| attack surface | Laravel/API key/provider依存 | Laravelに加えsigner、broadcast service、host、KMS、DB |
| transaction policy | Kaia whitelist + Laravel詳細policy | Laravel + sidecar policy。両方を保守 |
| least privilege | API key + JPYC contract whitelist | signer IAM、gas policyを自前設計 |
| amount/abuse | Laravel上限・rate limit・budgetが必要 | 同左に加えsidecarごとの制御が必要 |
| replay/idempotency | LivT永続ledgerが必要。provider契約は要確認 | LivT/sidecar永続ledgerを自前実装可能だが未実装 |
| nonce | sender/Wallet所有。provider挙動要確認 | sender/Wallet所有。sidecarで調査・復旧を実装 |
| unknown state | SenderTxHash対応RPCとprovider確認が必要 | full hashを署名前後で保持でき、設計自由度は高い |
| rate/balance | service quota・DApp balance・alertに依存 | per-user/store予算、KAIA残高alertを自前構築 |
| emergency stop | Laravel kill switch + Kaia DApp停止手段 | Laravel/sidecar/signer IAMの停止手順 |
| audit | LivT attempt log + Kaia提供範囲 | 全署名/broadcast auditを自前構築可能 |
| availability | Kaia serviceに依存 | 自前RPC、DB、KMS、hostのHAに依存 |
| vendor dependency | 高い。API変更・quota・審査に依存 | SDK/RPC/KMSには依存するがservice vendor lock-inは低い |
| 実装・運用負荷 | 中。policy/ledger/recoveryはLivT側に残る | 非常に高い。24/7 signer運用とsecurity reviewが必要 |

managed serviceでもAPI key漏洩、contract whitelist乱用、vendor outageの責任は残る。しかし学生運用の
one-store pilotでMainnet private key custody、KMS signing integration、rotation、HAを同時に成立させる
より、境界を狭くできる。

## 11. Cost / maintenance

公式guideで確認できるのは、DApp balance、`/api/balance`の0.1 KAIA基準、残高不足時の拒否、
設定時のemail alert、top-up時のKaia team連絡である。価格、無料枠、rate/quota、SLA、Mainnet利用上限は
公開guideから確定できないため、申請時に確認する。

self-hostedでは直接のgas代に加えて、KMS/HSM、primary/secondary RPC、database、monitoring、backup、
on-call、鍵rotation、security reviewの継続費が発生する。pilot規模では人件・事故リスクがgas代差より
支配的である。

## 12. Migration plan

1. Kaia applicationを提出する前に、第14節の質問と想定volume/上限を準備する。
2. API key + Mainnet JPYC contract whitelist、backend-only accessを申請する。
3. type `0x31` JPYC `transfer()`対応、response schema、idempotency、quota、停止手段を文書で確認する。
4. persistent sponsorship attempt table/state machineとSenderTxHash計算をLaravelへ実装する。
5. managed modeを明示するgateway/configを追加する。Mainnet flagsはfalseのままcontract testする。
6. mock serverでsuccess/rejection/reverted/timeout/connection reset/5xx/malformed/KNOWNを試験する。
7. existing Kairos self-hosted unit/browser/live regressionを維持する。
8. Kairos managed endpointで、許可されたtest credentialを使う別手動試験を行う。
9. primary/secondary Mainnet RPCのsenderTxHash indexing/readinessをread-only確認する。
10. rate limit、low pilot cap、balance monitor、kill switch、alert、operator recovery runbookを整備する。
11. security/operations review後、Mainnet有効化をPhase 5とは別の明示承認にする。

Mainnetの最初の実取引は、この文書の作成やadapter追加に連動して自動実行しない。

## 13. Rollback

- managed service障害時は新規sponsorshipだけを停止し、direct transferへ自動fallbackしない。
- `submitting` / `unknown_submission`のattemptは削除せず、read-only reconciliationを継続する。
- Kairos self-hosted経路は削除せず、Mainnet managed modeと設定/credential/DBを分離する。
- adapter release rollback後もattempt/evidence tableをdropしない。
- providerを切り替える場合もunknown attempt解決前に同じsender rawを再送しない。
- API key漏洩時はLaravel kill switch、Kaia側key失効/DApp停止、rotation、attempt監査を実施する。

## 14. Remaining unknowns / implementation blockers

Kaia teamへ次を確認するまでproduction adapterを有効化しない。

1. Mainnet serviceが`FeeDelegatedSmartContractExecution` type `0x31`とJPYC `transfer()`を受理するか。
2. 許可するtransaction type、gas limit、payload size、timeoutの上限。
3. success receiptの正式schemaとhash field（`hash` / `transactionHash`）。
4. POSTのidempotency、同一raw再送、`KNOWN transaction`、nonce conflictの厳密な意味。
5. service内部retry中にbroadcast済みとなり得るerrorと、response消失時の照会方法。
6. request ID/status API、SenderTxHashまたはfull tx hashを返す/検索する手段の有無。
7. API key + contract whitelistの正確なAND条件と、selector/amount custom policyの可否。
8. pricing、DApp balance funding/refund、quota/rate limit、SLA/maintenance notice、email alert。
9. credential rotation、key失効、DApp emergency disableの手順と所要時間。
10. managed fee-payer addressの公開/rotationとaudit recordの提供範囲。
11. 利用するMainnet RPCがsender tx hash indexingを有効化しているか。
12. LivT側のpersistent attempt ledger、rate limit、budget、monitor/alert/runbookの実装完了。

## 15. Required Kaia application/access steps

1. [公式integration guide](https://docs.kaia.io/build/tutorials/integrate-fee-delegation-service/)の現行Google Formを開く。
2. LivTのbackend-only構成、one-store pilot、想定件数、small payment cap、Mainnet JPYC contractを説明する。
3. API key + JPYC contract whitelistを希望し、第14節の回答を依頼する。
4. Kaia teamによるDApp登録・activation通知を待つ。Phase 5では提出しない。
5. credential受領後はsecret managerへ登録し、共有chat、issue、commit、frontendへ貼らない。
6. `/api/balance`、DApp状態、quota、alert、emergency contactをreadiness/runbookへ組み込む。

## 16. Test plan

- valid sender-signed `0x31`だけを公式bodyで1回forwardする
- invalid RLP、wrong chain/type/token/recipient/amount、expired Paymentを外部call前に拒否する
- signed sender、native value、gas、calldata、snapshot、duplicate policyを固定する
- Authorization headerを正しく付け、frontend bundle/logにAPI keyがないことを検査する
- malformed response、rejection、reverted、unexpected/mismatched hashをfail closedにする
- timeout/reset/5xx/429を`unknown_submission`にし、POSTを自動retryしない
- already-knownをSenderTxHash/full hashのread-only lookupへ送る
- final confirmationが必ずchain evidence verifierを通ることを確認する
- balance/readiness unavailable、wrong active network、disabled flagで外部callしない
- existing Kairos self-hosted pathを全suiteとlive reviewで回帰確認する

## 17. Phase 6実装結果

Laravelへ次を実装した。Mainnet execution gateは変更していない。

- `FEE_DELEGATION_MODE=self-hosted|kaia-managed`による明示provider選択
- self-hostedとmanagedの専用gateway、および自動fallbackしないselector
- managed modeでHTTPSとbackend-only Bearer API keyを必須化
- redirect禁止、最大120秒timeout、1 MiB response上限、厳格なstatus/receipt/hash検査
- additiveな`payment_fee_delegation_attempts` ledger
- Payment row lock、Paymentごとのunique制約、SenderTxHash/fingerprintのchain内unique制約
- submission call前に`reserved -> validated -> submitting`をcommitする状態遷移
- timeout、connection reset、5xx、429、malformed/矛盾responseの`unknown_submission`固定
- 同一request再受付時のknown hash再利用、unknown/rejected時のprovider非再呼び出し
- `Keccak-256(SenderTxHashRLP)`によるSenderTxHashのsubmission前保存
- `payments:resolve-fee-delegation-attempts` read-only resolver

resolverは`kaia_isSenderTxHashIndexingEnabled`を確認し、
`kaia_getTransactionBySenderTxHash` / `kaia_getTransactionReceiptBySenderTxHash`だけで回復する。
成功receiptが見つかった場合もmanaged responseだけでは確定せず、既存
`PaymentTransactionVerifier` / `OnChainPaymentEvidenceVerifier`を通してからPaymentとattemptを
confirmedにする。未発見、indexing無効、一時RPC障害では再送せずunknownを維持する。

公開資料に残る不明点（managed service自身のtype `0x31`受理保証、provider側idempotency/status API、
内部retryのbroadcast境界、quota/料金/SLA）はPhase 6でも推測していない。これらはlive Kairos managed
試験前の外部確認事項である。

## 18. Phase 7 controlled Kairos test preparation

Phase 7ではlive callを行わず、次のfail-closedな準備だけを追加した。

- `KAIROS_MANAGED_LIVE_TEST_ENABLED=false`をdefaultとする明示kill switch
- `KAIROS_MANAGED_LIVE_TEST_PAYMENT_ID`による1件のPayment固定
- `KAIROS_MANAGED_LIVE_TEST_MAX_JPYC=1`およびcode上限1 JPYC
- Kairos、chain ID 1001、公式Kairos managed endpoint、Mainnet三重gate falseの強制
- `blockchain:kairos-managed-readiness`によるchain/indexing/latest blockのread-only確認
- `payments:prepare-managed-kairos-test`によるRLP policy検証と安全metadata表示
- attemptの安全なstate transition logとresolver result log

公式managed service資料はfee-delegated transaction一般とvalue transfer例を示すが、type `0x31` JPYC
`transfer()`の対応を明記していない。将来の1回のKairos試験を互換性検証とし、再送やprovider fallbackを
行わない。operator手順は[`kairos-managed-live-test-runbook.md`](kairos-managed-live-test-runbook.md)を正本とする。
