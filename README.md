
# Liv Terminal

A Web3-powered Store Operations Platform

This is a condensed, production-style README template for the GitHub repository.

## Overview
Liv Terminal (LivT) is a store operations platform whose first implemented feature is ERC20/JPYC-compatible QR payments.
It is designed to manage the complete payment workflow rather than only blockchain transfers.

## Features
- Staff authentication (Laravel Sanctum)
- User authentication
- QR payment creation
- ERC20 payments with MetaMask
- Transaction Receipt verification
- Transfer event verification
- Payment history
- Mobile MetaMask support
- Responsive payment page

## Architecture
Store -> Laravel API -> MySQL
                |
                +-> Alchemy RPC -> Ethereum Sepolia

Customer -> Payment Page -> MetaMask -> ERC20 Transfer

## Security
- Sanctum authentication
- Server-side receipt verification
- Destination wallet verification
- Amount verification
- Duplicate txHash prevention
- Payment expiration

## Tech Stack
Laravel 11
PHP 8.3
MySQL
Blade
Vanilla JavaScript
ethers.js v6
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

## Database
users
stores
staffs
wallets
payments

## Roadmap
Phase 1: Web3 Payment MVP (completed)
Phase 2: POS improvements
Phase 3: Analytics & AI
Phase 4: Multi-chain & SaaS

## Development Principles
- Responsibility separation
- Backend verification
- Security-first
- MVP-first
- Next.js migration ready

## Author
Kousuke Nakasaki
National Institute of Technology, Kagoshima College

Portfolio:
https://kos-server.site/nakasaki_portfolio_site/
