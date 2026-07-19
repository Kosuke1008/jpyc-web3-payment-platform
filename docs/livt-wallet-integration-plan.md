# LivT Wallet Integration Plan

Status: architecture and integration plan only. No implementation has been performed.

## Scope and repository mapping

The Laravel repository described as `~/projects/livt` is checked out in this workspace as `~/projects/jpyc-web3-payment-platform`. Its `AGENTS.md` identifies it as LivT, so this document lives in that repository. The wallet repository is `~/projects/livt-wallet`.

The recommended first integration is a separate-origin, redirect-based adapter:

```text
LivT payment page
  -> opens LivT Wallet with only a payment ID
  -> LivT Wallet fetches canonical public payment details from LivT
  -> LivT Wallet validates, signs locally, and broadcasts the JPYC transfer
  -> LivT Wallet returns only payment ID + transaction hash to LivT
  -> LivT payment page submits that hash with its existing LivT user token
  -> the existing LivT backend verifier determines payment success
```

This keeps the repositories and deployments separate, does not expose LivT authentication to the wallet, does not expose wallet secrets to LivT, and leaves MetaMask available as an independent payment option.

## 1. Current LivT payment architecture

### Payment creation

- Staff authentication is handled by `POST /api/staff/login` in `app/Http/Controllers/Api/StaffAuthController.php`. It returns a Laravel Sanctum bearer token with `payment:create` and `payment:view` abilities.
- `resources/views/pos/index.blade.php` and `public/js/pos.js` use that token to call `POST /api/payments/create`.
- `PaymentController::create()` validates the amount, derives the store and staff from the authenticated staff token, creates a ten-minute `pending` payment, and returns the payment ID, public `/pay/{id}` URL, and SVG QR code.
- The QR contains the ordinary LivT payment-page URL. No wallet-specific payload is stored in the database.

### Customer payment page and MetaMask

- `GET /pay/{id}` is a public web route handled by `app/Http/Controllers/PayController.php`, which renders `resources/views/pay.blade.php` with the payment and store.
- Before allowing a transfer, the page requires a LivT user token named `user_token` in LivT-origin `localStorage`. Missing authentication redirects to `/user/login`, preserving the return path in LivT-origin `sessionStorage`.
- The Blade page uses ethers v6 and `window.ethereum` to connect to MetaMask, select the configured chain, read the JPYC balance and decimals, request `transfer`, wait for a receipt, and then call LivT's confirmation API.
- Mobile MetaMask is supported with a MetaMask dapp deep link.
- The current page obtains the token address and network metadata from `config/services.php`, but the transfer recipient is hard-coded in `resources/views/pay.blade.php`. The backend independently obtains the expected recipient from the store's `wallets` row. That duplication can drift and is the most immediate reason to make the backend payment intent canonical for both wallet clients.

### Backend confirmation and status

- `POST /api/payments/{id}/confirm` is protected by `auth:sanctum`. Its request body contains only `tx_hash`.
- `PaymentController::confirm()` loads the payment, rejects missing, confirmed, or expired payments, rejects a hash already used by another payment, and queries the configured RPC with `eth_getTransactionReceipt`.
- It does not trust the browser's receipt or success claim. It checks receipt status and searches receipt logs for an ERC-20 `Transfer` event whose:
  - emitting contract matches the configured token contract;
  - recipient matches the store wallet stored in LivT's database; and
  - amount equals the payment amount multiplied by `10^18`.
- On a valid receipt, it sets `status=confirmed`, saves the normalized transaction hash and payment time, and associates the authenticated LivT user.
- Both the payment page and POS poll public status endpoints. `GET /api/payments/{id}` exposes payment display/status data; `GET /api/payments/status/{id}` exposes only status.

### Data model used by the live flow

- `payments`: store, staff, optional LivT user, integer amount, status, unique nullable transaction hash, expiration, and payment time.
- `wallets`: store recipient address and network metadata.
- `users`: LivT account data and an optional unique `wallet_address`, although the current login/payment flow does not establish or enforce ownership of that address.
- `App\Models\Transaction` and `Payment::transaction()` exist, but no transaction-table migration or active use was found. The integration should not depend on that model.

### Current verification limitations to preserve or harden deliberately

- Chain selection is currently implicit in the configured RPC URL; the verifier does not call `eth_chainId` and compare it with the configured chain ID.
- The verifier does not validate or record the `Transfer` sender. There is no reliable wallet-address ownership boundary today, so accepting a client-reported sender would not fix this.
- Token decimals are assumed to be 18 in the backend.
- Duplicate checking and payment update are not one atomic locked operation. The unique `payments.tx_hash` constraint is useful, but concurrent confirmation should be covered by tests and ideally a short database transaction.
- Confirmation behavior has little test coverage compared with payment creation.

These are existing conditions, not reasons to create a second verification path. Any hardening should be applied once in a shared backend verifier and therefore benefit both MetaMask and LivT Wallet.

## 2. Current LivT Wallet architecture

- LivT Wallet is a standalone React/TypeScript/Vite application under `apps/web`; it is not embedded in Laravel and has no backend.
- `apps/web/src/app/App.tsx` is a single React application with local view state (`home`, `receive`, `send`, and `settings`). It currently has no URL router or payment-link mode.
- It supports only Kaia Kairos (chain ID `1001`) and one approved JPYC contract with 18 decimals. Network and approved-token definitions are fixed in `blockchain/kairos.ts` and `tokens/tokenRegistry.ts`.
- Public chain reads and broadcasts use a viem public client. RPC boundaries are represented by small interfaces that tests can replace.
- The manual send UI validates recipient and decimal-string amount, checks the chain and approved token metadata, simulates the transfer, estimates gas, checks KAIA, locally signs a legacy Kairos transaction, broadcasts once, and waits for the receipt.
- `executeJpycTransfer()` in `tokens/jpycTransfer.ts` is the main reusable transaction orchestration function. `JpycTransferIntent` is the reusable validated transfer input.
- Receipt timeout and unknown broadcast state deliberately do not trigger automatic resend. The computed transaction hash is retained when the outcome may be unknown.

### Local wallet storage

- A BIP-39 mnemonic is generated in the browser.
- The mnemonic is encrypted with AES-256-GCM using a PBKDF2-SHA-256 key derived from the user's password and a random salt. The password is not stored.
- Only the encrypted payload, salt, IV, KDF metadata, and public address are persisted in wallet-origin `localStorage` under `livt-wallet:encrypted-wallet`.
- The mnemonic is decrypted only when unlocking or signing. `withStoredSigningAccount()` derives a temporary viem local account, verifies that it matches the expected stored address, and passes it only to the signing operation.
- LivT Wallet does not use MetaMask or `window.ethereum`.

### Existing test shape

- Unit tests cover wallet creation, encryption/storage, signer-address matching, chain configuration, token registry, validation, and transfer state.
- Integration tests cover the mocked RPC boundary, local signed transaction fields, chain mismatch, wrong password, sender mismatch, single broadcast, receipt success/revert, timeout, and unknown outcomes.
- Playwright tests exercise browser wallet creation/unlock, balance reads, manual transfers, error states, and one-broadcast behavior with a mocked Kairos RPC.

## 3. Existing reusable APIs

### LivT HTTP APIs

| API | Authentication | Reuse in the integration |
| --- | --- | --- |
| `GET /api/payments/{id}` | Public | Reuse as the canonical payment-intent read after adding a few backward-compatible fields. |
| `POST /api/payments/{id}/confirm` | LivT user Sanctum token | Reuse unchanged for both MetaMask and LivT Wallet transaction hashes. This remains the only payment-finalization path. |
| `GET /api/payments/status/{id}` | Public | Reuse for POS polling; no wallet-specific change is needed. |
| `POST /api/user/login` | Public credentials exchange | Keep on LivT. The wallet should not proxy it or receive its token. |
| `POST /api/payments/create` | Staff token with `payment:create` | Keep unchanged; the same payment and QR should offer both wallet choices. |
| `GET /api/user/payments` | LivT user token | Keep unchanged; confirmed LivT Wallet payments appear because confirmation still sets `user_id`. |

### LivT Wallet code interfaces

- `createJpycTransferIntent()` can validate a server-supplied recipient and amount without accepting arbitrary payment edits.
- `executeJpycTransfer()` can be reused without changing encryption or signing code.
- `JpycTransferRpcClient`, `KairosTransactionClient`, and the signing-account provider are already suitable test seams.
- The transfer phase callback already exposes the transaction hash after broadcast, including the information needed for a safe return-to-LivT action.

## 4. Required minimal API changes

### Extend the existing payment read response

Keep `GET /api/payments/{id}` and all existing fields. Add canonical, read-only fields needed to construct and verify the wallet's locked payment review:

- `chain_id`
- `network`
- `token_address`
- `token_symbol` (`JPYC`)
- `token_decimals` (`18` for the current approved token)
- `recipient_address` from the payment store's `wallets` row
- `amount_atomic` as a decimal integer string, or enough string-valued amount/decimals data to calculate it without JavaScript floating point

The endpoint should continue returning the payment ID, store name, amount, status, and expiration. It should fail cleanly if the store has no configured recipient rather than letting either client guess one.

Because LivT Wallet should be hosted on a separate origin, permit narrowly scoped CORS only for the public payment-intent `GET` from the configured LivT Wallet origin. Do not enable credentialed cross-origin requests and do not expose authenticated LivT APIs through CORS.

### Keep the confirmation contract source-neutral

Do not add client-reported `success`, `amount`, `recipient`, `token`, `chain`, `sender`, or wallet-provider fields to the trusted confirmation input. The request should remain:

```json
{ "tx_hash": "0x..." }
```

The existing authenticated endpoint should resolve all expected values from LivT configuration and database state. It should not branch on `MetaMask` versus `LivT Wallet`.

### Isolate and harden the common verifier

Move RPC receipt and `Transfer`-log validation from the controller into one small service/interface, while keeping the public route and response contract stable. At minimum, the common verifier should check:

- configured RPC chain ID equals the expected chain ID;
- receipt exists and has successful status;
- token contract, recipient, and exact atomic amount match server-side expectations;
- transaction hash is well-formed and cannot be reused;
- payment is pending and unexpired at the point of update;
- confirmation update is concurrency-safe; and
- `Transfer` sender is derived from the log/transaction, never trusted from the client. Enforce an expected sender only after LivT has a separate, reliable address-ownership mechanism.

This service extraction is a local refactor, not a second API. It can be deployed and regression-tested before the wallet button is visible.

### Changes explicitly not required

- No new payment table or wallet table.
- No broad schema migration.
- No storage of wallet-provider type.
- No storage of mnemonic, private key, password, encrypted wallet payload, or decrypted material.
- No rename of `/pay/{id}` or any existing API route.
- No new dependency is required in either repository for the basic integration.

## 5. Required minimal frontend changes

### LivT

- Add a separate **Pay with LivT Wallet** button to `resources/views/pay.blade.php`; leave the MetaMask connect/pay buttons and handlers available.
- Build a wallet launch URL containing only a mode/version marker and payment ID, for example `...?mode=livt-payment&payment_id=123`. The wallet base URL should be an environment-backed public setting, and the button should be hidden when it is absent.
- Before launch, retain the existing pre-transfer LivT authentication check. Never place the Sanctum token in the wallet URL, fragment, `postMessage`, or wallet storage.
- On return, accept only a well-formed transaction hash tied to the current payment ID. Prefer a URL fragment so the hash is not sent as an HTTP request parameter or referrer, then immediately remove it with `history.replaceState`.
- Submit the returned hash through the existing `confirmPayment()` function and existing LivT user token.
- If LivT authentication expired after broadcast, store the public payment ID/hash temporarily in LivT-origin `sessionStorage`, send the user through the existing login page, then resume **confirmation only**. Never ask the wallet to rebroadcast.
- Continue polling the existing payment status so another tab or a delayed confirmation is reflected.

### LivT Wallet

- Add a small payment-link parser and `LivtPaymentApiClient` under an isolated integration folder. Do not turn the whole application into a general router or LivT frontend.
- When a supported payment link is present, fetch the canonical intent from the configured, allowlisted LivT origin.
- Parse the response with a strict runtime schema and reject mismatched chain ID, token address, symbol, decimals, payment ID, non-pending status, missing recipient, invalid amount, or expiration.
- Show a dedicated locked payment review: store, recipient, amount, Kairos chain, JPYC contract, sender, and fee warning. The recipient and amount must not be editable in payment mode.
- Reuse `createJpycTransferIntent()` and `executeJpycTransfer()` for validation, simulation, local signing, one-time broadcast, and receipt handling.
- Return only the payment ID and public transaction hash to a callback URL derived from a fixed/allowlisted LivT origin. Do not accept an arbitrary `return_url`, which would create an open redirect and phishing surface.
- For success, timeout, or unknown confirmation states after broadcast, preserve the hash and provide a return-to-LivT verification action. Do not automatically resend.
- Keep ordinary manual JPYC send and all existing wallet views working when payment mode is absent.

## 6. Authentication boundary

The two authentication concepts must remain separate:

- **LivT authentication** identifies the customer account that owns the LivT payment history. It stays on the LivT origin and is required when calling `/confirm`.
- **Wallet unlock/password confirmation** authorizes local access to signing material. It stays on the LivT Wallet origin and never authenticates the user to LivT.

The handoff contains public identifiers only:

```text
LivT -> Wallet: payment ID
Wallet -> LivT: payment ID + transaction hash
```

No bearer token is copied between origins. LivT Wallet does not call authenticated LivT APIs. LivT does not infer account identity from the wallet address. A future optional address-linking feature would require a separate signed-message challenge and is outside the minimal payment integration.

## 7. Wallet security boundary

- Mnemonic, private key, password, derived encryption key, decrypted mnemonic, and temporary signing account remain exclusively in LivT Wallet browser memory/storage.
- The only persistent wallet data remains the existing encrypted wallet payload and public address on the wallet origin.
- LivT receives only public payment data and a public transaction hash.
- LivT Wallet should fetch only public canonical payment fields. It should not load or execute JavaScript supplied by LivT and should not embed the LivT payment page in an iframe.
- Both applications need normal XSS controls, but separate origins limit blast radius. Hosting the wallet under the LivT origin is not recommended because it would widen access to wallet-origin storage.
- Payment URLs, logs, analytics, error reporting, and tests must never contain a mnemonic, private key, password, decrypted material, or signed raw transaction. Only the transaction hash is handed back.
- Wallet payment mode must compare the server intent against the wallet's compiled approved Kairos/JPYC registry before signing. A server response alone must not make an arbitrary chain or token trusted.

## 8. MetaMask compatibility strategy

- Preserve the current `/pay/{id}` route, QR payload, MetaMask buttons, `window.ethereum` handling, ethers transfer flow, mobile deep link, and status polling.
- Add LivT Wallet as an adjacent option, not a replacement or provider shim.
- Do not make LivT Wallet emulate `window.ethereum`; direct payment intent is smaller and avoids changing the current MetaMask integration.
- Make both clients submit only a transaction hash to the same `/api/payments/{id}/confirm` endpoint.
- Use the same backend-configured chain, JPYC contract, store recipient, amount conversion, expiration, duplicate protection, and confirmation status for both.
- Do not introduce provider-specific verification branches or provider-specific database columns.
- Roll out the LivT Wallet button behind a simple configuration/feature switch. With it disabled, the current MetaMask experience is unchanged.

## 9. Proposed integration sequence

1. **Characterize the current verifier.** Add confirmation feature tests around existing behavior before refactoring. Resolve the hard-coded Blade recipient by making server-side payment intent data canonical.
2. **Extract the shared backend verifier.** Keep `/confirm` and its body unchanged; add explicit chain checks and concurrency-safe finalization behind regression tests.
3. **Extend the public payment read.** Add the canonical chain/token/recipient/atomic-amount fields and narrow read-only CORS. Do not expose authentication or secrets.
4. **Add LivT Wallet payment mode.** Implement an isolated intent client/parser and locked payment panel that reuses the existing viem transfer engine. Test it independently with fake LivT HTTP and Kairos RPC clients.
5. **Add return-to-LivT behavior.** Return the hash through a fixed callback fragment and cover success, revert, timeout, unknown broadcast, expired auth, and repeated callback handling. Confirmation retries may repeat the backend lookup but must never repeat the blockchain broadcast.
6. **Add the optional LivT button.** Keep MetaMask as the default existing path; show the new option only when the wallet URL is configured.
7. **Run an opt-in Kairos trial.** Enable for internal/test users, compare both clients against identical backend verification cases, and monitor confirmation failures without logging sensitive wallet data.
8. **Expand gradually.** Enable the wallet option more broadly only after mobile return behavior and delayed-receipt recovery are reliable. Repository merging, SPA migration, mainnet, and account/address linking remain separate future decisions.

Each step is independently deployable and reversible. The backend verifier and payment-intent response should land before exposing the new button.

## 10. Files likely to be modified

Exact names for new files can change during implementation, but the likely minimal surface is:

### LivT (`~/projects/jpyc-web3-payment-platform` in this workspace)

- `app/Http/Controllers/Api/PaymentController.php` — delegate confirmation and extend the existing public payment response.
- A new service such as `app/Services/Payments/PaymentTransactionVerifier.php` — shared, source-neutral receipt verification.
- Possibly a small payment-intent resource/presenter under `app/Http/Resources` or `app/Services/Payments` — stable response mapping.
- `resources/views/pay.blade.php` — additive wallet button, launch, callback, and confirmation-resume logic while retaining MetaMask.
- `config/services.php` and `.env.example` — only the public LivT Wallet base URL/origin names; no secrets.
- Possibly a narrow `config/cors.php` entry if deployment origins differ.
- New feature tests such as `tests/Feature/PaymentShowTest.php` and `tests/Feature/PaymentConfirmTest.php`.
- Existing `tests/Feature/PaymentCreateTest.php` only if response expectations need additive coverage.

`routes/api.php` does not need modification if `GET /api/payments/{id}` is extended rather than adding a new route.

### LivT Wallet

- `apps/web/src/app/App.tsx` — select isolated payment mode when a valid payment link is present.
- A new folder such as `apps/web/src/integrations/livt/` containing the link parser, strict response schema, API client, callback builder, and payment-mode component.
- `apps/web/src/app/styles.css` — only styles required by the new isolated panel.
- New unit and integration tests under `apps/web/tests` and focused Playwright cases in `apps/web/e2e/app.spec.ts` or a separate payment spec.
- A small public build-time setting declaration for the allowlisted LivT API/origin if needed.

No package manifest or lockfile change should be necessary because React, viem, and zod already provide the required primitives.

## 11. Files that should not be modified

Unless a later, separately approved requirement proves otherwise, the first integration should not modify:

- Any existing database migration or create a broad schema migration.
- `app/Models/User.php`, `Wallet.php`, `Payment.php`, or the unused `Transaction.php` merely to record wallet provider type.
- Staff authentication, user authentication, POS behavior, user payment history, or their public routes.
- The existing `/pay/{id}` and `/api/payments/{id}/confirm` route names.
- Existing MetaMask transfer, mobile deep-link, and polling behavior except for small shared helpers needed by the additive button/callback.
- `apps/web/src/wallet/encryptedWallet.ts`, `signingAccount.ts`, or `wallet.ts`.
- The core wallet encryption format or storage key.
- The approved Kairos chain/token registry except to compare it with LivT intent data.
- `package.json`, `pnpm-lock.yaml`, `composer.json`, or `composer.lock`.
- Generated wallet `dist/` assets by hand.
- Either repository's Git history, remote, or directory layout.

## 12. Risks and rollback plan

### Main risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Laravel checkout path differs from the requested `~/projects/livt` path | Treat the confirmed LivT repository as authoritative; do not rename or duplicate it as part of integration. |
| Hard-coded MetaMask recipient drifts from the store wallet verified by the backend | Serve recipient canonically from LivT and use the same server-derived value for both client displays/transfers. |
| Cross-origin token leakage | Wallet calls only public intent `GET`; authenticated confirmation occurs only after returning to LivT origin. Never put bearer tokens in URLs or messages. |
| Tampered payment link or malicious LivT-like server | Pass only payment ID, use a fixed LivT API origin, strictly parse intent, and require exact Kairos/approved-JPYC matches before signing. |
| Open redirect or phishing callback | Derive callback from an allowlisted LivT origin and payment ID; do not honor arbitrary return URLs. |
| RPC/configuration drift between applications | Wallet rejects intent values that differ from its approved registry; backend remains final authority. Add a deployment smoke test for chain ID, contract, decimals, and recipient. |
| Backend trusts wallet receipt/success | Never send or accept client success as proof. Backend independently reads and verifies the receipt/logs. |
| Receipt is delayed after broadcast | Preserve the hash, allow confirmation-only retry, continue polling, and never automatically rebroadcast. |
| Duplicate or concurrent confirmation | Normalize hash, retain the unique constraint, and perform state/hash checks plus update in a database transaction/lock. Make repeat handling explicit and idempotent where safe. |
| Auth expires after funds are sent | Save only the public hash/payment ID in LivT `sessionStorage`, reauthenticate on LivT, then retry backend verification without returning to signing. |
| Sender/account attribution is ambiguous | Do not trust client sender. Derive it from chain data. Do not enforce `users.wallet_address` until ownership is separately proven without breaking MetaMask account choice. |
| Wallet-origin XSS exposes local encrypted data or password input | Keep separate origin, strict CSP/dependency hygiene, no third-party script injection in payment mode, and no secret telemetry. This integration must not weaken existing wallet controls. |
| Public payment IDs can be enumerated | The endpoint is already public; expose only fields necessary for payment, avoid customer/staff data, apply ordinary rate limiting, and keep short expiration. |
| Mobile browser/app return loses state | Use URL-fragment callback plus session-backed confirmation recovery and test real mobile browser/PWA behavior before broad rollout. |

### Rollback

- Disable or remove the LivT Wallet URL/feature switch. This hides the additive button and immediately returns all users to the existing MetaMask flow.
- Leave the additive payment-intent response fields in place or revert them; existing clients ignore extra JSON fields.
- Revert the wallet payment-mode entry point without touching manual wallet functions or encrypted storage.
- Roll back verifier refactoring independently only if regression tests reveal a behavioral problem. No database downgrade should be needed.
- Narrow/remove the wallet-origin CORS allowance when the wallet option is disabled.
- Blockchain transfers already broadcast cannot be rolled back. Recovery means retaining the hash and safely retrying server verification, never issuing a compensating or duplicate transfer automatically.

## 13. Test strategy

### LivT backend feature tests

- Public payment intent returns existing fields plus canonical chain, token, decimals, recipient, expiration, and exact amount representation.
- Missing store wallet, expired payment, confirmed payment, and nonexistent payment return deliberate responses.
- Confirmation rejects unauthenticated callers, malformed hashes, absent receipts, reverted receipts, wrong chain, wrong token contract, wrong recipient, wrong amount, malformed logs, expired payments, already-confirmed payments, and duplicate hashes.
- Confirmation succeeds only for the exact `Transfer` log and records status, hash, payment time, and authenticated user.
- Concurrent requests cannot apply one hash twice or confirm one payment twice.
- The RPC layer is faked; tests must not use a live RPC or sleep.
- Run the same confirmation fixtures without a provider/source field. This demonstrates that MetaMask and LivT Wallet use one verification path rather than two subtly different branches.

### LivT frontend/browser tests

- Existing MetaMask controls and handlers still render and behave when the wallet feature is disabled and enabled.
- Wallet launch contains only allowlisted public fields and never the user token.
- Callback accepts only the current payment ID and a valid 32-byte transaction hash, clears the fragment, and invokes the existing confirm API once per attempt.
- Missing/expired LivT auth resumes confirmation after login without relaunching or rebroadcasting.
- Pending/confirmed/expired status polling remains unchanged.

### LivT Wallet unit and integration tests

- Link parser accepts the supported mode/payment ID and ignores ordinary wallet sessions.
- API client uses only the configured LivT origin and rejects malformed or extra-trust data.
- Intent validation rejects chain, token, decimals, recipient, amount, status, expiration, and payment-ID mismatches before simulation or signing.
- Payment mode locks recipient and amount and clearly displays all material transfer details.
- Existing `executeJpycTransfer()` tests remain the transaction-engine contract.
- On successful receipt, the callback contains only payment ID/hash and uses the fixed LivT origin.
- Revert does not offer success; timeout and unknown broadcast retain the hash and do not resend.
- Outbound LivT requests, callback URLs, console output, and telemetry never include mnemonic, password, private key, decrypted wallet material, encrypted payload, or raw signed transaction.

### End-to-end Kairos tests

- Staff creates one payment; the existing QR/payment page offers MetaMask and, when enabled, LivT Wallet.
- MetaMask path still confirms through the common backend verifier.
- LivT Wallet fetches the intent, rejects tampering, signs once, returns the hash, and LivT confirms through the same endpoint.
- POS and customer history observe the same confirmed payment state for either wallet.
- Test wrong recipient, wrong amount, expired payment, insufficient JPYC, insufficient KAIA, user rejection, RPC failure before broadcast, unknown state after broadcast, delayed receipt, expired LivT login, duplicate callback, refresh/back navigation, and mobile return.
- Start with mocked RPC tests, then run a small real Kairos smoke suite using non-production test accounts. Never use or log real wallet secrets in fixtures.

## Acceptance criteria for the first rollout

- MetaMask remains fully usable and unchanged from a customer's perspective.
- LivT Wallet is opt-in and independently disableable.
- Both wallet choices end at the same source-neutral backend confirmation endpoint and verifier.
- LivT never receives wallet secrets, and LivT Wallet never receives LivT credentials.
- No database schema change, dependency installation, repository merge, route rename, or SPA migration is required.
- A transaction is shown as paid only after LivT independently verifies the configured-chain receipt and exact JPYC transfer.
