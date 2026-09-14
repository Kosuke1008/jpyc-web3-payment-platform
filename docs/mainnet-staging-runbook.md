# Kaia Mainnet staging runbook（Phase 10）

状態: read-only staging準備用。Mainnet transaction署名・broadcast・Fee Payer入金は禁止。
operatorが明示する固定test digestのKMS offline署名だけを許可する。

## 1. Architecture

```text
Operator
  -> Laravel blockchain:mainnet-staging-readiness
       -> dedicated Mainnet DB（read-only probe / migration visibility）
       -> shared Redis/database cache lock probe
       -> primary + secondary Mainnet RPC（read-only）
       -> 127.0.0.1 Fee Payer /health（read-only）

Wallet -> Laravel -> Fee Payer signing endpoint は全Mainnet gateにより到達不能
```

Fee Payerは同一hostのliteral `127.0.0.1`でread-only healthだけを提供する。public reverse proxyへ
載せない。将来のsigning endpointもbackend bearer、host firewall、egress制限を必須とし、health以外を
Internetへ公開しない。

## 2. Environment separation

KairosとMainnet stagingでDB/schemaとuser、primary/secondary RPC、Fee Payer address、merchant、approved
sender、secret store、cache connection/prefix、log contextを分離する。readinessはDB identifier、cache
prefix、RPC URL、merchant、Fee Payer identityの再利用を拒否する。cross-environment overrideはpilotで
使用しない。

設定する変数名（値は環境固有placeholder）:

```text
MAINNET_STAGING_ENVIRONMENT_ID=mainnet-staging
MAINNET_STAGING_DATABASE_IDENTIFIER=<dedicated DB/schema name>
KAIROS_DATABASE_IDENTIFIER=<different Kairos DB/schema name>
MAINNET_STAGING_CACHE_PREFIX=<dedicated prefix>
KAIROS_CACHE_PREFIX=<different Kairos prefix>
MAINNET_STAGING_MERCHANT_ADDRESS=<public recipient address>
KAIROS_MERCHANT_ADDRESS=<different Kairos recipient>
FEE_PAYER_KAIA_MAINNET_ADDRESS=<public Fee Payer address>
FEE_PAYER_KAIROS_ADDRESS=<different Kairos Fee Payer>
MAINNET_STAGING_APPROVED_USER_IDS=<comma-separated IDs>
MAINNET_STAGING_APPROVED_SENDER_ADDRESSES=<comma-separated addresses>
MAINNET_STAGING_FEE_PAYER_HEALTH_URL=http://127.0.0.1:19000/health
MAINNET_STAGING_STAGE2_LEGACY_POLICY_RESOLVED=false
MAINNET_STAGING_ALLOW_CROSS_ENVIRONMENT_REUSE=false
```

`DB_DATABASE`はMainnet staging identifierと一致させ、`CACHE_STORE`は`redis`または共有database、
`CACHE_PREFIX`はMainnet staging prefixと一致させる。credential値はrunbookやrepositoryへ書かない。

## 3. Database setup

空のMainnet staging DBをKairosとは別名・別credentialで作成し、通常のmigration手順を実施する。
readiness自体はDDLを実行しない。Phase 2 snapshot、Phase 3 confirmation evidence、Phase 6 attempt ledger
migrationが見えることを確認する。

Stage 2 hardeningはlegacy policy未解決なら`blocked_pending_legacy_policy`と表示する。legacy rowを含む
DBではaudit合格まで実行しない。完全に空の新規DBなら、全migrationを作成順に適用する際にpreflightが
空集合へ合格するため安全に適用できる。Kairos historical DBを変更・流用してはならない。

## 4. RPC setup

commercial/SLA用途として契約した異なるprimary/secondaryを設定する。Kaia public Mainnet/Kairos endpoint
はproduction候補として拒否する。両方でchain ID 8217、latest block、JPYC bytecode、symbol、decimals、
block-height差をread-only検査する。secondaryは観測専用でbroadcast fallbackに使用しない。

## 5. Merchant wallet and Fee Payer identity

merchantは受取用public addressだけを設定し、秘密鍵をLivTへ渡さない。Mainnet merchant、Mainnet Fee
Payer、Kairos merchant、Kairos Fee Payerを分離し、merchant/Fee Payer同一addressも禁止する。

Mainnet Fee PayerはAWS KMS公開鍵から導出したaddressを設定する。Phase 9.5ではmetadata health成功時に
`SIGNER_READY`となるが、execution/broadcastは無効でFee PayerへKAIAを送らない。詳細は
`external-signer-runbook.md`を参照する。

## 6. External signer boundary

`FeePayerSigner`のAWS KMS adapter契約:

- input: `{ senderRaw }`（検査済みsender署名RLP）
- output: `{ signedRaw }`（Fee Payer署名追加済みRLP）
- public identity: `address`
- health: ready/unavailable/authentication_failure/key_mismatch/invalid_key
- errors: unavailable、timeout、auth、signing failure、invalid signature、key mismatch

公開鍵からaddressを導出して設定値と照合し、DER署名をlow-s化・recover検証する。Mainnet payment pathは
code-level gateで到達不能のまま。local key/Managed providerへのfallbackはない。

## 7. Cache and locking

全Laravel workerが共有するRedisまたはdatabase cacheを使う。`array`、`file`、`null`、process-local cache
は禁止。Redisでは全worker共通のlock connection、TTL/時刻、network timeout、専用prefixを確認する。

readinessは5秒TTLのlockを取得・解放する。pilot前に複数workerで同時実行し、1 workerだけがcritical
sectionへ入ること、Phase 8 policy lockの10秒TTL内でDB attempt commitが完了することを負荷検証する。

## 8. Allowlist and pilot limits

初回pilot例（すべてplaceholder、Phase 9.5では有効化しない）:

- merchant: 1 address
- approved user: 1 ID
- approved sender: 1 address
- Payment上限: 1 JPYC
- user/store/sender: 1 attempt/hour
- global: 1 attempt/hour
- daily transaction: 1
- daily KAIA budget: `max_gas * max_gas_price`を超える承認済み極小値
- minimum reserve: 実gas/停止基準から決定
- pilot Payment: `MAINNET_PILOT_PAYMENT_ID`の1件だけ
- Fee Payer残高上限: `MAINNET_PILOT_MAX_FEE_PAYER_BALANCE_KAIA`

allowlistはattempt予約・Fee Payer呼出し前に検査する。空allowlist、違うmerchant/user/senderはfail closed。

## 9. Monitoring and alerts

readiness JSONとattempt state logを外部監視へ送る。secret、Authorization、private key、raw RLPを記録
しない。Laravel/DB/cache/Fee Payer health、両RPC、chain/height、JPYC metadata、balance、signer、kill
switch、execution gates、rate/budget rejection、`unknown_submission`、reconciliation anomalyを監視する。

即時alert: chain mismatch、gate/kill switch変更、DB/cache unavailable、unknown submission、Fee Payer
unavailable。warning: reserve接近、daily budget 80%、rate limit反復、RPC height lag。

## 10. Readiness procedure

Fee Payer host:

```bash
cd /path/to/livt-fee-payer
corepack pnpm readiness:mainnet
corepack pnpm start:mainnet-staging-health
```

Laravel host:

```bash
cd /path/to/jpyc-web3-payment-platform
php artisan config:clear
php artisan blockchain:mainnet-staging-readiness
php artisan blockchain:mainnet-pilot-preflight
php artisan payments:prepare-mainnet-pilot <PAYMENT_ID>
```

期待値は`overall=READ_ONLY_READY`、Fee Payer `SIGNER_READY`、`broadcast=DISABLED`。通常readinessはRPC/DB/
cache/KMS metadata/HTTPのread-only操作だけを行い、Payment作成、署名、broadcast、migrationを実行しない。
実KMSの非transaction署名proofはoperatorが別途`corepack pnpm signer:test-mainnet`を明示実行する。

Phase 11ではFee Payer残高の照会成功をread-only readiness条件とするが、minimum reserve未満だけを理由に
失敗させない。healthは`funding_status=NOT_FUNDED`と現在残高・minimum reserveを返す。reserve以上なら
`FUNDED`。Phase 12の`blockchain:mainnet-pilot-preflight`と実行policyは引き続き不足残高をfail closedにする。

## 11. Required disabled flags

```text
PAYMENTS_MAINNET_ENABLED=false
MAINNET_FEE_DELEGATION_ENABLED=false
MAINNET_BROADCAST_ENABLED=false
SELF_HOSTED_MAINNET_FEE_PAYER_ENABLED=false
FEE_PAYER_MAINNET_SIGNING_ENABLED=false
FEE_PAYER_MAINNET_BROADCAST_ENABLED=false
FEE_PAYER_KILL_SWITCH=true
```

Managed providerは明示選択可能な将来候補として残し、自動fallbackは禁止。

## 12. Stop, rollback, and secrets

chain/token mismatch、RPC divergence、DB/cache分離不明、identity再利用、balance不明、gate変更、signerが
ready、unknown submission、secret露出疑いで停止する。RPC outageではbroadcastせずread-only比較する。
key compromise疑いではkill switchを維持し、credentialを無効化し、address履歴をread-only調査する。

rollbackはMainnet staging processを停止し設定を前版へ戻す。Kairos DBへの接続切替、migration rollback、
データ削除は禁止。secretは外部secret storeから専用service accountへ渡し、`.env.example`には値を置かない。

## 13. Phase 10 pilot procedure

exact Payment gate、funding計算、kill-switch/alert drill、将来のenable順序、one-click、evidence、shutdownは
`mainnet-1-jpyc-pilot-runbook.md`を唯一のlive pilot手順として参照する。

## 14. Blockers

External signer adapterは実装済み。実AWS key/role policy review、offline KMS proof、監査alert、rotation drillは未実施。

1 JPYC前: 上記完了、承認済み最小KAIA入金、全readiness、multi-worker lock、allowlist、alert、kill-switch
drill、JPYC Mainnet情報再確認、change approval、別Phaseでの全gate変更。
