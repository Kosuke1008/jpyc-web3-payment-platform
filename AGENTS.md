# AGENTS.md

## Project

LivT is a non-custodial JPYC QR payment application built with Laravel.

## Secrets

- Do not open, read, print, copy, summarize, or modify `.env`.
- Do not include environment-variable values in responses, logs, tests, commits, or patches.
- Use `.env.example` only to inspect configuration variable names.
- Never expose RPC API keys, private keys, seed phrases, database passwords, access tokens, or wallet credentials.
- If a task requires an environment variable, refer to it only by variable name.
- Do not modify `.gitignore` in a way that exposes `.env`.

## Safety

- Do not commit or push unless explicitly instructed.
- Do not run destructive Git commands.
- Do not run `php artisan migrate:fresh` unless explicitly instructed.
- Never run destructive commands against production or staging databases.
- Do not modify unrelated files.
- Show the implementation plan before cross-cutting changes.
- After editing, summarize changed files and tests performed.

## Payment security

- A transaction hash alone is not proof of payment.
- Do not weaken Transfer-event verification.
- Verify chain ID, token contract, recipient, sender when applicable, amount, transaction status, and duplicate transaction use.
- LivT must remain non-custodial.
