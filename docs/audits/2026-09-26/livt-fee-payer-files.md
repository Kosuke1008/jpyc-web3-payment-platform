# livt-fee-payer 追跡ファイル確認表

基準commit: `71144ffb3694f284b85e56172a07fde8c0edc525`

各行の bytes / lines / SHA-256 は、作業ツリーではなく基準commitの Git blob 全体を取得して算出した。これにより、省略・切断のない取得を確認した。『内容を確認』は、その全体取得に加えて、ファイル種別に応じた静的監査対象に含めたことを示す。生成ロック、同梱minifiedライブラリ、画像は意味内容の監査対象外だが、blobの完全性は同様に記録した。

| path | bytes | lines | SHA-256 | 分類 | 理由 |
|---|---:|---:|---|---|---|
| `.env.example` | 1876 | 47 | `f0d91057998c6e1d521990bc53c4f886792b5445c70f2634fe8390e9909d482d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `.gitignore` | 279 | 20 | `feba3cc4501171c63b94ff3c3eddc4ad5dc703de395e784cb94e8fcfe114920c` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `AGENTS.md` | 751 | 19 | `649e491403a7769015f61c2564dc7a42ece3bf8456f018488afb4771b2cf6b11` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `README.md` | 12184 | 310 | `7aa56ba95210973f4336e9984287e790add5bf82fd9e570de761f36e64837fba` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `docs/mainnet-pilot-activation.example.json` | 45 | 3 | `fac2fd75c451df8fd69e8b0cddd6720c9b45b54eb341f3f725eb1fd8e18309c1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `package.json` | 1348 | 30 | `d5b6772ca7cea9922d3afb2fa1c93d0f74d6d5c311bf2d6c7bb97d7cf29f07e0` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `pnpm-lock.yaml` | 22389 | 687 | `3dabae268f3522018cc962501dd4f7f011a26c9ef977e6d221a390031f5cf89c` | 生成物・画像等のため内容監査対象外 | パッケージマネージャ生成ロック（完全性のみ確認） |
| `scripts/clean-dist.mjs` | 347 | 11 | `5a131d804c1e2548ddcaa3c7fb90279e68ad4a7d0eda815ea07bc7f5c37ea0d5` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/activation-release.ts` | 600 | 19 | `9e97b5d1e4393ab82aeed4eac36d7e6f216477a4b8a8120e02fd699f2e15e48e` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/broadcast-certainty.ts` | 438 | 16 | `b0e23f010ab91e16a84ea7c9bfb74183a8110fd53fd769f98026129a1dfc9fd1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/config.ts` | 9053 | 291 | `1277cfb93b52774f728156656010e69dac1701254ce949a1c3525c1321e9c0bc` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/http.ts` | 7989 | 269 | `ef5f86e1db7f330a078f769d9f2e7ea516caabc85c207ed4dd1d69282405b4a6` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/kaia-fee-payer-signature.ts` | 4318 | 156 | `668448346a05288876744103bfb47ea374a6acd40748b9a2ba3ea6717fbd6054` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/kill-switch.ts` | 299 | 9 | `db7f799764057b8bc834998f49a0f3bf583b00b245c2c5e16f28d0bba1f427d7` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/mainnet-live-pilot-server.ts` | 3457 | 87 | `c70827eefb3f7a2ddbc56f31aab38e5881df333bd15429e3e49e8a01b53c177c` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/mainnet-readiness.ts` | 1441 | 44 | `33e764a6f584a06d5b9332f9a0c98409ba448091538758140b0b8f7a5d3b53d5` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/mainnet-staging-server.ts` | 2266 | 63 | `78454ce86413a1ea4d18cb95632fb99e22a05f4e83d038a14343c95be3db0334` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/network-profiles.ts` | 5772 | 197 | `00f0268e833d91c27f6130b7fe359d1588791ea9ba5078036c83e0b83f0dd4a5` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/pilot-policy.ts` | 5155 | 117 | `b9a7653cab6c8bec260991f7ca7f4f7db94f75fa3cc9fd9e9b37953a2802f86b` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/policy.ts` | 6477 | 250 | `41a1b5e96735aaa934398df4f16a6fa4e39443041675b8ff0a650a9b6f153f94` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/readiness.ts` | 4927 | 177 | `07896cdbcad66c007f8aec83eab49724ab5e0cc469c71d877a8daab016fa2c92` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/server.ts` | 1510 | 52 | `176ff1ee0bb72bc95d18e90cdf17afe93360f102dc19fae66d47f86f7416ea60` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/setup-kairos.ts` | 1523 | 54 | `1af9ea60c68d742de3ee74b219678ad75dc19d7e3bada3b878a12fe29d73e416` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/signer.ts` | 12079 | 418 | `386834d1f7ed3dc76fe56c0082305d88d04ce5500ea195f88b519fb207d236d1` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/sponsor.ts` | 13318 | 442 | `a043bec434025569f23947cec05788f66dd388947570a5eec0765aabfad744b5` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `src/test-mainnet-signer.ts` | 2133 | 52 | `473d2783d1752508e030cd6a3a56ef191bde5814e58030fc49e900b6e6ad731d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/activation-release.test.ts` | 988 | 21 | `648775fcbc991b37640cb6ee5702eb1c05b5a8de5f1c2e05fe832d34dfdf2261` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/config.test.ts` | 4362 | 112 | `77b3f57ad980a608ca50dd6dc85058ee88e29445d0b262f7d782cca4bd639a57` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/fixtures.ts` | 1337 | 34 | `78f10a95f20989735098f55e4ceb07307ca9c15bfda11e5485ee412beda6f3ed` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/http.test.ts` | 10504 | 335 | `d917bdf3a4a5b18df5eaadff1f41a27de8355a978591741e4f83ceb9e73297cc` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/network-profiles.test.ts` | 4942 | 163 | `7096bffb004124e99e15182f56ff87fdeb16eabc830850d9c1fbb701e9783e8e` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/pilot-policy.test.ts` | 2115 | 45 | `9407375f3f4432d4761460d701fcad62295d5d074900b21e50479fd7050061fc` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/policy.test.ts` | 3748 | 121 | `65d90d448c4e4dba8abec9618cb4c63c5da68d728b5ba2e90211123b7342b088` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/readiness.test.ts` | 6417 | 191 | `adf01921e312d969e3592ae35a66fd63defa4f3949c22e0fd4cfc9809506c8cd` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/signer.test.ts` | 9991 | 275 | `adad3646d26a3bb751fd2acb9bdc45402945eed3c7c892fb76f0523e2fdc09f3` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tests/sponsor.test.ts` | 21875 | 673 | `6cd02f9dc3e8be6fa729fe223bca1b0524b4c932d283cae83416b478d1a56011` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tsconfig.build.json` | 182 | 9 | `c4d4cd6022a77924cf367cfcdbd26a51d34d40053a61acf228a418b6a8a5ab1c` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tsconfig.json` | 530 | 20 | `8e8e2bf68c24922060a0168c110fd2b3d4bb05b065bc65c5dd10e79d149ed200` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |
| `tsconfig.tools.json` | 154 | 8 | `2b138edd5454cf79ca1af1e7fa46d468abf4d78383a9f648ee8fccff5b3a9e6d` | 内容を確認 | Git blob全体を取得・照合し、静的監査対象として確認 |

## 集計

- 追跡ファイル: 39
- 内容を確認: 38
- 生成物・画像等のため内容監査対象外: 1
- 未確認: 0
