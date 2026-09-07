# Kaia Mainnet read-only readiness runbook

Phase 4のreadinessはMainnet設定とread-only RPCだけを検査する。Payment作成、署名、fee delegation、transaction broadcastを有効化するものではない。

## 環境分離

KairosとMainnetでDB、DB user、RPC、Wallet build設定、merchant wallet、fee-payer設定、秘密情報を共有しない。Mainnetはprimary/secondary RPCを異なるproviderで用意する。実値やcredentialはrepositoryへ保存しない。

Mainnet read-only確認に必要な変数名:

```text
BLOCKCHAIN_NETWORK=kaia-mainnet
BLOCKCHAIN_KAIA_MAINNET_RPC_URL=
BLOCKCHAIN_KAIA_MAINNET_SECONDARY_RPC_URL=
PAYMENTS_MAINNET_ENABLED=false
MAINNET_FEE_DELEGATION_ENABLED=false
MAINNET_BROADCAST_ENABLED=false
MAINNET_READINESS_MAX_BLOCK_LAG=10
```

期待値:

- chain ID: `8217` (`0x2019`)
- JPYC: `0xe7c3d8c9a439fede00d2600032d5db0be71c3c29`
- symbol: `JPYC`
- decimals: `18`

## Payment監査

最初にread-only監査を実行する。

```bash
php artisan payments:audit-mainnet-migration
```

`C_ambiguous`または`D_invalid`が1件でも残る間はStage 2 migrationを実行しない。承認済みhistorical exportがある場合だけmanifestを使う。

```bash
# dry-run
php artisan payments:audit-mainnet-migration --manifest=/secure/path/payment-snapshots.json

# 別途レビュー後にだけ適用
php artisan payments:audit-mainnet-migration --manifest=/secure/path/payment-snapshots.json --apply
```

manifest例（実アドレスや実hashを文書・Gitへ追加しない）:

```json
{
  "123": {
    "source": "trusted_historical_export",
    "source_reference": "sha256:<64 lowercase hex>",
    "network": "kairos",
    "recipient_address": "0x<40 lowercase hex>"
  }
}
```

## RPC readiness

```bash
php artisan blockchain:mainnet-readiness
```

primaryとsecondaryについて、chain ID、JPYC bytecode、`decimals()`、`symbol()`、latest block number/hash/timestampを独立に確認する。両providerが成功し、latest height差が設定上限以内の場合だけ`readiness=ready`になる。secondary障害もreadiness failureとするが、chain corruptionとは断定せずprovider障害として調査する。

成功時にも必ず次が表示される。

```text
mainnet_execution=disabled
readiness=ready
```

失敗例はchain mismatch、空bytecode、token metadata mismatch、RPC method unavailable、malformed latest block、provider unavailable、provider height divergence、execution flag有効化である。診断出力へRPC URLやcredentialは含めない。

## 将来の1 JPYC試験までの順序

1. Mainnet primary/secondary RPCを設定する。
2. read-only readinessを成功させる。
3. isolated Mainnet DBを準備し、legacy監査とStage 2 migrationを完了する。
4. 別Phaseでfee delegation方式とcredentialを構成する。
5. budget、rate limit、監視、kill switchを実装・演習する。
6. security/operations承認後、別タスクでのみ1 JPYC controlled testを有効化する。
