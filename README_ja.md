
# Liv Terminal

Web3対応 店舗運営プラットフォーム

## 概要
Liv Terminal（LivT）は「JPYC決済が最初の機能である店舗運営プラットフォーム」です。

単なるウォレット送金ではなく、

・決済
・売上管理
・スタッフ管理
・決済履歴
・分析
・AI

までを視野に設計しています。

## 主な機能
- スタッフ認証
- ユーザー認証
- QRコード決済
- ERC20送金
- MetaMask連携
- Transaction Receipt検証
- Transferイベント検証
- 決済履歴
- モバイルMetaMask対応

## システム構成
店舗
 ↓
Laravel API
 ↓
MySQL

Alchemy RPC
 ↓
Ethereum Sepolia

利用者
 ↓
MetaMask
 ↓
ERC20送金

## セキュリティ
- Sanctum認証
- サーバー側Receipt検証
- 送金先検証
- 金額検証
- txHash重複防止
- 決済期限

## 技術スタック
Laravel11
PHP8.3
MySQL
Blade
JavaScript
ethers.js
Ethereum Sepolia
Alchemy RPC
Apache
Ubuntu

## API
POST /api/user/register
POST /api/user/login
POST /api/staff/login
POST /api/payments/create
POST /api/payments/{id}/confirm
GET /api/user/payments

## DB
users
stores
staffs
wallets
payments

## 今後
Phase1 Web3決済
Phase2 POS
Phase3 AI分析
Phase4 マルチチェーン

## 設計方針
- 責務分離
- セキュリティ重視
- Laravelらしい構成
- Next.jsへ移行しやすい構成

## 作者
中崎 康介

ポートフォリオ
https://kos-server.site/nakasaki_portfolio_site/
