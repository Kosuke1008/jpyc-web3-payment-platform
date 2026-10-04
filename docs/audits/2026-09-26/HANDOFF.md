# LivT 3リポジトリ静的監査 引き継ぎメモ

監査日: 2026-09-26 (Asia/Tokyo)

## 基準状態

| リポジトリ | ブランチ | HEAD | 指定との差 | 開始時worktree |
|---|---|---|---|---|
| Laravel | `fix/qr-payload` | `61e33c4711e473022a40f511b8fb30a35d52e4ca` | なし | clean |
| Wallet | `agent/livt-wallet-payment-integration` | `103f03f61ca7f35644564a3b13361f609b2fab82` | なし | clean |
| Fee Payer | `main` | `71144ffb3694f284b85e56172a07fde8c0edc525` | なし | clean |

監査開始時に既存の未コミット変更はなかった。この表とファイル別確認表は上記の基準commit時点の記録である。後続の実装は2026-10-04にLaravel `d6d914e`、Wallet `d4f1953`、Fee Payer `c78e3fb`としてコミットした。以下の記述は静的監査時点の事実と後続の開発・Mainnet実証を時系列で分けて読む。

## 範囲と確認方法

- `git ls-files`で各HEADの追跡ファイルを列挙した。
- 各ファイルは `git show HEAD:<path>` でblob全体を取得し、bytes、lines、SHA-256を記録した。ツール出力の表示省略を完全取得の根拠にはしていない。
- 人が書いたsource、設定、test、migration、運用command、documentは全文静的確認の対象とし、決済作成からsender署名、Fee Payer署名、broadcast、attempt遷移、receipt、Payment確定、鍵管理、認証の経路は個別に行番号付きで追跡した。
- 生成lock、同梱minified第三者library、画像だけを内容監査対象外とした。対象外でもblob全体の取得とhash記録は行った。
- 前回Google Driveメモは調査項目のチェックリストとしてのみ利用し、結論は現HEADで再検証した。
- `.env`、`node_modules`、`vendor`、storage、未追跡build生成物は読んでいない。秘密値、signed raw transaction、Mainnet送信、DB操作も扱っていない。

| リポジトリ | 追跡 | 内容を確認 | 生成物等で対象外 | 未確認 |
|---|---:|---:|---:|---:|
| Laravel | 219 | 215 | 4 | 0 |
| Wallet | 97 | 96 | 1 | 0 |
| Fee Payer | 39 | 38 | 1 | 0 |
| 合計 | 355 | 349 | 6 | 0 |

ファイル別の取得根拠と分類は次を参照する。

- [Laravel全追跡ファイル表](./jpyc-web3-payment-platform-files.md)
- [Wallet全追跡ファイル表](./livt-wallet-files.md)
- [Fee Payer全追跡ファイル表](./livt-fee-payer-files.md)

## End-to-end確認結果

1. Laravelのstaff認証済み作成APIは、店舗walletとnetworkを照合し、金額・期限・token・recipientをimmutable snapshotとしてpending Paymentへ保存する (`app/Http/Controllers/Api/PaymentController.php:101-175`)。
2. WalletはPayment ID、状態、期限、chain、token、recipient、amount、Mainnet pilot metadataを照合し、送信直前にもdetails不変性を再検査する (`livt-wallet/apps/web/src/payments/livtPaymentIntent.ts:80-218`)。
3. Walletは暗号化保存されたmnemonicを署名時だけ復号し、期待addressを二重照合する (`livt-wallet/apps/web/src/wallet/signingAccount.ts:20-45`)。fee-delegated transactionをsender署名後、Laravel sponsor APIを1回呼ぶ (`livt-wallet/apps/web/src/tokens/feeDelegatedJpycTransfer.ts:79-205`)。
4. Laravelはsender署名txのchain/token/recipient/amountをPayment snapshotと照合し、DB transaction内でPaymentごとのattemptを予約してからgatewayへ渡す (`app/Services/Payments/PaymentSponsorshipService.php:26-77,153-225`)。
5. Fee Payerはpolicy、期限、残高、pilot sender/merchant/amount/gasを検査し、MainnetではAWS KMS signerだけを許可する。署名済みrawのexpected hashを算出し、一度broadcastした後receiptを待つ (`livt-fee-payer/src/sponsor.ts:124-275`, `livt-fee-payer/src/config.ts:63-126`)。
6. Walletは既知hashをLaravelへ送り、Wallet側だけで成功確定せずLaravel verifierへ委ねる (`livt-wallet/apps/web/src/payments/livtPaymentFlow.ts:77-132`)。
7. Laravelはchain ID、transaction/receipt/blockの一貫性、receipt status、JPYC Transfer logのtoken/recipient/amount/payer、作成から期限までのblock timestampを検査する (`app/Services/Payments/OnChainPaymentEvidenceVerifier.php:30-109,194-212,243-306`; `app/Blockchain/KaiaFinalityPolicy.php:10-54`)。DB lock後もsnapshotを再照合してconfirmedへ遷移する (`app/Services/Payments/PaymentTransactionVerifier.php:20-113`)。

## 確認済みの問題

以下は引き継ぎ時HEADに対する監査結果である。certainty永続化、delegated confirm binding、標準MySQL test化は、後述の未コミット作業ツリーで修正・回帰確認済み。

### P0: broadcast certaintyがattempt履歴へ保存されず、安全でない再作成判定になり得る

確認済み事実:

- self-hosted gatewayはHTTP 400を`PROVIDER_REJECTED`にし得る一方、responseのcertaintyは独立に`broadcast_possible`/`submitted`も保持する (`app/Services/Payments/KaiaFeeDelegationHttpGateway.php:63-90`)。protocol欠落・不正値は保守的に`broadcast_possible`となる (`:298-307`)。
- しかしserviceは例外reasonだけで`rejected`等のstateを決め、certaintyをattemptへ保存しない (`app/Services/Payments/PaymentSponsorshipService.php:97-134`)。attempt schemaにもcertainty列がない (`database/migrations/2026_09_06_500000_create_payment_fee_delegation_attempts_table.php:14-35`)。
- expired Mainnet pilot Paymentの履歴判定は、self-hosted、`rejected`、HTTP 400、`provider_rejected`、hashなし等だけで安全と見なし得る (`app/Payments/MainnetPilotPaymentHistory.php:138-176`)。
- unit test自体がHTTP 400で全3 certaintyを同じ`PROVIDER_REJECTED`として受けるケースを固定している (`tests/Unit/SelfHostedFeeDelegationGatewayTest.php:71-121`)。

影響条件と推論:

- Fee PayerがHTTP 400と`broadcast_possible`または`submitted`を同時に返す矛盾応答、またはgateway/response変形が起き、そのattemptを持つexpired pilot Paymentをreplacement対象にすると、実送信可能性を失った履歴が「definitive rejection」と誤判定され得る。
- 現Fee Payerの通常policy rejectionは`definitely_not_broadcast`なので、実際に二重送信が起きた証拠ではない。静的に成立するfail-closed欠落である。

### P0: confirmがfee-delegation attempt・注文要求者とtxを結び付けない

確認済み事実:

- confirm APIはログインuser ID、URLのPayment ID、受領hashをverifierへ渡す (`app/Http/Controllers/Api/PaymentController.php:27-60`)。
- verifierはPayment snapshotに一致するon-chain evidenceを確認後、呼出user IDをPaymentへ保存する (`app/Services/Payments/PaymentTransactionVerifier.php:20-103`)。
- chain+tx hashの重複は防ぐ (`:145-161`) が、`payment_fee_delegation_attempts.tx_hash`、`requester_user_id`、`sender_address`との一致を確認しない。
- receipt verifierはtoken、recipient、amountとtransaction senderを検査するが、Payment固有のattempt/requesterまでは識別しない (`app/Services/Payments/OnChainPaymentEvidenceVerifier.php:88-109,243-306`)。
- third-party payerを意図的に許可するtestがあるため、単純に`users.wallet_address == payer_address`を強制する修正は互換性を壊す (`tests/Feature/PaymentConfirmTest.php:128-145`)。

影響条件と推論:

- 同じrecipient、token、amount、有効時間帯のpending Paymentが複数ある場合、同一chain transactionはどれにもsnapshot上適合し得る。最初にconfirmしたPaymentへhashとuserが割り当てられ、意図した注文との対応がずれる可能性がある。
- fee-delegated経路ではattemptのpayment/requester/sender/hashをconfirm時に照合し、意図的なthird-party/direct経路とは明示的に別policyにする必要がある。

### P1: 標準Laravel test設定がSQLite

- `phpunit.xml`は`DB_CONNECTION=sqlite`、`DB_DATABASE=:memory:` (`phpunit.xml:20-30`)。
- `composer test`は通常の`artisan test`を呼ぶ (`composer.json:47-52`)。project作成scriptもSQLite fileを作る (`composer.json:63-66`)。
- MySQL専用設定は別の`phpunit.mysql.xml`にあり、`livt_test`を強制する (`phpunit.mysql.xml:20-36`)。`tests/TestCase.php:13-22`のfail-closed検査はMySQL suite/default MySQLの場合だけで、標準SQLiteを拒否しない。

影響: 現状の標準test commandは「SQLiteを使わない」という本プロジェクトの絶対ルールに反する。この監査ではLaravel testを実行していない。

### P1: Wallet backup/recoveryは未実装

- 実装は生成、AES-GCM/PBKDF2暗号化、localStorage保存、復号・address復元まで (`livt-wallet/apps/web/src/wallet/encryptedWallet.ts:77-125,220-272`)。
- export/import、別端末復元、recovery UI/APIは存在せず、READMEにも未実装と明記される (`livt-wallet/README.md:61-77,224-239`)。
- password policyは空文字拒否だけ (`encryptedWallet.ts:77-83`)。

影響: browser data/profile消失時、現在のUIだけでは資産アクセスを復元できない。一般利用へ進める前の重大な運用要件である。

### P1: user payment履歴にstored XSS sink

- API由来の`amount`、`store_name`、`status`、`paid_at`、`tx_hash`をtemplate literalへ入れ、`innerHTML`へ一括代入する (`resources/views/user/payments.blade.php:99-121`)。

影響条件と推論: これらの値、とくに店舗名等へ攻撃文字列を保存できる経路があれば、履歴閲覧userのoriginで実行され得る。入力可能性の権限境界は別途動的確認が必要だが、sink自体は確認済み。

### P2: 認証endpointのrate limitが不統一

- `/payment/login`は`throttle:6,1`だが、`/staff/login`、`/user/register`、`/user/login`にはroute-level throttleがない (`routes/api.php:11-16`)。

影響: upstream/WAF等の制限がない構成ではcredential stuffing/brute force耐性が不足する。外部層の現設定は本静的監査範囲外。

### P2: 誤って追跡されたと見られるartifact

- Laravel rootの`@php`、`IlluminateFoundationComposerScripts::postAutoloadDump`、`stream`は0 byte。
- `staffs`は`tore-`だけ、`tore->staffs;`はTinker風のStore/Staff dumpとpassword hash、`public/js/select`はMySQL client help全文、`resources/views/pay_old`は未routeの旧hard-coded payment viewである。
- 現行`/pay/{id}`は`PayController`から`pay` viewを返し、`pay_old`参照はない (`routes/web.php:10`; `app/Http/Controllers/PayController.php:36`)。

これらは削除候補だが、監査では削除していない。

## 確認できた安全側の実装

- WalletはMainnetで署名前にunknown-attempt markerを保存し、sponsor開始可能性があれば自動再送を許さない (`livt-wallet/apps/web/src/app/LivtPaymentPanel.tsx:445-579`)。
- Wallet API transport failure、成功応答のmalformed hashは`sponsorship-unknown`になる (`livt-wallet/apps/web/src/payments/livtPaymentApi.ts:317-348`)。
- Fee Payerはbroadcast/broadcast-hash failureを`broadcast_possible`、receipt failureを`submitted`とし、`definitely_not_broadcast`だけprocess内attempt claimを解放する (`livt-fee-payer/src/sponsor.ts:168-175,243-315`)。
- Mainnet Fee Payerはprocess-local private keyを拒否し (`livt-fee-payer/src/config.ts:115-126`)、runtime gatesでexternal AWS KMS backendを要求する (`livt-fee-payer/src/sponsor.ts:330-346`)。AWS SDK retryは`maxAttempts: 1` (`livt-fee-payer/src/signer.ts:154-163`)。
- Fee Payer attempt ledgerはprocess内Mapで再起動をまたがない。READMEでも既知制約 (`livt-fee-payer/src/sponsor.ts:108-175`; `livt-fee-payer/README.md:290-303`)。

## 検証実績と未検証範囲

実行済み（非送信・非DB）:

- 全追跡blob 355件の完全取得、bytes/lines/SHA-256記録
- Laravel追跡PHP全件 `php -l`: pass
- Wallet `corepack pnpm typecheck`: pass
- Wallet `corepack pnpm lint`: pass
- Fee Payer `corepack pnpm typecheck`: pass

未実行:

- Laravel test: 標準設定がSQLiteのためSTOP
- MySQL integration/E2E: 専用`livt_test`接続情報・実行許可をこの監査では扱っていない
- Wallet/Fee Payer test suite: 読み取り専用監査を優先し、build/dist等の生成を伴うcommandは実行していない
- Mainnet RPC/AWS KMS/送信、Mainnet DB、Payment 5/6変更: 未実施

従って本書の問題判定は現commitに対する静的に確認済みの事実と、その成立条件を明示した影響推論であり、Mainnet上で障害が発生済みという主張ではない。

## 監査後の未コミット実装と検証

- Laravel標準test入口を専用MySQL `livt_test`以外では起動前に停止する構成へ変更した。SQLite設定とSQLite作成処理を除去した。
- attemptへ`broadcast_certainty`を永続化し、`definitely_not_broadcast`以外の履歴をreplacement不可にした。既存nullも安全とは扱わない。
- delegated confirmではPayment、requester、chain+hash、sender、certainty、attempt stateが同一attemptへ結び付く場合だけ確定可能にした。direct/意図的third-party経路との互換性は維持した。
- Laravel、Wallet、Fee PayerのMainnet pilot金額を固定1 JPYCから設定上限内の正整数へ拡張した。LaravelはPayment snapshotを正とし、WalletとFee Payerは同じ`max_payment_jpyc`を検査する。LaravelとFee Payerの上限不一致は稼働不可とする。
- `mainnet:create-pilot-payment --amount=<整数>`で設定上限内のPaymentを作成できる。期限切れ未使用Paymentの置換は元金額を保持する。
- Paymentへ変更不能な`mainnet_authorized_at`を追加し、Mainnet Paymentの作成・置換時に永続認可証跡を記録する。metadata、preflight、live gate、sponsorshipはこの証跡がないPaymentを拒否する。既存Paymentは自動認可せずnullのまま維持する。
- LaravelからFee Payerへ渡すMainnet要求に、Payment ID、有効期限、Kaia SenderTxHashをHMAC-SHA256で結び付けた`paymentAuthorization`を追加した。Fee Payerはtransaction解析後、KMS署名前に検証する。欠落・改ざん・鍵不一致は`definitely_not_broadcast`で拒否し、鍵そのものではなくfingerprintだけをhealth/preflightで照合する。
- 通常の店舗checkoutでMainnet Paymentを作る際、専用DB、単一identity set、全live gate、self-hosted endpoint、HMAC鍵、設定金額上限が一致した場合だけ変更不能な`mainnet_authorized_at`を同じinsertへ含める。条件不一致ではPaymentを作らずfail closedとする。
- 実行経路から静的`pilot_payment_id`照合を外し、live gateは要求対象Paymentを直接検査する。Fee PayerはPayment IDとsender transaction fingerprintを別々にclaimし、同一Paymentまたは同一fingerprintを拒否する一方、設定上限内では独立Paymentを受け付ける。
- attempt/daily上限は1固定から1〜1000の設定値へ拡張し、per-user/store/sender <= global <= dailyの関係をLaravelとFee Payer policy parserで検証する。Laravel DBがrate/budgetの正本で、Fee Payerもprocess内global上限を適用する。
- Laravelの確定処理は、独立検証したreceipt evidenceでPaymentを確定する同一DB transaction内で、対応attemptを`submitted -> receipt_observed -> confirmed`へ進め、観測・解決時刻も保存する。Paymentだけconfirmed、attemptだけsubmittedという不整合を残さない。
- Mainnet外部通信を全てHTTP fakeに置き換えた連続2 Payment統合testを追加した。2375 JPYCと4999 JPYCについて、店舗作成、Wallet形式のsender署名済み`0x31`、Payment別HMAC、別attempt/fingerprint、別tx hash、receipt検証、Payment/attempt確定までを一続きで検査し、Fee Payer呼出しとsender recoveryが各2回だけであることも固定した。
- WalletのPlaywright browser E2Eにも2375 JPYCと4999 JPYCの連続2 Paymentを追加した。隔離MySQL、ローカルLaravel、ローカルKairos RPC stubを使い、同じbrowser Wallet/sessionで2件を順に操作して、表示金額、signed transfer金額、transaction hash、DB上のPayment確定が混線しないことを検査する。RPC stubは複数transactionとLaravelのtransaction/receipt/canonical block独立検証に対応した。これはブラウザ状態分離の非送信試験であり、Mainnet Fee Payer実送信の証拠ではない。
- browser E2E Seederを現行のimmutable Payment snapshotとconfirmation evidence制約へ追従させた。fixture reset時は全confirmation evidenceをnullへ戻し、Paymentごとに正しいsnapshotを再作成する。
- Walletへ暗号化backup/recoveryを追加した。設定画面でpasswordと解除中addressを再検証してAES-GCM暗号文だけをversion付きJSONへexportし、Wallet未作成状態だけでimportできる。importは16 KiB上限、strict schema、PBKDF2 iteration上限、AES-GCM認証、mnemonicからのaddress再導出を通過するまで保存せず、既存Walletを上書きしない。平文mnemonic/private keyは表示・export・backend送信しない。
- Laravelのuser payment履歴から`innerHTML`を除去し、API由来のamount、店舗名、status、日時、transaction hashを`textContent`だけで描画するようにした。Playwrightでは全項目へHTML攻撃文字列を返し、要素・event handlerが生成・実行されないことを実ブラウザで確認した。
- user/staff/payment loginへ失敗時のみ計数するnamed rate limiterを追加した。IP全体30回/分、同一識別子＋IP 5回/分に加え、user/paymentは同じ利用者枠を共有して20回/10分、staffは店舗＋staff ID単位で10回/10分に制限する。識別子は正規化後のSHA-256 fingerprintとしてcache keyへ入れ、生のemail、store code、staff IDを保存しない。成功ログインはfailure budgetを消費しない。
- Laravelで誤追跡されていた6 artifact（空の`@php`、`IlluminateFoundationComposerScripts::postAutoloadDump`、`stream`、分断コマンド片`staffs`、credential hashを含むTinker dump `tore->staffs;`、MySQL help出力`public/js/select`）を削除した。WalletとFee Payerには同種の追跡済みartifactはなかった。旧ソースや同梱ライブラリは別判断が必要なため削除していない。
- runtime参照のない旧決済画面`resources/views/pay_old`、placeholder contractを持つ試作`public/js/wallet.js`、どの画面からも読み込まれない同梱`public/js/ethers.umd.min.js`も削除した。3件ともInitial commit以降更新がなく、現行`/pay/{id}`は`PayController`から`pay` viewを使うため影響しない。
- 店舗試験前のread-only preflightを現行実装から再検証し、`STORE_TRIAL_PREFLIGHT.md`へ副作用分類、実行順、READY／STOP条件を記録した。pilot preflight、prepare dry-run、gate status、funding plan、gas observationはDB更新・署名・broadcastを行わない。一方、staging readinessは一時cache lockを取得し、Fee Payer `readiness:mainnet`は`dist`を再生成するため、厳密なfilesystem/state read-onlyではないことを区別した。
- WSL配置のenv系5ファイルが`644`または`755`だったため、内容を読まずpermissionだけ`600`へ閉じた。既存Fee Payer readiness成果物をMainnet staging environmentで実行したが、`Invalid Mainnet payment authorization key`で設定解析時にfail closedした。KMS health、RPC、残高照会には進まず、署名・broadcast・DB操作はない。
- operator承認後、Laravel／Fee Payerへ同一の新規256-bit Payment authorization HMAC keyを値非表示で設定し、fingerprint `sha256:da405a419e04f133`一致を確認した。既存AWS profileの一時指定でFee Payerは`READ_ONLY_READY`、Laravel gateは`SHUTDOWN_VERIFIED`、staging readinessは全check成功した。health-only processは確認後停止した。
- configured Payment 1は期限切れかつattempt 1件のため再利用不可で、pilot preflightは`NOT_READY`として停止した。現行policyはmax 1 JPYC、minimum required 0.0124 KAIAに対して残高0.01052043 KAIAで0.00187957 KAIA不足。追加KAIAは送っていない。unsigned gas observationも`NOT_READY`のため、原因をread-onlyで切り分けるまで新規Payment・上限変更・gate変更へ進まない。
- 両Mainnet RPCのread-only照会で、承認Senderのnative残高0 KAIA、JPYC残高0、両providerのtransfer gas推定call revertを確認した。現時点のgas observation失敗原因はSender JPYC残高不足である。修正後の実環境commandも`approved sender has less than 1 JPYC`、`Gas estimation was not attempted`で明示的にfail closedした。
- Laravel preflight／live gateへ、両providerの同一canonical blockでJPYC `balanceOf`を一致確認する独立checkを追加した。残高が対象Paymentのatomic amount未満なら明示診断でgas推定前に停止し、十分な場合のgas calldataも固定1 JPYCではなく対象Payment実額を使う。専用MySQL `livt_test`の対象Feature testは29 tests / 282 assertions pass。
- 1日10決済の候補policyを検討した結果、既存funding planが1件分fee＋reserveしか要求しない問題を修正した。開始前の推奨保有額は日次KAIA budget＋reserve、各transaction直前の最低残高は1件分最大fee＋reserveとして分離した。Laravel／Fee Payer双方がmaximum balance >= daily budget + reserveを要求する。現行capでは10件分0.024 KAIA＋reserve 0.01 KAIA = 0.034 KAIA、現残高との差は0.02347957 KAIAとなる。
- operator承認後、Laravel／Fee PayerのMainnet staging policyを1決済上限5,000 JPYC、直近86,400秒の各identity/global上限10 attempts、daily上限10、日次KAIA budget 0.024、maximum balance 0.034へ同期した。env permissionは600を維持した。更新後はFee Payer `READ_ONLY_READY`、KMS `SIGNER_READY`、Laravel staging `READ_ONLY_READY`、gate `SHUTDOWN_VERIFIED`、両policy一致を確認した。送金、Payment作成、署名、broadcastは行っていない。
- その後operatorがFee Payerへ0.023 KAIAを送金し、Mainnet RPCで残高0.03352043 KAIAを確認した。目標／maximum 0.034 KAIAまで0.00047957 KAIA不足している。追加送金は未実施で、health-only processは確認後停止した。
- operatorが残額0.00047957 KAIAを追加送金し、両Mainnet RPCでFee Payer残高34,000,000,000,000,000 wei = 0.034 KAIAの完全一致を確認した。Laravel funding planはrecommended top-up 0、gateは`SHUTDOWN_VERIFIED`。health-only processは確認後停止した。
- operatorが承認Senderへ50 JPYCを送金し、Wallet表示および両Mainnet RPCの同一canonical blockで50,000,000,000,000,000,000 atomicを確認した。Senderは0 KAIA。1 JPYCのunsigned observationは両providerともexecution gas 46,316、Fee Delegation overhead込み56,316、gas price 27.5 gweiで、cap 80,000／30 gwei以下。署名・broadcastは未実施。
- Laravel／Fee PayerのMainnet staging上限は5,000 JPYCへ同期済みである。店舗画面はserverからこの上限を取得し、正整数だけを受け付ける。送信・署名・新規Mainnet Payment作成は実施していない。
- 店舗スタッフログイン、商品ボタン／任意金額による決済作成、QR・期限・状態表示、active network別の店舗履歴を実装した。履歴は`store_id + network`で分離し、Kairosや別店舗のPaymentを混在させない。Store WalletのDB値は変更していない。
- 店舗UIはwallet/network不一致と実行gate停止を表示して作成を無効化し、曖昧な通信失敗で自動再作成しない。QRはbase64画像、API文字列は`textContent`で描画する。
- WSLローカルLaravelとbrowser内mock APIで、ログイン、Mainnet店舗画面、商品・履歴表示、コーヒー1 JPYC選択、QR表示までのbrowser smokeを行い、JavaScript page error 0件を確認した。Mainnet DBや実Payment APIは呼んでいない。
- pilot staffのPINだけを再発行し、既存staff API tokenを失効する`mainnet:rotate-pilot-staff-pin`を追加した。秘密値はconsoleへ出さず、既存ファイルを上書きしないmode-600ファイルへ配送する。専用MySQLのMainnet安全系32 tests / 305 assertionsがpassした。
- operator承認後にMainnet staging Store 1 / Staff 1のstaff PINだけを再発行した。PIN hash一致、staff token 0件、店舗コード／staff ID／wallet network不変を秘密値非表示で確認した。Store PIN、user password、Wallet address、Payment、transactionは変更していない。
- 店舗Payment／QR作成をchain execution gateから分離した。全gate閉鎖＋kill switch activeまたは全gate開放＋kill switch inactiveの整合構成だけを許可し、部分開放は拒否する。閉鎖中の作成ではimmutable pending Payment以外を作らず、attempt、署名、Fee Payer HTTP、broadcastは発生しない。関連46 tests / 405 assertionsと全351 tests / 1989 assertionsが専用MySQLでpassした。
- 店舗UIからの最初の1 JPYC作成は`mainnet_authorized_at`列未適用でINSERT前に失敗し、新規Paymentがないことを確認した。`APP_DEBUG=false`と汎用保存エラー応答を追加し、全352 tests / 1993 assertionsがpassした。
- operator承認後、Mainnet staging DBへ未適用のnullable列追加migration 2件を適用した。既存Payment 1／既存attemptは件数・状態とも不変で、新列値もNULLのままである。Payment 5/6の変更・削除は行っていない。
- 店舗UIで新規1 JPYC Payment 2を作成し、pilot対象をID 2へ更新した。Payment 2はpending、Mainnet認可あり、tx hashなし、attempt 0件である。
- Payment 1の完全な確定証跡とattemptのidentity/hash bindingを再検証し、再送・置換不可の閉じた履歴としてのみ認める互換判定を追加した。reconciliation anomalyは拒否する。専用MySQL `livt_test`で対象54 tests / 468 assertions、Laravel全355 tests / 2001 assertionsがpassした。
- Payment 2の実環境read-only preflightは全check READY。Fee Payer 0.034 KAIA、Sender 50 JPYC、required gas 56,316、gas price 27.5 gweiを確認した。broadcastはDISABLED、kill switchはACTIVEで、署名・broadcastは未実施である。
- operator承認後、`SAFE_DEPLOYED`、kill switch activeの`ARMED_NOT_LIVE`、gas再観測を順に確認してから両kill switchを解除した。Fee PayerとLaravelをlocalhost限定で再起動し、Payment 2／attempt 0件／policy READYの`LIVE_ENABLED`を確認した。有効化中の署名・broadcast・attempt作成はない。実送信には別の直前承認が必要である。
- Payment 2の当初QRは`APP_URL`に8000番ポートがなく別localhost serverで500となった。emergency markerと両kill switchを即時有効化して`ARMED_NOT_LIVE`へ戻し、`APP_URL=http://127.0.0.1:8000`へ修正した。Payment 2ページHTTP 200とWallet URLのID 2を確認後、停止中の両processを整合設定で再起動し、`LIVE_ENABLED`／attempt 0件へ復帰した。署名・broadcastはない。
- Walletリンクの`localhost:5173`も正しいSenderを保持する`127.0.0.1:5173`とは別originだったため、承認Sender照合で停止した。呼ばれたのはGET availabilityだけで実送信`/sponsor`ではなく、attempt 0件である。kill switch下でLaravelのWallet URLとWallet API URLを127.0.0.1へ統一し、正しいoriginでPayment内容検証とpilot user loginを確認後、`LIVE_ENABLED`／attempt 0件へ復帰した。
- Walletのread-only sponsorship availability照会だけを15秒から30秒へ延長し、実環境で約14.94秒かかる照会の誤timeoutを解消した。送信POSTのtimeoutや再試行policyは変更していない。Walletは25 unit files / 224 tests、typecheck、lintがpassした。
- operatorの送信直前の明示承認後、Payment 2の1 JPYCをMainnetへ1回だけ送信した。Paymentは`confirmed`、receipt status 1で、保存hashは`0x1f15fa55943ea80deb1fd233c84fd235b2fbb5f846ee1934e6374233dddf4509`と一致した。attemptは1件だけで`confirmed`、certainty `submitted`、Payment/attempt hash一致、receipt observed、resolvedを確認した。自動retry・手動再送はない。
- 送信後のread-only reconciliationは`verified=1 anomaly=0 transient_failure=0`。直後にemergency markerと両kill switchを有効化し、全Mainnet execution/signing/broadcast gateも無効化した。最終状態は`SHUTDOWN_VERIFIED`、kill switch `ACTIVE`、local/remote broadcast `DISABLED`、attempt count 1である。emergency markerは維持している。

検証結果:

- Laravel: 専用MySQL `livt_test`で355 tests / 2001 assertions pass（店舗UI/API、エラー非漏えい、確定済み履歴のfail-closed判定を含む）
- Wallet: typecheck・lint・production build pass、25 files / 224 tests pass、Wallet browser E2E 18 tests pass、隔離Payment browser E2E 5 tests pass
- Fee Payer: typecheck pass、82 tests pass
- 3リポジトリとも`git diff --check` pass
- 今回変更したLaravel 4ファイルの`pint --test`はpass。repository全体の`pint --test`には今回触れていない既存style違反が残る。
- 認証rate limitと関連回帰は専用の使い捨てMySQL `livt_test`で14 tests / 80 assertions pass。変更後の隔離Payment browser E2Eも5 tests pass。

## 次の1件

2026-09-28の終了地点として、Payment 2のMainnet実証、送信後停止、店舗／利用者履歴への確定済み決済表示まで確認済みである。Mainnet送信経路は`SHUTDOWN_VERIFIED`のまま維持する。

2026-10-04に3リポジトリの実装を上記commitへ記録した。最新の再検証はLaravel 355 tests / 2001 assertions（専用MySQL `livt_test`）、Wallet 25 files / 224 tests・typecheck・lint・production build、Fee Payer 82 tests・typecheckがpassした。Fee Payerのtestは既存`dist`内のemergency markerを守る隔離ビルドで実行した。

後日再開する最優先課題は「本番サーバーへのデプロイ設計とチェックリスト作成」とする。ここではまだデプロイ、DNS／TLS変更、production DB migration、secret配置、Mainnet gate再開を実施しない。次回は次の項目を読み取り専用の現状確認から設計する。

1. Laravel、Wallet、Fee Payerの配置方式、公開／内部境界、ドメイン、HTTPS、reverse proxy、常駐process管理
2. production MySQLの専用DB、backup／restore、migration手順とMainnet DBでtestを実行しない保証
3. AWS KMS IAM、API認証鍵、Payment authorization key、Wallet秘密情報を含むsecret管理とrotation
4. 全gate閉鎖＋kill switch activeでの初回deploy、health／ログ／監視／rollback確認
5. 店舗ログイン、Payment作成、QR、Wallet遷移、店舗／利用者履歴をbroadcastなしで検証する手順
6. 本番Mainnet送信を別承認境界にし、送信直前の1回限りの明示承認と送信後shutdownを行う手順

コード監査上の優先修正は完了した。店舗試験前には実設定変更前のread-only preflightが必要である。
