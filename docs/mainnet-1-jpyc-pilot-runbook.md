# Kaia Mainnet 1 JPYC pilot runbook (Phase 13 activation release)

This runbook prepares one human-approved Mainnet pilot. It does not authorize a
live transaction. Keep a change approver, operator, and observer present for the
activation and shutdown phases.

## Mandatory safety boundary

Before approval, all of these values remain closed:

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

Each repository requires the exact `phase-13-mainnet-pilot-v1` activation
artifact. The artifacts are absent from a normal checkout and ignored by Git;
environment changes alone cannot make the pilot executable. Only the reviewed
release pipeline may install them from the tracked examples. Do not create them
ad hoc and do not replace the KMS signer with a process-local private key.

Never retry a timeout, connection reset, HTTP 5xx, malformed success, or other
ambiguous submission. Such an outcome is `unknown_submission`; use only the
read-only SenderTxHash resolver.

## Required configuration inventory

Keep actual values in the approved secret/config store, not this document,
source control, chat, shell history, or service logs.

Laravel and Fee Payer must agree on:

```text
BLOCKCHAIN_NETWORK=kaia-mainnet
MAINNET_STAGING_ENVIRONMENT_ID=mainnet-staging
MAINNET_STAGING_DATABASE_IDENTIFIER=<dedicated Mainnet staging DB>
KAIROS_DATABASE_IDENTIFIER=<different Kairos DB>
MAINNET_STAGING_CACHE_PREFIX=<dedicated prefix>
KAIROS_CACHE_PREFIX=<different prefix>
MAINNET_STAGING_MERCHANT_ADDRESS=<exact pilot merchant>
MAINNET_STAGING_APPROVED_USER_IDS=<exactly one positive ID>
MAINNET_STAGING_APPROVED_SENDER_ADDRESSES=<exactly one sender>
MAINNET_PILOT_PAYMENT_ID=<set only after Phase 3>
MAINNET_PILOT_MAX_FEE_PAYER_BALANCE_KAIA=<tiny approved cap>
PAYMENT_EXPIRATION_SECONDS=21600
KAIA_FEE_DELEGATION_MAX_GAS=<same approved gas cap>
FEE_PAYER_MAX_GAS=<same approved gas cap>
SELF_HOSTED_MAINNET_MAX_PAYMENT_JPYC=1
SELF_HOSTED_MAINNET_MAX_GAS=<Phase 5 observed and approved integer>
SELF_HOSTED_MAINNET_MAX_GAS_PRICE_WEI=<Phase 5 approved wei ceiling>
SELF_HOSTED_MAINNET_RATE_WINDOW_SECONDS=86400
SELF_HOSTED_MAINNET_MAX_ATTEMPTS_PER_USER=1
SELF_HOSTED_MAINNET_MAX_ATTEMPTS_PER_STORE=1
SELF_HOSTED_MAINNET_MAX_ATTEMPTS_PER_SENDER=1
SELF_HOSTED_MAINNET_MAX_ATTEMPTS_GLOBAL=1
SELF_HOSTED_MAINNET_DAILY_TRANSACTION_LIMIT=1
SELF_HOSTED_MAINNET_DAILY_KAIA_BUDGET=<max_gas * max_gas_price, in KAIA>
FEE_PAYER_MIN_RESERVE_KAIA=<approved reserve greater than zero>
```

Fee Payer additionally requires two different commercial Mainnet RPCs, its
loopback API secret, the KMS-derived public address, signer backend `aws-kms`,
AWS region/KMS key reference, and the Roles Anywhere/instance-role credential
profile. Laravel requires the loopback health URL and its own matching RPCs.
Never place an RPC URL containing credentials in command output.

The exact gas values are deliberately not guessed. Run Phase 5. The approved
gas cap must be at least `observed_required_gas`, and the gas-price ceiling must
be at least `observed_gas_price_wei`. Re-run immediately before approval; stop
if either observation exceeds its cap.

The conservative arithmetic is exact integer arithmetic:

```text
max_transaction_fee_wei = max_gas * max_gas_price_wei
minimum_required_balance_wei = max_transaction_fee_wei + minimum_reserve_wei
maximum_allowed_balance_wei = configured pilot maximum
recommended_top_up_wei = max(0, minimum_required_balance_wei - current_balance_wei)
```

Set the daily KAIA budget to exactly one maximum transaction fee. Set the
maximum balance no lower than fee plus reserve and no higher than the separately
approved tiny funding cap. The application rejects inconsistent values.

## Tomorrow's controlled sequence

### Phase 0 — rotate exposed pilot credentials

Earlier generated credentials must be considered exposed. Run only against the
dedicated Mainnet staging database, confirm the displayed Store/User IDs, and
save the three new values directly to the approved secret store. They are shown
once and are not logged by the command.

```bash
cd /path/to/jpyc-web3-payment-platform
php artisan mainnet:rotate-pilot-credentials --env=mainnet-staging
```

STOP if the environment, database, exact pilot identities, or confirmation do
not match. Do not rotate the addresses or IDs.

### Phase 1 — start the read-only Fee Payer health service

```bash
cd /path/to/livt-fee-payer
corepack pnpm build
corepack pnpm start:mainnet-staging-health
```

It must bind to loopback and expose health only. STOP if a sponsorship route is
reachable, if signing/broadcast is enabled, or if the kill switch is inactive.

### Phase 2 — prove staging READ_ONLY_READY

```bash
cd /path/to/livt-fee-payer
corepack pnpm readiness:mainnet
corepack pnpm signer:test-mainnet

cd /path/to/jpyc-web3-payment-platform
php artisan blockchain:mainnet-staging-readiness --env=mainnet-staging
php artisan mainnet:pilot-gate-status --env=mainnet-staging
```

Require `READ_ONLY_READY`, `SIGNER_READY`, `DISABLED`, `ACTIVE`, and
`SHUTDOWN_VERIFIED`. The signer test signs only a fixed offline digest; it must
not accept a Payment or call a broadcast RPC.

### Phase 3 — create exactly one Payment

Run once while every gate remains closed:

```bash
cd /path/to/jpyc-web3-payment-platform
php artisan mainnet:create-pilot-payment --env=mainnet-staging
```

Record the public output through the approved operational channel. Require:
network `kaia-mainnet`, chain `8217`, symbol `JPYC`, decimals `18`, display
amount `1`, atomic amount `1000000000000000000`, exact merchant, and a future
expiry. The command refuses any existing Payment or fee-delegation attempt.

If every existing Payment is an exact expired and completely unused pilot,
preserve the full audit history and create one replacement with:

```bash
php artisan mainnet:replace-expired-pilot-payment --env=mainnet-staging
```

The replacement command validates every historical Payment and locks the same
pilot resources. With multiple historical Payments,
`MAINNET_PILOT_PAYMENT_ID` must point to the latest expired Payment; an unset ID
is accepted only for the first replacement from a single historical Payment.
The command rejects any attempt, legacy transaction, tx hash, evidence, wrong
snapshot or identity, active Payment, or open gate. It leaves every old row
unchanged and prints `history_count` plus the new
`MAINNET_PILOT_PAYMENT_ID`; update both service configurations manually before
another replacement or preflight.

### Phase 4 — set the Payment ID manually

Copy only the command's final positive ID into `MAINNET_PILOT_PAYMENT_ID` in both
approved service configurations. Do not edit tracked files. Clear Laravel's
configuration cache and restart the read-only processes through the normal
deployment mechanism. Re-run Phase 2.

### Phase 5 — apply conservative limits

Start with every count limit and max payment exactly `1`, and the rate window
exactly `86400`. Obtain gas observations without signing:

```bash
php artisan mainnet:pilot-gas-observation --env=mainnet-staging
```

Approve explicit integer caps using both RPC observations. Make
`FEE_PAYER_MAX_GAS` and `SELF_HOSTED_MAINNET_MAX_GAS` equal. Set the daily
budget to exactly `max_gas * max_gas_price` converted to KAIA with at most 18
decimal places. Restart read-only services and re-run Phase 2. STOP rather than
inventing a gas value if estimation fails.

Create the Payment only when the remaining procedure can finish inside the
recommended six-hour Mainnet staging validity window. This is an operator value,
not a change to the application default. If it expires before any attempt,
transaction hash, payment evidence, or confirmation exists, use the guarded
replacement workflow above; never extend or overwrite the stored snapshot.

### Phase 6 — run the dry-run before funding

```bash
php artisan payments:prepare-mainnet-pilot <PAYMENT_ID> --env=mainnet-staging
```

With an unfunded Fee Payer, `NOT_READY` is expected and the only permissible
policy failure is the balance range. The command must perform no INSERT, UPDATE,
DELETE, signing, or broadcast. Resolve every other diagnostic before funding.

### Phase 7 — calculate funding

```bash
php artisan mainnet:pilot-funding-plan --env=mainnet-staging
```

Record the public fields: max gas, gas-price ceiling, max fee, reserve, minimum
required balance, maximum allowed balance, current balance, recommended top-up,
and Fee Payer address. STOP if either RPC disagrees, the address/chain differs,
or the maximum would be exceeded.

### Phase 8 — manually fund only the recommendation

The operator, not either application, manually transfers exactly
`recommended_top_up_kaia` to the independently verified Fee Payer address.
Require a second-person address check. Do not round up, automate, or send a test
transaction from these repositories.

### Phase 9 — verify funding through both RPCs

Re-run the funding plan. Require `recommended_top_up_kaia=0`, identical balance
at the same canonical block through both providers, balance at least minimum,
and balance at most maximum. Explorer display alone is not sufficient.

### Phase 10 — run complete preflight

```bash
php artisan blockchain:mainnet-pilot-preflight --env=mainnet-staging
php artisan payments:prepare-mainnet-pilot <PAYMENT_ID> --env=mainnet-staging
```

### Phase 11 — accept only the exact ready state

Require all of:

```text
overall=PILOT_PREFLIGHT_READY
infrastructure=READY
signer=READY
policy=READY
broadcast=DISABLED
kill_switch=ACTIVE
READY_FOR_HUMAN_APPROVAL
```

Also require one pending Payment, zero attempts, exact unexpired snapshot,
matching public policy from Fee Payer health, and gas observations within caps.

### Phase 12 — human approval checkpoint

The approver, operator, and observer independently compare Payment ID, Store,
User, sender, merchant, Fee Payer, token, chain, amount, expiry, funding, and
change record. Confirm an emergency operator can reactivate the kill switch
immediately. Any difference is a STOP.

### Phase 13 — deploy the separately reviewed activation release

Build from the reviewed commit with every runtime gate false and the kill switch
true. The release pipeline, not an interactive operator, copies the reviewed
artifact examples to these exact artifact paths:

```text
Laravel: bootstrap/cache/mainnet-pilot-activation.php
Fee Payer built release: dist/mainnet-pilot-activation.json
Wallet before production build: apps/web/mainnet-pilot-activation.json
```

For Fee Payer, build first, then install the artifact because `build` cleans
`dist`. For Wallet, install the artifact before its production build because
Vite compiles the capability into the bundle. For Laravel, install the artifact
before `php artisan config:cache`. Verify the artifact contents against the
reviewed examples and release checksum. Do not copy an artifact between service
types.

Start the live-capable Fee Payer safely while all runtime gates remain false:

```bash
cd /path/to/livt-fee-payer
corepack pnpm build
# reviewed deployment installs dist/mainnet-pilot-activation.json here
corepack pnpm start:mainnet-live-pilot

cd /path/to/jpyc-web3-payment-platform
php artisan mainnet:pilot-gate-status --env=mainnet-staging
```

It must bind literal `127.0.0.1`, health must remain available, sponsorship must
return unavailable, and gate status must be `SAFE_DEPLOYED`. The activation
artifact provides capability only; it opens no runtime gate.

### Phase 14 — enable gates in reviewed order; kill switch last

Configuration is immutable within each process. Prepare the complete reviewed
configuration while the existing health-only service remains active, keep the
payment UI unavailable, then restart Laravel and the live-capable Fee Payer once
into the complete gate set with the kill switch still active. This avoids
serving an intermediate partial-gate state. Enable in this reviewed order:

1. `PAYMENTS_MAINNET_ENABLED=true`.
2. Laravel and Fee Payer `SELF_HOSTED_MAINNET_FEE_PAYER_ENABLED=true`.
3. `MAINNET_FEE_DELEGATION_ENABLED=true` and the existing generic Laravel
   `KAIA_FEE_DELEGATION_ENABLED=true`. Keep provider mode `self-hosted` and the
   authenticated sidecar URL on literal loopback.
4. Fee Payer `FEE_PAYER_MAINNET_ENABLED=true` and
   `FEE_PAYER_MAINNET_SIGNING_ENABLED=true`.
5. `MAINNET_BROADCAST_ENABLED=true`.
6. `FEE_PAYER_MAINNET_BROADCAST_ENABLED=true`.
7. Restart both backend processes, keep the Wallet UI unavailable, and require
   `ARMED_NOT_LIVE` plus configuration, identity, signer, policy, balance,
   unexpired Payment, and zero attempts.
   Run the read-only gas observation and require both observed gas and gas price
   to remain within the approved caps:

   ```bash
   php artisan mainnet:pilot-gas-observation --env=mainnet-staging
   php artisan mainnet:pilot-gate-status --env=mainnet-staging
   ```
8. Remove any `dist/mainnet-kill-switch` emergency marker, set both Laravel and
   Fee Payer `FEE_PAYER_KILL_SWITCH=false` LAST, restart them once, and require
   `LIVE_ENABLED` immediately before exposing the one Payment UI. Re-run
   `mainnet:pilot-gate-status`; it verifies the latest unexpired Payment,
   attempt count, approved identities and immutable snapshot, Fee Payer policy,
   balance, and a fresh two-RPC gas observation.

STOP if the reviewed release cannot remain running safely with the kill switch
active, or if the gate transition cannot be observed atomically.

### Phase 15 — submit exactly once

Open only the configured Payment in one browser/tab/device. Authenticate the one
approved User, connect the one approved sender, recheck chain/merchant/token/1
JPYC, and click once. The wallet sender-signs type `0x31` locally and sends only
the sender-signed raw transaction to Laravel. It must never self-broadcast in
self-hosted mode.

### Phase 16 — never retry ambiguity

If no definitive transaction hash is returned, close the payment action path.
Do not click again, refresh into a send path, use a second device, call the Fee
Payer manually, or fall back to direct transfer. Preserve `unknown_submission`.

### Phase 17 — resolve by SenderTxHash only

Use the existing resolver, which performs read-only
`kaia_isSenderTxHashIndexingEnabled`,
`kaia_getTransactionBySenderTxHash`, and
`kaia_getTransactionReceiptBySenderTxHash` checks. It may update the existing
attempt with resolved evidence; it must never create another attempt or call a
broadcast method. If providers disagree or find nothing, leave it unknown and
escalate to manual investigation.

### Phase 18 — confirm the Payment

Confirm only the resolved/returned canonical transaction hash. Require matching
request, transaction and receipt hashes; canonical block number/hash; successful
receipt; exact JPYC Transfer contract/sender/recipient/atomic amount; explicit
`removed=false`; log index; and block timestamp within the Payment window.

### Phase 19 — reconcile read-only

```bash
php artisan payments:reconcile <PAYMENT_ID> --env=mainnet-staging
```

This command only reports `verified`, `anomaly`, or `transient_failure`. It does
not update Payment status, stored confirmation evidence, or reconciliation
metadata. A transient RPC failure does not invalidate a confirmed Payment.

### Phase 20 — reactivate kill switch first

Immediately after the single attempt, whether success, revert, failure, or
unknown, create the Fee Payer release's `dist/mainnet-kill-switch` emergency
marker FIRST. It is rechecked immediately before KMS Sign and immediately before
broadcast. Confirm sponsorship requests are refused, then set
`FEE_PAYER_KILL_SWITCH=true` in both approved configurations and restart. A
pre-broadcast kill-switch refusal is durable `failed`; only an outcome after a
broadcast call may become `unknown_submission`.

### Phase 21 — disable every Mainnet gate

Set all Mainnet payment, fee-delegation, signing, and broadcast flags back to
false and restart. Stop the live Fee Payer endpoint and start the health-only
entrypoint if continued observation is approved. Keep the emergency marker
until the live endpoint has stopped.

### Phase 22 — prove shutdown

```bash
php artisan mainnet:pilot-shutdown-check --env=mainnet-staging
```

Require `SHUTDOWN_VERIFIED`, local execution/broadcast `DISABLED`, kill switch
`ACTIVE`, and Fee Payer gates `DISABLED`.

### Phase 23 — preserve the audit record

Record only approved non-secret data: change/approval IDs, Payment/attempt IDs,
SenderTxHash, final tx hash, block number/hash, log index, payer/sender/merchant,
exact amount, timestamps, actual gas fee, KMS audit event reference, preflight,
shutdown proof, and reconciliation result. Never record raw transactions,
bearer tokens, generated credentials, certificate private keys, or RPC URLs.

## Explicit STOP conditions

STOP before submission for any non-ready check, mismatched identity or provider,
expired/changed Payment, existing attempt, balance outside range, gas above cap,
signer degradation, inactive kill switch, unexpected executable route, log/alert
failure, or absent human approver. STOP after submission for ambiguity, provider
disagreement, receipt mismatch, reorg evidence, or any request to retry.

Never delete or rewrite evidence, reset the database, switch to Kairos data,
raise a cap during the live window, add funds after opening gates, or replace an
unknown attempt.

## Ubuntu VPS migration checklist

1. Provision a dedicated Ubuntu host and non-login service users. Patch the OS,
   firewall inbound traffic, and synchronize time.
2. Deploy immutable reviewed revisions of all three repositories. Install pinned
   PHP/Composer, Node/Corepack/pnpm, required PHP extensions, and build assets.
3. Create a dedicated MySQL database/user for Mainnet staging. Verify its name
   before applying reviewed migrations. Never copy or point to the Kairos DB.
4. Provision Redis with authentication/private networking and a dedicated cache
   prefix. All Laravel workers must share the same backend and prefix.
5. Create operator environment files on the VPS with owner-read permissions;
   never copy tracked examples as secrets or commit the files.
6. Configure two different commercial Kaia Mainnet RPC providers. Restrict the
   credentials at the provider where possible and keep full URLs out of logs.
7. Install `aws_signing_helper` from the approved AWS distribution and verify its
   checksum/version. Configure `credential_process` in a service-user AWS
   profile; do not deploy static AWS access keys.
8. Issue a NEW VPS-specific Roles Anywhere end-entity certificate. Deploy only
   that certificate, chain, and its private key with least privilege (service
   user only, directories non-world-readable). Never copy the CA private key to
   the VPS. Retire/revoke the WSL development certificate when appropriate.
9. Restrict the Roles Anywhere role and KMS key policy to required identity,
   key, region, and signing operations. Independently derive and verify the KMS
   Fee Payer address.
10. Run the Fee Payer health process as a hardened systemd service: dedicated
    user, fixed working directory, `EnvironmentFile` outside the repository,
    restart limits, `NoNewPrivileges`, private temporary directory, filesystem
    protections, and loopback-only bind. Do not expose its API port publicly.
11. Run Laravel queue/web processes as separate hardened services. Use a local
    Unix socket or loopback connection to Fee Payer, and restrict its bearer
    secret to the Laravel service account.
12. Put only the user-facing Laravel/Wallet endpoint behind a TLS reverse proxy.
    Set request/body/time limits and redact Authorization, cookies, RPC URLs,
    raw transactions, and environment values from access/error logs.
13. Route fixed diagnostic codes and audit identifiers to protected logs with
    retention and alerting. Validate disk rotation before the pilot.
14. Run migration status and `--pretend`; obtain change approval; apply only to
    the new empty Mainnet staging DB; then run READ_ONLY_READY checks.
15. Exercise service stop/start, kill-switch-first shutdown, KMS permission
    denial, RPC failure, Redis failure, and alert delivery without a Payment raw
    transaction.
16. Repeat Phases 0–12 on the VPS. Do not copy WSL database contents, credentials,
    private keys, or Roles Anywhere identity material.

## One-broadcast ownership

Laravel validates and persists the attempt fingerprint/SenderTxHash before the
network call. It does not call Kaia broadcast RPC. The self-hosted Fee Payer is
the sole owner of `kaia_sendRawTransaction`, uses the primary RPC only, and has
transport retry count zero. Secondary RPC is observation/resolution only. AWS
KMS Sign is configured for one SDK attempt. Ambiguous broadcast outcomes remain
durable `unknown_submission`; only read-only resolution is permitted.
