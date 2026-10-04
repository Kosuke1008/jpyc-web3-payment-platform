# livt-wallet 追跡ファイル確認表

基準commit: `103f03f61ca7f35644564a3b13361f609b2fab82`

各行の bytes / lines / SHA-256 は、作業ツリーではなく基準commitの Git blob 全体を取得して算出した。これにより、省略・切断のない取得を確認した。『内容を確認』は、その全体取得に加えて、ファイル種別に応じた静的監査対象に含めたことを示す。生成ロック、同梱minifiedライブラリ、画像は意味内容の監査対象外だが、blobの完全性は同様に記録した。

| path | bytes | lines | SHA-256 | 分類 | 理由 |
|---|---:|---:|---|---|---|
| `.gitignore` | 324 | 23 | `c88765c2c46b1b873b62f213778e18192a106b400c1a7337f3232f5f35410df6` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `README.md` | 9839 | 246 | `dfc4a777b955a096379d377c909b5cb876f6e810363c6daf370d65056f759434` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/.env.example` | 518 | 10 | `9f624a9a9ff64e6e7eeae92e708728f82965caf512ee4e3d66c14e50f03dbb71` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/app.spec.ts` | 21429 | 562 | `bd0e2c4a6edd8c12e9da88872d3efe99d83bfe975ce97c201f2df205a5d784d9` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/payment/payment.spec.ts` | 6296 | 172 | `eaeed30e876787ca9ea73cf103d6233e08229e589a626c08911c5ee8f9e04b4d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/payment/support/environment.ts` | 6999 | 250 | `4842e16bf1e30eb2a5d9603e9e7b068147fb4f0d7ecff10d15f0f1896214f45e` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/payment/support/kairos-receipt.mjs` | 1298 | 40 | `58b015a91de59a42e89666cb9c612d91b5098ee89f64247c330b506cf1f8e994` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/payment/support/kairos-rpc-stub.mjs` | 9657 | 388 | `d49fd4b6ca7a1923e823a29c23bd4cec3853bf94ab8011432680f4f91aabefa9` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/payment/support/run-live-kairos-review.mjs` | 24468 | 759 | `4e8de948d902e5d1fed731f303798f7122a6cff360fe8a3227d58bf1d8c75498` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/e2e/payment/support/run-payment-e2e.mjs` | 24801 | 813 | `78bfbb50a03ce2564caf8f5a3aa7b23c5e521dc5f1a5a5016b023cd8a0b5e043` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/eslint.config.js` | 766 | 26 | `f81e59acfb7db621b6abbdc9dd0a3524de699249661dfa670503f21bdfc63f40` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/index.html` | 356 | 13 | `1c1b0accff476fd9614e2593d02033ca390a25f07559256f2dc949e39ea85f6d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/package.json` | 1605 | 47 | `996ec8cfdbd4e4cb2ef128863e46faad6448ec681fa5c3d969af26f85819a5b9` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/playwright.config.ts` | 560 | 24 | `624153fe5d503a573151086fef22fe00339b0d59967b50389fe5409ff9fad7e4` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/playwright.payment.config.ts` | 648 | 30 | `261487a2278aafde0d857231b7c58af067700229a108ad639f285e7a6abb746f` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/AddressCopyButton.tsx` | 1847 | 66 | `b0d7435b2194065c21dc723323e5604749f4400ff178a122667ad15bf8509ad2` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/App.tsx` | 12938 | 353 | `51761d235214a7aba5becf734bf912a57c5ac37bbd23358d1069320284bf9740` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/JpycTransferPanel.tsx` | 18965 | 518 | `93c43c04eb6fda91fefaba75c2e7ea547d5adc5e87e34f0626fac056127e46cc` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/LivtPaymentPanel.tsx` | 36791 | 1066 | `9ac21cb5210ddd06fc7ebe090d1beb6b2e30912ce7b5826c20262dfb1a37ed57` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/ReceivePanel.tsx` | 1789 | 52 | `00f54c3ade078a82299330aa91ebb40255a37654e82b63cf60aea6b67772b610` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/SettingsPanel.tsx` | 1267 | 45 | `fd1e85478478284cd1980e40101a959ba274d90954a296c9935874a96015bee6` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/WalletHome.tsx` | 4371 | 144 | `bec56787e45dcda4b8abb839c13feb74633c9dec7f2b4fa3eb7e23645b610ba7` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/addressCopy.ts` | 839 | 32 | `68f075351718e9b9f6c585b6d1738675e61c12b9eb20afbe9c36c1e932231b31` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/main.tsx` | 314 | 17 | `acb3a45de17b572e3b96caf24ac73bddd35f2bc6ee129f14665945f93372f727` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/styles.css` | 8766 | 532 | `eb35a7d0ef4ae7fb391734e90fc147d5b6c240b73550d5d02d6c2f5805ba4d60` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/app/transferState.ts` | 6526 | 237 | `fd8cd331ccd96a97c00919e48f4f40e470a04306d1659db675e19ba35521c7a2` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/activeNetwork.ts` | 1732 | 66 | `594b29f639d27d706510669a1f95eb9ad3ca74fcf431b84e1814faa9710752e4` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/address.ts` | 708 | 25 | `e8247950c1043c7f0abc2ca274f9e1b9f131dd15e8d36b33714031a89e590f44` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/kairos.ts` | 1313 | 49 | `69fcaef4ad97cc96c3edb496841933dc5fb21b1b775c328473e31f0202646617` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/kairosBalance.ts` | 1154 | 33 | `ee5ce40bbb67ce41eb1f65062ba400b48dd9fa36c761ca0ed7ce66a7e63a7aef` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/kairosChainVerification.ts` | 206 | 6 | `78317ac86d1568a9e44787ef3b9c389580c09c90d6a4f3504bfe3155b7c82156` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/kairosClient.ts` | 438 | 14 | `bc4bad1de98efcf3dcbc9c9fd5b1ad9967004227034907690fe00a33ba21b316` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/kairosRpcError.ts` | 1806 | 54 | `43b86832b9d0aa5ac16e95ca7a06a888a172cade769aeb8e23a0f02cd9e37fe2` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/kairosTransaction.ts` | 1751 | 41 | `e8c72047cb21690a8a36875d221709236b582f620e0b45e99e73346d2dac1103` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/networkChainVerification.ts` | 1074 | 36 | `42e03e09a740c4f8d34be615968c9b1b2778844b3c21b735ac15c67a74ad845e` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/networkClient.ts` | 620 | 20 | `9c078c15bbfc621e37bd96c7351c23573b928e819c74ace98e9d32f928befaea` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/blockchain/networkProfiles.ts` | 6003 | 218 | `40001ddfbdf960e5d533c8ddba564b36f8d0dd9dc204bfb31fb6118cc0032069` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/mainnetActivationRelease.d.ts` | 59 | 1 | `7181bc5f984af77d97b7e62db4cccb5b54011266b7709d67b4a1a0a150e0e075` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/payments/livtPaymentApi.ts` | 17358 | 616 | `9d05089420b2916455187ac8a016576299907131ea12dd93b4db7aa4f93c6661` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/payments/livtPaymentFlow.ts` | 4096 | 133 | `4f666c57cd5f68b4b731500dd0d007e8927e48c084dc16b20109d75aecc2c07d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/payments/livtPaymentIntent.ts` | 6283 | 219 | `5d8b35659131577dad966d8eeb6ca4c57fa34374efd651f6101fd362b6ff7791` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/payments/livtPaymentProgress.ts` | 2688 | 97 | `b6d25762f155f5e8bece1646ffc1b954410894a176e60bd4d0e9b67fe8f47802` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/payments/paymentRequest.ts` | 902 | 35 | `d8549535269e93e381e827cbae298f1b1f5fc32e191595da1de050c82996f32d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/erc20Abi.ts` | 348 | 11 | `96523df57ef7243d33a85d078577f30d3bb003eecd574bae6cbbe94a7e549c61` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/erc20Client.ts` | 1199 | 34 | `8f62dbe8db5bd5c953285581002fc38636284601723ac952a15a1ecf316b91a6` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/feeDelegatedJpycTransfer.ts` | 6620 | 222 | `f2f50aa04e0bac2875229f65999c4ce547e350395bccb1e2654fa28737ae5d42` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/jpycTransfer.ts` | 10858 | 350 | `d3580537964481df991b58aa64ad18eea04b4afe3574efc6ff865ee6ea199b7c` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/jpycTransferClient.ts` | 1761 | 56 | `d6d25d01be2e277998bf2a0f97e86501aa83b4893da0e907a29ecaf7f45d53ff` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/tokenBalance.ts` | 2271 | 66 | `7515d76c33ca97d2b558d3bd3be12a098410becf9a0126a26e5afc96b3f94ec1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/tokenFormatting.ts` | 386 | 9 | `804bc524a2ad051f3dc7e72b34216ff63f7a50e95cf77e9c4e7bc6c9f9eec498` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/tokenMetadata.ts` | 3509 | 104 | `7fffc365d231ad9b07130c625a97448154947357c457145c138f282fea5c1df1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/tokenRegistry.ts` | 2385 | 85 | `c3a5521630c25c0e464caecea2bfacd96fe5bf0d60c940df964bc66bd32cfa04` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/tokens/transferValidation.ts` | 4564 | 172 | `bd73d3e4942db424f74e45f1acf95fbb2b557ef41c56ebf604218baaac777999` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/wallet/encryptedWallet.ts` | 9029 | 273 | `1c7254d426f722593bb42a27760793e5d1b59d0c76670bc27b7574c215a7639b` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/wallet/signingAccount.ts` | 1849 | 46 | `588989c8f2154806f152f144b0fe6b7bc000c6a685b2c4e170695d0917cab497` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/src/wallet/wallet.ts` | 1084 | 46 | `4a37dba03f397e76599ba028ed1a1337922d06f41eacb7667ad5521e6373cbc0` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/integration/blockchain/kairosNetwork.integration.test.ts` | 1304 | 37 | `9176ee471329f4584ee0a2221d48aa5a54fdb15d3d3b0ca12a70946fb9c26bf7` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/integration/payments/livtPaymentFlow.integration.test.ts` | 6193 | 195 | `bc8572d08a4df984437596d7341b9dadc6dd2d67a33a6e68818355ff730b6465` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/integration/tokens/feeDelegatedJpycTransfer.integration.test.ts` | 8044 | 248 | `0754d556c7b29695647dacd80f798f503b3d01811f26b0192e335bbc0b27530f` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/integration/tokens/jpyc.integration.test.ts` | 3525 | 104 | `a2bb31cee01da585d2c39dc89cd1480a2ffb88686521e467c09eb4ee020ff1b9` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/integration/tokens/jpycTransfer.integration.test.ts` | 14835 | 452 | `d87e8fdb44e22278198fba9e7606dfbe689703fca67214357159a10179e720a4` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/integration/wallet/encryptedWallet.integration.test.ts` | 1618 | 49 | `0ba2082187c81b28d00d909297b2239e919f3ab238fa75716aa8d014ce807b98` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/app/AddressCopyButton.test.tsx` | 3217 | 101 | `e8fd94fb63413b33e2b3e1dd8c60286fcc400cf5207cc342e7ea35c082623cd3` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/app/App.test.tsx` | 1084 | 32 | `5fa7f182d8bfb6a8beb9399795e818445989e8d9945f634bf4de809160ed283d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/app/LivtPaymentPanel.mainnet.test.tsx` | 10973 | 259 | `954a5e91419d5906cfaba30a643d34329c237a552d39676674a0c2e2086bbada` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/app/LivtPaymentPanel.test.tsx` | 14771 | 430 | `804ded521e34718fde8a28822222ff2af9c25e4f559927bed658ab477b5d1df8` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/app/WalletViews.test.tsx` | 6076 | 190 | `6091ff119d65e936b8cc0e280b05842e4e1d9519d118e700f5cb481ea4c50343` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/app/transferState.test.ts` | 7102 | 250 | `2f46328d21b95d6ba5f753a5b9497350cd5ec00333e424f28484e686925ece3c` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/blockchain/activeNetwork.test.ts` | 579 | 18 | `6dbb1ba9f69756974bed26e0d76dfa030de3b31110a5fa92bfa8e78e29f893e0` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/blockchain/address.test.ts` | 557 | 17 | `d2b4a77a2ae30e788056a04fbc7898d9cd3d9542fc572c78590a3bea5164b46d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/blockchain/kairos.test.ts` | 1287 | 44 | `f21eae7934f2bb3b6e4fa06643ed2cd68cb156609b285bd9af89bfe6132584e6` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/blockchain/kairosBalance.test.ts` | 1570 | 48 | `19b9b4e10db578dde13fe00024263c4325ae809c62376cf9a9b3a58717320c09` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/blockchain/kairosChainVerification.test.ts` | 1376 | 43 | `de60bc1afbbbfef10da56e3723f8fe9dfbf165a17ad0c78e7423f3eba3540b08` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/blockchain/networkProfiles.test.ts` | 4651 | 144 | `4cbd509794e4ac5d61f44aaf25d022a937d58995e0aa1f1900c96dffc864aaca` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/kairosReceipt.test.ts` | 1887 | 60 | `41377d91c48f4014e0cf10dc29dfd9217de4ae952ed59f14b3cdd070df247f20` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/livtPaymentApi.test.ts` | 15987 | 477 | `debabe3ae5515da9f9e0fdc22af6c56e280347f24b7153f768cb4b695d37f86c` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/livtPaymentIntent.test.ts` | 3900 | 122 | `d8f9dd5ee665765f6d5b5437da8cc21ab7399fa702563ecd10ada16be54e0351` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/livtPaymentProgress.test.ts` | 2761 | 77 | `297bfd10038baa4f0297f76370cabad37b1749f1a108d57c7b26e0afa77fb302` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/mainnetNoDirectBroadcast.test.ts` | 1128 | 31 | `29721dba94c66a5a6cdb3b6d42f81a38d57570119f40f142bbaad1cc18ddf576` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/mainnetPilotIntent.test.ts` | 2752 | 67 | `d2a0d6aeeab9878fddd3239b8888eae4826b9dab92f68cc5e6cb15bf77c598ad` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/payments/paymentRequest.test.ts` | 1378 | 44 | `58897e02e71981b58db3c113037ac455cf0ec75a1c06119f4629d55c77f45e65` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/tokens/erc20Token.test.ts` | 4549 | 129 | `c6d1bda9cff17802c20e42e16746d47e088b18c0d414aba36d60a42556073a94` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/tokens/tokenRegistry.test.ts` | 1287 | 36 | `e474391f5ac38bb444cc6df62c92dbfd60ff3fc1d070ba55cbf5bad63b61b9d1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/tokens/transferValidation.test.ts` | 8744 | 292 | `4fa6d7749c38377ed412c216839a7741f5e98c66190b6cda315ed264ce7fcc3d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/wallet/encryptedWallet.test.ts` | 2584 | 73 | `6bad84207626c3e944e3fccd136ef13aa13dd15636ab14868ac6ce53b4c66b33` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/wallet/signingAccount.test.ts` | 2450 | 83 | `342d404f4cf4ab87fca81d9f6a674b9fa37073e5bbc978cd71c344d79f65a413` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tests/unit/wallet/wallet.test.ts` | 1111 | 35 | `608700d0d161dce257926724fee0f26bf309e2a27ff0b4f2f5b5937ddbd54a85` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/tsconfig.json` | 681 | 28 | `9d7e2b6ae1ecd7798c259167f49980c33a1795184ea3c0ab2bd544fbe308b880` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `apps/web/vite.config.ts` | 1005 | 31 | `37f0531bd3747494bb6ad87c57ae3d4e1075568fcf3efe39b4174b8d66a44e89` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `docs/README.md` | 4149 | 64 | `084285506930f4210964bcfe4b11862b092d6d36e0a981022a73bb5b713ec9e0` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `docs/live-kairos-review.md` | 5440 | 89 | `76ad81ff34d85e88c85d66435c58567150a372ce4b57d7552076d2d76e999fd1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `docs/mainnet-pilot-activation.example.json` | 45 | 3 | `fac2fd75c451df8fd69e8b0cddd6720c9b45b54eb341f3f725eb1fd8e18309c1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `docs/payment-browser-testing.md` | 6544 | 103 | `7421ded6d22c50f8416b8cfc7d951cf28dc18b608fbb3acf3dafd955bb27d256` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `package.json` | 1170 | 21 | `23e8bc3cf03bb1db843726771761f6039a81eab9de8edef3d2b4d7e7c0b6866a` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `packages/.gitkeep` | 1 | 1 | `01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `pnpm-lock.yaml` | 85616 | 2621 | `a4fa5bcac65239084ffba62620df5f872e34d91c61b75f412fb6913f353a0f4c` | 生成物・画像等のため内容監査対象外 | パッケージマネージャ生成ロック（完全性のみ確認） |
| `pnpm-workspace.yaml` | 37 | 4 | `f2ba901d1edd7ef26100668973fb3540e21445de5706ac3bf47a9a9c2dff6f42` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |

## 集計

- 追跡ファイル: 97
- 内容を確認: 96
- 生成物・画像等のため内容監査対象外: 1
- 未確認: 0
