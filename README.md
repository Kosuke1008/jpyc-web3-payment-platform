# Liv Terminal

JPYCなどのERC20トークンを用いた、Web3対応QRコード決済プラットフォームです。

Laravel・PHP・MySQL・ethers.js・MetaMaskを使用し、実店舗でのステーブルコイン決済を想定して開発しています。

---

## 概要

Liv Terminalは、店舗スタッフが金額を入力してQRコードを生成し、利用者がウォレットからJPYCを送金することで決済を完了するWeb3 POSシステムです。

従来のQRコード決済のような使いやすさと、ブロックチェーン上の取引記録を利用した透明性のある決済を組み合わせることを目指しています。

現在はSepolia Testnet上のERC20トークンを用いて検証しています。

---

## 主な機能

- QRコード決済
- ERC20トークン決済
- MetaMask連携
- ユーザー認証
- スタッフ認証
- 店舗・スタッフ管理
- 決済履歴管理
- txHashを用いたトランザクション確認
- Transferイベントを用いた送金内容の確認
- MySQLによる決済データ管理

---

## 決済フロー

### 店舗側

```text
スタッフログイン
        │
        ▼
決済金額入力
        │
        ▼
Paymentレコード作成
(status = pending)
        │
        ▼
QRコード生成
        │
        ▼
利用者がQRコードを読み取り
```

### 利用者側

```text
ユーザーログイン
        │
        ▼
QRコード読み取り
        │
        ▼
決済画面表示
        │
        ▼
MetaMask接続
        │
        ▼
ERC20トークン送金
        │
        ▼
txHash取得
        │
        ▼
Laravel APIへtxHash送信
```

### サーバー側

```text
txHash受信
        │
        ▼
Alchemy RPC経由でSepoliaにアクセス
        │
        ▼
Transaction Receipt取得
        │
        ▼
Transferイベントを解析
        │
        ▼
送金先・送金額・成功状態を確認
        │
        ▼
DB上の決済情報と照合
        │
        ▼
一致すれば決済完了
(status = confirmed)
```

---

## 決済確認の仕組み

本プロジェクトでは、フロントエンドから送信された `txHash` をそのまま信用せず、Laravel側でブロックチェーン上の情報を確認します。

決済完了時には、以下の流れで確認を行います。

1. 利用者がMetaMaskでERC20トークンを送金する
2. フロントエンドが `txHash` を取得する
3. Laravel APIへ `txHash` を送信する
4. LaravelがAlchemy RPCを利用してSepolia Testnetへアクセスする
5. `txHash` をもとにTransaction Receiptを取得する
6. Receipt内のTransferイベントを解析する
7. 送金先アドレス・送金金額・トランザクション成功状態を確認する
8. DB上のPayment情報と一致すれば、決済ステータスを `confirmed` に更新する

---

## Transferイベントについて

ERC20トークンでは、送金が行われるとスマートコントラクトが `Transfer` イベントを発生させます。

Transferイベントには、主に以下の情報が含まれます。

```text
from   : 送信元アドレス
to     : 送信先アドレス
value  : 送金額
```

Liv Terminalでは、このTransferイベントを読み取り、DB上の決済情報と照合することで、実際に正しい送金が行われたかを確認しています。

---

## API

### User

```text
POST /api/user/register
POST /api/user/login
POST /api/user/logout
GET  /api/user/me
GET  /api/user/payments
```

### Payment

```text
POST /api/payments/create
GET  /api/payments/{id}
GET  /api/payments/status/{id}
POST /api/payments/{id}/confirm
```

### Staff

```text
POST /api/staff/login
```

---

## データベース設計

主なテーブルは以下の通りです。

```text
users
stores
staffs
wallets
payments
personal_access_tokens
```

### users

利用者情報を管理します。

```text
id
name
email
password
created_at
updated_at
```

### wallets

ユーザーのウォレット情報を管理します。

```text
id
user_id
network
address
created_at
updated_at
```

1人のユーザーが複数のネットワーク・複数のウォレットを持てるように、ウォレット情報はusersテーブルから分離しています。

将来的には以下のような複数ネットワーク対応を想定しています。

- Ethereum
- Sepolia
- Kaia
- Polygon
- Base

### stores

店舗情報を管理します。

```text
id
store_code
store_pin
name
created_at
updated_at
```

### staffs

店舗スタッフ情報を管理します。

```text
id
store_id
staff_id
name
pin
role
is_active
created_at
updated_at
```

### payments

決済情報を管理します。

```text
id
user_id
store_id
staff_id
amount
status
qr_payload
tx_hash
paid_at
expires_at
created_at
updated_at
```

決済作成時は以下の状態で保存します。

```text
status = pending
user_id = NULL
tx_hash = NULL
paid_at = NULL
```

送金確認後、以下のように更新します。

```text
status = confirmed
user_id = 支払ったユーザーID
tx_hash = 確認済みトランザクションハッシュ
paid_at = 決済完了時刻
```

---

## 技術スタック

### Backend

- Laravel
- PHP
- Laravel Sanctum

### Frontend

- Blade
- HTML
- CSS
- JavaScript
- ethers.js

### Database

- MySQL

### Blockchain

- Ethereum
- ERC20
- MetaMask
- Sepolia Testnet
- Alchemy RPC

### Infrastructure

- Ubuntu
- Apache
- Linux

---

## 開発状況

| 機能 | 状況 |
|---|---|
| ユーザー認証 | 完了 |
| スタッフ認証 | 完了 |
| 決済作成API | 完了 |
| QRコード生成 | 完了 |
| ERC20送金処理 | 完了 |
| txHash取得 | 完了 |
| Transaction Receipt取得 | 完了 |
| Transferイベント確認 | 完了 |
| 決済履歴API | 完了 |
| フロントエンドUI | 開発中 |
| 管理画面 | 開発中 |
| QRコード読み取り | 開発予定 |
| AI支援機能 | 開発予定 |

---

## 今後の開発予定

- フロントエンドUIの改善
- スマートフォン向けレスポンシブ対応
- QRコード読み取り機能
- ウォレット管理機能
- LINE Wallet対応
- Kaia Network対応
- 複数ERC20トークン対応
- マルチチェーン対応
- 売上分析機能
- AIによる決済・売上支援
- ECサイト向け決済API

---

## このプロジェクトで意識していること

本プロジェクトでは、単にブロックチェーン送金を行うだけでなく、実店舗で利用することを想定して、以下の点を意識しています。

- 店舗スタッフが扱いやすい決済フロー
- 利用者がQRコードから簡単に支払えるUI
- フロントエンドの情報をそのまま信用しない決済確認
- ブロックチェーン上の取引情報とDB情報の照合
- 将来的な複数ネットワーク対応
- 店舗管理・スタッフ管理を含めたPOS的な構成

---

## 注意事項

このプロジェクトは現在、学習・ポートフォリオ目的で開発中です。

現時点ではSepolia Testnet上で動作確認を行っており、実決済・本番運用には対応していません。

本番環境で利用する場合は、以下のような追加対応が必要です。

- 秘密鍵・APIキー管理
- 本番用RPC設定
- コントラクトアドレスの厳密な管理
- 二重決済防止
- 決済期限切れ処理
- 十分なブロック承認数の確認
- エラーハンドリング
- セキュリティ監査
- 法務・会計面の確認

---

## 作者

### 中崎 康介（Kousuke Nakasaki）

鹿児島工業高等専門学校 情報工学科

興味分野：

- Web開発
- FinTech
- Web3
- Blockchain
- AI
- プロダクト設計
- ソフトウェアアーキテクチャ

---

## Portfolio

https://kos-server.site/nakasaki_portfolio_site/

---

## License

本プロジェクトはポートフォリオ・学習目的で公開しています。
