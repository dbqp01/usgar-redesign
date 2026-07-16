---
name: mercadopago-mp-test-setup
description: Create test users and add funds to them for Mercado Pago testing. Wraps create_test_user and add_money_test_user from the MCP. Clarifies that credentials come in APP_USR- (Orders API, Checkout Pro, Point, QR) and TEST- (Checkout API, Bricks, Payments API) formats — both are valid and actively issued.
license: Apache-2.0
copyright: "Copyright (c) 2026 Mercado Pago (MercadoLibre S.R.L.)"
metadata:
  version: "4.2.0"
  author: "Mercado Pago Developer Experience"
  category: "development"
  tags: "mercadopago, testing, test-user, sandbox, credentials"
---

# mp-test-setup

This skill is the only place test users get created. It exists because the testing model is a frequent source of confusion — there are two distinct types of test credentials with different prefixes.

---

## Pre-check — confirm readiness before creating test users

> You are at **step 4 of 7** in the integration journey:
> `1. Create app · 2. Get TEST creds · 3. Scaffold · 4. Create test user ← here · 5. E2E · 6. /mp-review · 7. Prod`

Before creating test users, confirm the basics via `AskUserQuestion` (up to 3 questions — skip any already in `.mp-integrate-progress.md`):

1. **Account** — Skip if already confirmed in `.mp-integrate-progress.md`. Otherwise: "Do you have a Mercado Pago developer account?" → `Yes` / `No`
2. **Credentials** — "Do you have your `APP_USR-` access token?" → `Yes` / `No`
3. **Credential type** — "Are you using TEST credentials — from the {test_tab}?" → `Yes, test credentials` / `No, production credentials` / `I don't know`

- **No account** → point to the dashboard for the country (Chile: `https://www.mercadopago.cl/developers` — no `.com`; others `https://www.mercadopago.com.{cc}/developers`).
- **No credentials** → once the MCP is authenticated (Step 0 → State A): (1) call `application_list`, (2) ask via `AskUserQuestion` *"Which app do you want to use?"* listing each app by name, (3) call `mcp__plugin_mercadopago_mcp__get_credentials` with the chosen `application_id`, (4) display credentials inline. **Never write to file or commit.** If MCP is not authenticated, complete Step 0 first.
- **Credential type — No, production credentials** OR **I don't know** → show this **BLOCKING WARNING** and do NOT create a test user:

  > ⛔ **WARNING — Production credentials detected**
  > A real card charge may occur if you test against production. Switch to the **{test_tab}** tab in the Developer Dashboard first.
  > 👉 `https://{DOMAIN}/developers/panel/app` → Credentials → **{test_tab}** tab

  Re-show question 3 until the developer confirms "Yes, test credentials". **This gate has no Skip option** — do not proceed to Step 2 (test user creation) otherwise.

> Unlike `mp-integrate`, this skill's MCP gate stays **hard**: creating test users requires authenticated MCP calls, so there is no scaffold-only path here.

---

## The current testing model — read first

- **Credential prefixes — two distinct types:**
  - **`APP_USR-`** → test user credentials from `create_test_user`, production credentials, Orders API, Checkout Pro, Point, QR
  - **`TEST-`** → static test credentials from the {test_tab} tab, Checkout API / Bricks / Payments API
  Both are valid. `get_credentials` returns the correct format automatically. Never tell a developer to change their prefix.
- **Test user credentials use the `APP_USR-` prefix** and run against the production API. A test user has its own balance (loaded via this skill) and behaves like a real account.
- For static test credentials without creating a test user: in the Developer Dashboard, go to your app → Credentials → click the **{test_tab}** tab.

---

## Step 0 — Verify MCP is actually authenticated

`ListMcpResourcesTool` is unreliable for this MCP (always returns "No resources found"). The bootstrap tools `authenticate` / `complete_authentication` are always present and prove nothing.

Check whether `mcp__plugin_mercadopago_mcp__application_list` is callable AND returns at least one application. If not, **call `mcp__plugin_mercadopago_mcp__authenticate` immediately** and show:

> To create test users I need access to your Mercado Pago account. Open this link to connect: **[Connect Mercado Pago]({url})**
When you see "Authentication Successful" in the browser, come back and say anything.

When the user returns, call `application_list` directly — do NOT call `complete_authentication` first. Never ask the user to paste the callback URL.

---

## Step 1 — Resolve `site_id` before asking

The MCP does not currently return a `site_id` (its `application_list` only returns `AppID`/`AppName`/`AppDescription`, and the OAuth access token that would let us call `/users/me` is not exposed to the plugin client). Resolve in this order:

1. **Use the country the agent already passed** as `country=` — the agent already resolved it (persisted state or wizard).
2. **Read `.mp-integrate-progress.md`** at the project root — if a previous run persisted a country, reuse it.
3. **Last resort**: ask via `AskUserQuestion` (picker, never a numbered text block). Persist the answer to `.mp-integrate-progress.md`.

Do **not** grep the repo for `currency_id`/`site_id` literals or locale strings — they're unreliable on a clean repo and waste tokens.

## Step 2 — Create a test user

Call `mcp__plugin_mercadopago_mcp__create_test_user` with:

| Param | Required | Values |
|-------|----------|--------|
| `site_id` | yes | `MLA` (Argentina), `MLB` (Brazil), `MLM` (Mexico), `MLC` (Chile), `MCO` (Colombia), `MPE` (Peru), `MLU` (Uruguay) |
| `description` | yes | Free text identifying the user (e.g., `"buyer for checkout-pro tests"`) |
| `profile` | yes | `seller` or `buyer` — pick the role you need to simulate |
| `amount` | optional | Initial balance in the country's currency |

The tool returns the user id, email, password, and `APP_USR-` credentials.

⚠️ **Critical distinction — two types of credentials:**
- **Test user email + password** → use to **log in at the checkout page** as the buyer/seller during testing. NOT for your `.env`.
- **Test user `APP_USR-` credentials** → the test user's own API credentials. Used only if you need to make API calls AS that test user (e.g., marketplace seller OAuth flows). NOT your app's `MP_ACCESS_TOKEN`.
- **Your app's `MP_ACCESS_TOKEN`** → comes from DevPanel → your app → {test_tab} tab. This is what goes in your `.env`. Created by `mp-integrate`, NOT by `create_test_user`.

> If the developer needs both sides of a transaction (typical for marketplace, subscriptions, P2P), create one `seller` and one `buyer`.

---

## Step 3 — Load funds (when needed)

Call `mcp__plugin_mercadopago_mcp__add_money_test_user` with:

| Param | Required |
|-------|----------|
| `test_user_id` | yes — the id returned by `create_test_user` |
| `amount` | yes — number in the user's currency |

Country-specific limits apply. If the call fails with a limit error, ask for a smaller amount and retry once.

---

## Step 4 — Test cards

For card testing, do **not** invent card numbers.

1. **First**, read `~/.claude/plugins/cache/claude-plugins-official/mercadopago/{version}/skills/mp-integrate/references/products.md` — it has curated, version-pinned test cards for **AR, BR, MX, CO, CL** (numbers, CVV, expiry, and the `APRO`/`OTHE`/`FUND`/… status-code table). For these five countries, no MCP call is needed.
2. **Only if the country is not listed there** (e.g. PE, UY), fall back to MCP `search_documentation` with `"test cards {country}"` (e.g., `"test cards peru"`).

---

## Step 5 — Hand the credentials to the developer

Output template:

```markdown
## Test user created

**Country**: {country}
**Profile**: {seller | buyer}
**User id**: {id}
**Initial balance**: {amount} {currency}

### 🔑 Login credentials (to simulate the buyer/seller at checkout)

Use these to **log in at the Mercado Pago checkout page** during a test purchase:

- **Email**: {email}
- **Password**: {password}

> These are NOT your MP_ACCESS_TOKEN. Do NOT put these in your `.env`.

---

### 📋 Your app's test credentials (for your `.env`)

These come from **DevPanel → your app → {test_tab} tab** — NOT from the test user.

```
MP_ACCESS_TOKEN=APP_USR-...   ← your app's test access token (from DevPanel)
MP_PUBLIC_KEY=APP_USR-...     ← your app's test public key (from DevPanel)
```

> Both types of credentials use the `APP_USR-` prefix — what matters is WHERE they come from:
> - Your `.env`: from DevPanel → your app → {test_tab} tab
> - Checkout login: the test user's email + password above

### Next steps
- Use the test user **email + password** to log in at the checkout when making a test purchase.
- Use your app's **MP_ACCESS_TOKEN** (from DevPanel) in your backend to create orders/payments.
- For card payments, use the test cards in `references/products.md`.
- Smoke test with `mp-webhooks` → trigger a real test payment to fire the webhook.
- Run `mp-review` after a successful test payment.
```

---

## Gotchas

- Test user credentials look identical to production credentials. If a `.env` file leaks, both buyer and seller balances are exposed.
- The test user's email/password are valid logins on `mercadopago.com.{tld}` — the developer can sign in to inspect movements.
- A test user belongs to the country specified by `site_id`; trying to use Argentine test credentials against the Brazilian site fails silently with a `not_found` payment.
- Adding money has per-country daily limits. If you hit them, create a fresh test user instead of bumping limits.

---

## What this skill does NOT do

- It does **not** issue real production credentials. Those come from the Developer Dashboard.
- It does **not** validate webhooks (use `mp-webhooks`) or scaffold integration code (use `mp-integrate`).
