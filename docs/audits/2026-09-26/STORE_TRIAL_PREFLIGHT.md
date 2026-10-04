# 店舗試験前 read-only preflight

確認日: 2026-09-27

注: 冒頭の「現時点」は2026-09-27時点の記録である。後続のPayment 2実送信と停止状態は下記「Payment 2 Mainnet実証結果」を参照。2026-10-04時点で送信経路は`SHUTDOWN_VERIFIED`である。

## 現時点の結論

- WSLからMainnet RPC、AWS KMS public-key metadata、Mainnet DBのSELECT、Fee Payer health endpointまでread-only確認済みである。
- Mainnet transactionの署名、broadcast、新規Payment作成、過去attempt更新、JPYC／KAIAの送金は行っていない。
- 現行コードは正整数の可変金額を共通の決済上限内で扱える。Laravel／Fee PayerのMainnet staging上限は同期済みの5,000 JPYCであり、画面も実行時の上限をAPIから取得する。
- `Payment 5`、`Payment 6`や既存attemptは変更・削除しない。新しい店舗試験には、新規の期限内Paymentとattempt 0件が必要である。

## コマンドの副作用分類

| コマンド | Mainnetへの作用 | ローカル／内部状態への作用 | 判定 |
|---|---|---|---|
| `php artisan blockchain:mainnet-readiness --env=mainnet-staging` | primary/secondary RPCへのread-only JSON-RPC | DB/cache更新なし | read-only |
| `php artisan blockchain:mainnet-pilot-preflight --env=mainnet-staging` | RPC read、Fee Payer health GET | Mainnet DBをSELECTするだけ。cacheは設定確認のみ | read-only |
| `php artisan payments:prepare-mainnet-pilot <PAYMENT_ID> --env=mainnet-staging` | 上記preflightと同じ | Mainnet DBをSELECTするだけ | read-only |
| `php artisan mainnet:pilot-gate-status --env=mainnet-staging` | RPC read、Fee Payer health GET | Mainnet DBをSELECTするだけ | read-only |
| `php artisan mainnet:pilot-funding-plan --env=mainnet-staging` | 両RPCで残高とcanonical blockを照合 | DB更新なし | read-only。入金はしない |
| `php artisan mainnet:pilot-gas-observation --env=mainnet-staging` | Sender JPYC `balanceOf`、残高十分時だけ`eth_estimateGas`と`eth_gasPrice` | DB更新なし | unsigned observation。送信しない |
| `php artisan blockchain:mainnet-staging-readiness --env=mainnet-staging` | RPC read、Fee Payer health GET | 5秒のcache lockを取得・解放 | transactionは作らないが厳密には状態変更あり |
| Fee Payer `corepack pnpm readiness:mainnet` | RPC read、AWS KMS public-key metadata取得 | `dist`をclean/buildしactivation artifactをcopy | chain上はread-onlyだがfilesystemは変更する |

`MainnetPilotRpc`はpreflight系で`eth_chainId`、`eth_blockNumber`、`eth_getBlockByNumber`、
`eth_getBalance`、JPYC `balanceOf`用`eth_call`、`eth_estimateGas`、`eth_gasPrice`だけを許可し、broadcast methodを拒否する。
Fee Payer signer healthはAWS KMSのpublic key metadataを取得・address照合するだけで、`Sign`を呼ばない。

## 店舗試験前の順序

1. Fee Payerを全実行gate無効・kill switch activeのhealth-only構成にする。
2. Fee PayerのRPC、JPYC metadata、KMS public key/address、残高をread-only確認する。
3. Laravelの`mainnet:pilot-gate-status`でローカル／Fee Payer双方のgate閉鎖を確認する。
4. LaravelのMainnet staging DB識別子、migration、identity、RPC分離、Fee Payer policyを確認する。
5. 店舗試験用の金額・回数・KAIA budget変更案をレビューする。この時点では設定を変更しない。
6. 明示承認後だけ、新規Paymentを作成する。過去Paymentやattemptを再利用・改変しない。
7. 新規Payment IDを指定してpreflightとprepare dry-runを実行し、全項目READYを要求する。
8. その後もtransaction送信の直前に、別途その1回限りの明示承認を得る。

## READY条件

- `overall=PILOT_PREFLIGHT_READY`
- `infrastructure=READY`
- `signer=READY`
- `policy=READY`
- `broadcast=DISABLED`
- `kill_switch=ACTIVE`
- `READY_FOR_HUMAN_APPROVAL`
- Mainnet専用DB、primary/secondary RPC分離、AWS KMS signer、Laravel/Fee Payer policy一致
- 新規Paymentがpending、期限内、変更不能なMainnet認可あり、tx hashなし、attempt 0件
- sender、merchant、Fee Payer、Store、Staff、Userが承認値と完全一致
- Fee Payer残高がminimum required以上かつmaximum以下
- Sender JPYC残高が対象Paymentのatomic amount以上
- observed gasとgas priceが承認済みcap以下

## STOP条件

- gateが1つでも有効、kill switchがinactiveまたは不一致
- `broadcast_possible`、`submitted`、unknown／ambiguousな既存attemptが対象Paymentに存在
- Payment 5／6または過去attemptの変更・削除・再送が必要になる
- Mainnet DB識別子、RPC chain ID、JPYC contract／symbol／decimals、identity、policyの不一致
- public RPC、同一primary/secondary、KairosとのDB・RPC・identity共有
- KMS signer不良、Fee Payer残高範囲外、Sender JPYC残高不足、gas cap超過、Payment期限切れ
- preflight出力にsecret、RPC URL、raw transactionが現れる
- readinessのためにMainnet DBでtestを実行する、SQLiteへ切り替える、追加KAIAを送る必要が生じる

いずれかでSTOPした場合、gateを閉じたまま原因を調査し、自動retryや代替providerへのbroadcastを行わない。

## 次の1ステップ

コード側には、両RPCの同一canonical blockでSender JPYC `balanceOf`を照合する独立checkを追加した。対象Paymentの
残高が不足する場合は`sender_jpyc_balance_unreadable_or_insufficient`で停止し、gas推定を実行しない。残高が十分な場合も、
固定1 JPYCではなく対象Paymentのatomic amountでgasを推定する。

実環境Sender残高はoperatorによる資金準備後に50 JPYCとなり、両Mainnet RPCで一致確認済みである。下記の
10 attempts policyはLaravel／Fee Payerへ同期済みである。過去Paymentを再利用せず新規Paymentを作り、
全check READYを再確認してから送信直前の別承認を得る。

店舗試験policyは、1決済5,000 JPYC以下、同一user／store／sender／global／dailyを各10 attempts、
rate window 86,400秒である。現在のmax gas 80,000とgas price cap 30 gweiでは1件の最大費用が0.0024 KAIA、
10件分の日次budgetが0.024 KAIA、reserve 0.01 KAIAを含む推奨保有額は0.034 KAIAとなる。現残高
0.01052043 KAIAとの差は0.02347957 KAIAである。policy設定は反映済みだが、実資産の送金はしていない。
上限は「10 sponsorship attempts」であり、失敗attemptも保守的に枠とbudgetを消費するため、10件の確定成功を保証しない。
ambiguous／broadcast possibleを再送して成功件数を埋める運用はしない。

複数件運用に備え、funding plan／preflightの必要残高を「1件の最大fee＋reserve」から「日次budget＋reserve」へ修正した。
LaravelとFee Payerの双方がmaximum balanceをこの合計以上に限定し、Laravelのlive gateは各transaction直前には従来どおり
次の1件の最大fee＋reserveを要求する。専用MySQLのLaravel全341 tests / 1904 assertions、Fee Payer全82 testsがpassした。

設定同期後の実環境read-only確認では、Fee Payer `READ_ONLY_READY`、KMS `SIGNER_READY`、Laravel staging
`READ_ONLY_READY`、gate `SHUTDOWN_VERIFIED`、Laravel／Fee Payer policy一致を確認した。preflightは既存Payment 1、
既存attempt、Fee Payer資金不足、Sender JPYC不足のため意図どおり`NOT_READY`である。health-only processは確認後停止した。

operatorがFee Payerへ0.023 KAIAを送金した後、両RPCのcanonical blockで残高0.03352043 KAIAを確認した。
目標／maximum 0.034 KAIAまで0.00047957 KAIA不足しているため、funding planは追加額0.00047957 KAIAを示す。
operatorが残額0.00047957 KAIAを追加送金した後、両RPCで残高34,000,000,000,000,000 wei = 0.034 KAIAの
完全一致を確認した。Laravel funding planもrecommended top-up 0、gate statusは`SHUTDOWN_VERIFIED`である。
maximum緩和、新規Payment、gate変更、署名、決済broadcastは行っていない。health-only processは確認後停止した。

operatorが承認Senderへ50 JPYCを送金し、Wallet表示に加えて両Mainnet RPCの同一canonical blockで
50,000,000,000,000,000,000 atomic = 50 JPYCを確認した。Senderは0 KAIAのままである。1 JPYC transferの
unsigned observationは両providerともexecution gas 46,316、Fee Delegation overhead込み56,316、gas price
27.5 gweiで、設定cap 80,000 gas／30 gwei以下だった。Sender署名、Fee Payer署名、broadcastは行っていない。

## 店舗Web導線の実装

- `/login`へ店舗コード、スタッフID、PINの店舗スタッフログイン画面を実装した。認証失敗は資格情報を特定しない表示とし、既存の失敗回数制限を利用する。
- `/pos`へ店舗名、スタッフ名、active network、Store Walletを表示し、API上のwallet/network不一致または実行停止時は決済作成を無効にする。
- 商品ボタン（コーヒー1 JPYC、軽食3 JPYC、店舗商品5 JPYC）と、1 JPYC単位の任意金額入力を実装した。商品と表示上限はserver contextを正とし、POST側でもimmutable snapshot生成前に再検証する。
- 作成後はQR画像、金額、Payment ID、受取先、有効期限、状態を表示する。通信失敗時に作成や送信を自動再試行せず、confirmed／failed／expiredで状態監視を停止する。
- 履歴APIは認証staffの`store_id`とactive `network`を必須条件にする。Mainnet画面にKairosまたは別店舗のPaymentを表示しない。
- QR SVGや履歴値を`innerHTML`へ渡さず、QRはbase64画像、文字値は`textContent`で描画する。
- Store WalletのDB値は変更していない。店舗のMetaMask生成addressは受取先であり、店舗側で決済署名するものではない。
- 専用MySQL `livt_test`で店舗UI/API対象12 tests / 80 assertions、Laravel全351 tests / 1989 assertionsがpassした。Mainnet DB、新規Mainnet Payment、署名、broadcastは使用していない。
- WSLローカルLaravelとbrowser内mock APIによる表示確認で、ログイン画面、Mainnet店舗画面、商品3件、ネットワーク別履歴、コーヒー1 JPYC選択後のQR画面を確認した。JavaScript page errorは0件であり、実APIへのPayment作成要求は送っていない。
- Mainnet stagingのスタッフPIN紛失に対応し、対象pilot staffのPINだけを再発行して既存staff API tokenを失効する`mainnet:rotate-pilot-staff-pin`を追加した。秘密値はconsoleへ出さず、既存ファイルを上書きしないmode-600の指定ファイルへ一度だけ保存する。専用MySQLでMainnet安全系32 tests / 305 assertionsがpassした。
- operator承認後、Store 1 / Staff 1へ専用コマンドを実行した。秘密値を表示せず、ファイルmode 600、PIN hash一致、staff token 0件、店舗コード／スタッフID／wallet network不変を確認した。Store PIN、user password、Store Wallet address、Payment、transactionには変更を加えていない。
- 店舗でのPayment／QR作成とchain executionを分離した。Mainnet設定が「全実行gate無効＋kill switch active」または「全実行gate有効＋kill switch inactive」の完全一致時だけ店舗作成を許可し、部分的に開いたgate構成は拒否する。閉鎖状態のQR作成はDBへ期限付きimmutable Paymentを作るだけで、attempt、署名、Fee Payer呼出し、broadcastを行わない。関連46 tests / 405 assertionsとLaravel全351 tests / 1989 assertionsが専用MySQLでpassした。
- 最初の店舗UIによる1 JPYC Payment作成は、Mainnet staging DBへ`mainnet_authorized_at` migrationが未適用だったためINSERT前に失敗した。SELECTでPayment総数1、既存Payment 1 confirmed、attempt総数1の不変を確認した。詳細SQLが画面へ出たため`APP_DEBUG=false`へ修正し、保存例外は汎用503だけを返すようにした。専用MySQLの全352 tests / 1993 assertionsがpassした。
- operator承認後、未適用だった`broadcast_certainty`と`mainnet_authorized_at`のnullable列追加migration 2件をMainnet staging DBへ適用した。既存Payment 1はconfirmedかつ`mainnet_authorized_at=NULL`、既存attemptはsubmittedかつ`broadcast_certainty=NULL`のままで、行内容・件数を変更していない。
- 店舗UIから1 JPYCのPayment 2を新規作成した。Payment 2はpending、変更不能なMainnet認可あり、tx hashなし、attempt 0件である。設定のpilot Payment IDも2へ更新した。
- Payment 1はreceipt、block、payer、user、transaction hashの確定証跡とattemptのidentity/hash bindingが揃うことを要求し、再送・置換対象にはせず閉じた確定履歴としてだけ許可するよう履歴判定を修正した。旧attemptの`state=submitted`／`broadcast_certainty=NULL`は、Payment 1の完全な確定証跡および旧Paymentの`mainnet_authorized_at=NULL`と組み合わさる場合だけ互換扱いとする。
- 専用MySQL `livt_test`で履歴対象54 tests / 468 assertions、Laravel全355 tests / 2001 assertionsがpassした。reconciliation anomalyも履歴として拒否し、Mainnet stagingのPayment 1/2やattemptをテストで変更していない。
- Payment 2の実環境read-only preflightは`READY_FOR_HUMAN_APPROVAL`／`PILOT_PREFLIGHT_READY`となった。Payment、identity、policy、KMS signer、Fee Payer 0.034 KAIA、Sender 50 JPYC、required gas 56,316、gas price 27.5 gweiがすべてREADYで、broadcastはDISABLED、kill switchはACTIVEのままである。
- operator承認後、live-capable Fee Payerを全gate無効・kill switch activeで起動して`SAFE_DEPLOYED`を確認した。次に両サービスのexecution／signing／broadcast gateを一括反映し、kill switch activeの`ARMED_NOT_LIVE`、再観測56,316 gas／27.5 gweiを確認した。
- 最後に両kill switchを解除してFee PayerとLaravelをlocalhost限定で再起動し、Payment 2、attempt 0件、policy READYの`LIVE_ENABLED`を確認した。この有効化処理ではWallet署名、KMS Sign、broadcast、DB attempt作成は行っていない。実送信は別の直前承認を必須とする。
- Payment 2の当初QRは`APP_URL=http://localhost`により8000番ポートを欠き、別のlocalhost serverで500になった。即時にemergency markerと両kill switchを有効化して`ARMED_NOT_LIVE`／attempt 0件を確認し、WSL用`APP_URL=http://127.0.0.1:8000`へ修正した。正しいPayment 2ページのHTTP 200とWallet URLの`payment_id=2`だけを確認後、両process停止中に設定を揃えて再起動し、`LIVE_ENABLED`／attempt 0件へ復帰した。署名・broadcastはない。
- Payment画面のWalletリンクも`localhost:5173`では正しいSenderを保持する`127.0.0.1:5173`と別originになり、別Walletを参照して承認Sender照合で停止した。実送信`/sponsor`ではなくGET availabilityの`/sponsorship`だけで、attempt 0件を確認した。emergency kill switch下で`LIVT_WALLET_URL`とWallet API URLを127.0.0.1へ統一し、正しいSender originで内容検証とpilot user loginまで成功した。再び`ARMED_NOT_LIVE`を経て`LIVE_ENABLED`／attempt 0件へ復帰した。

## Payment 2 Mainnet実証結果

- Walletのread-only availability照会は実環境で約14.94秒かかり、従来の15秒timeoutでは送信前に誤って利用不可となり得た。このGETだけを30秒へ延長し、送信POSTのtimeout・再試行policyは変更していない。Walletは25 unit files / 224 tests、typecheck、lintがpassした。
- operatorが送信直前にPayment 2の1 JPYC Mainnet transactionを1回限りで明示承認し、Walletから送信した。自動retry、手動再送、追加送信は行っていない。
- Payment 2は`confirmed`、receipt status 1で、保存tx hashは[Kaiascanのtransaction](https://kaiascan.io/ja/tx/0x1f15fa55943ea80deb1fd233c84fd235b2fbb5f846ee1934e6374233dddf4509)と一致した。attemptは1件だけで`confirmed`、broadcast certaintyは`submitted`、Paymentとattemptのtx hashも一致し、receipt観測時刻と解決時刻が保存されている。
- `payments:reconcile 2`による保存済み確定証跡の独立再照合は`verified=1 anomaly=0 transient_failure=0`だった。このcommandは設計上read-onlyであり、DBの`reconciliation_status`は確定時の`pending`を保持する。
- 送信確認直後にemergency markerと両kill switchを有効化し、その後Laravel／Fee PayerのMainnet execution・signing・broadcast専用gateを全て無効化した。health-only Fee PayerとLaravelを整合設定で再起動した最終確認は`SHUTDOWN_VERIFIED`、kill switch `ACTIVE`、local/remote broadcast `DISABLED`、attempt count 1である。emergency markerは残している。

## WSL read-only実行結果

- operator承認後、同一の新規256-bit HMAC keyをLaravel／Fee Payerへ非表示で設定し、fingerprint
  `sha256:da405a419e04f133`の一致を確認した。
- Fee Payerは既存AWS profileをprocessへ一時指定した場合に`READ_ONLY_READY`。KMS signerは`SIGNER_READY`、
  chain ID 8217、primary/secondary block取得、全execution/signing/broadcast gate無効、kill switch activeを確認した。
- Fee Payer残高は0.01052043 KAIA、設定minimum reserveは0.01 KAIA、現在のfunding statusは`FUNDED`。
- health-only processを一時起動し、Laravel gate statusは`SHUTDOWN_VERIFIED`。確認後にprocessを停止した。
- staging readinessは全check成功し`READ_ONLY_READY`。Mainnet専用DB、19 migrations、cache lock、RPC分離、
  identity分離、Fee Payer policy一致を確認した。
- configured Payment 1はattempt 1件で、expiryも2026-09-17のため再利用不可。pilot preflightは意図どおり
  `NOT_READY`、`pilot_attempt_already_exists`で停止した。DB rowは変更していない。
- 現行policyはmax 1 JPYC、global/daily/各identity 1 attempt、minimum required balance 0.0124 KAIAである。
  現残高は新規1件用minimum requiredを0.00187957 KAIA下回る。追加KAIAは送っていない。
- unsigned gas observationは`NOT_READY`。identity、RPC、sender JPYC balanceのいずれかをread-onlyで切り分けるまで、
  gas limitを推測・引上げせず、新規Payment作成やgate変更へ進まない。
- 修正後のunsigned gas observationを実環境でread-only再実行し、`approved sender has less than 1 JPYC`で停止した。
  `Gas estimation was not attempted`も確認した。
- 両RPCを個別照会した結果、承認Senderのnative残高は0 KAIA、JPYC残高は0で、両providerともtransferのgas推定は
  call revertだった。現在の直接原因はSender JPYC残高不足であり、identity／RPC不一致ではなかった。
- この原因を曖昧なgas failureへ埋めないよう、Laravel preflightとlive gateに独立したSender JPYC残高checkを追加した。
  Sender残高不足時はgas推定前に停止する。専用MySQL `livt_test`とHTTP fakeによる対象Feature testは
  29 tests / 282 assertions pass。Mainnet DBやMainnet transactionは使用していない。
