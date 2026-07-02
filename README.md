# JPYC Web3 Payment Platform

Laravel・PHP・MySQL・ERC20を用いて開発している、Web3対応QRコード決済プラットフォームです。

JPYCなどのステーブルコインを利用し、実店舗で安全かつシンプルに決済できる仕組みの実現を目指しています。

---

# プロジェクト概要

本プロジェクトは、従来のQRコード決済（PayPayなど）のような使いやすさと、ブロックチェーンによる透明性を組み合わせた決済システムです。

現在はバックエンドを中心に開発を進めており、

- REST API設計
- データベース設計
- ERC20トークン決済
- トランザクション検証

などを実装しています。

---

# 主な機能

- QRコード決済
- JPYC（ERC20）決済
- MetaMask連携
- REST API
- 決済トランザクション検証
- 決済履歴管理
- ユーザー認証
- 店舗・スタッフ管理
- MySQLによるデータ管理

---

# 使用技術

## バックエンド

- Laravel
- PHP
- MySQL
- Laravel Sanctum

## フロントエンド

- Blade
- JavaScript
- HTML
- CSS

## ブロックチェーン

- Ethereum
- ERC20
- ethers.js
- MetaMask
- Sepolia Testnet

## インフラ

- Ubuntu
- Apache
- Linux

---

# システム構成

```text
利用者
    │
    ▼
MetaMask
    │
    ▼
Laravel API
    │
    ▼
MySQL
    │
    ▼
Ethereum（Sepolia）
```

---

# API

## ユーザー

```
POST /api/user/register
POST /api/user/login
POST /api/user/logout
GET  /api/user/me
GET  /api/user/payments
```

## 決済

```
POST /api/payments/create
GET  /api/payments/{id}
GET  /api/payments/status/{id}
POST /api/payments/{id}/confirm
```

## スタッフ

```
POST /api/staff/login
```

---

# データベース

主なテーブル

- users
- stores
- staffs
- wallets
- payments
- personal_access_tokens

---

# 開発状況

| 機能 | 状況 |
|------|------|
| バックエンドAPI | ✅ 完了 |
| データベース設計 | ✅ 完了 |
| ユーザー認証 | ✅ 完了 |
| ERC20決済検証 | ✅ 完了 |
| 決済履歴API | ✅ 完了 |
| フロントエンド | 🚧 開発中 |
| 管理画面 | 🚧 開発中 |
| AI支援機能 | 📅 開発予定 |

---

# 今後の開発予定

- レスポンシブUI対応
- ユーザーウォレット管理
- QRコード読み取り機能
- AIによる決済支援
- 複数ERC20トークン対応
- 売上分析機能
- モバイル最適化

---

# このプロジェクトについて

私は「Web3技術を実際の店舗決済へ活用できないか」というテーマから、このプロジェクトを開発しています。

単なる学習用アプリではなく、実際の店舗で利用できることを意識し、

- システム設計
- API設計
- データベース設計
- ブロックチェーン連携

まで一貫して開発しています。

将来的には、AIやデータ分析を組み合わせた次世代のFinTechサービスへ発展させることを目標としています。

---

# 作者

## 中崎 康介（Kousuke Nakasaki）

鹿児島工業高等専門学校 情報工学科

**興味分野**

- Web開発
- FinTech
- Web3
- AI
- プロダクト設計
- ソフトウェアアーキテクチャ

---

# ポートフォリオ

https://kos-server.site/nakasaki_portfolio_site/

---

# ライセンス

本プロジェクトはポートフォリオ・学習目的で公開しています。
