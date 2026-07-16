# Guide: Marketplace (Split Payments)
# Updated: 2026-06-22 | Source: Mercado Pago MCP (search_documentation)
#
# TRAP TO AVOID FIRST:
#   Both collector_id AND application_fee are required.
#   Seller OAuth tokens expire in 6 months — store refresh_token.

---

## Complete working app (Node.js + Express)

### Install

```bash
npm install express dotenv
```

### server.js

```js
import 'dotenv/config';
import express from 'express';
import { randomUUID } from 'crypto';

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// In-memory seller store (use a database in production)
const sellers = {};

// ─── GET / — marketplace dashboard ───────────────────────────────────────────
app.get('/', (req, res) => {
  const sellerList = Object.entries(sellers)
    .map(([id, s]) => `<li>${s.email} (ID: ${id})</li>`).join('');

  res.send(`
    <!DOCTYPE html><html><body>
    <h1>Marketplace Test</h1>

    <h2>Step 1 — Connect a seller</h2>
    <a href="/oauth/connect">
      <button>Connect Seller Account</button>
    </a>

    <h2>Step 2 — Process a split payment</h2>
    ${Object.keys(sellers).length === 0
      ? '<p>Connect a seller first.</p>'
      : `<form action="/charge" method="POST">
          <select name="seller_id">${Object.entries(sellers)
            .map(([id, s]) => `<option value="${id}">${s.email}</option>`).join('')}
          </select><br/>
          <label>Card Token: <input name="card_token" required placeholder="from tokenization" /></label><br/>
          <label>Amount: <input name="amount" type="number" value="100" /></label><br/>
          <label>Your fee (BRL): <input name="fee" type="number" value="10" /></label><br/>
          <button type="submit">Process Payment</button>
        </form>`
    }

    <h2>Connected sellers</h2>
    <ul>${sellerList || '<li>None yet</li>'}</ul>
    </body></html>
  `);
});

// ─── Seller OAuth flow ────────────────────────────────────────────────────────
app.get('/oauth/connect', (req, res) => {
  const authUrl = `https://auth.mercadopago.com/authorization?` +
    `client_id=${process.env.MP_APP_ID}&response_type=code&platform_id=mp` +
    `&redirect_uri=${encodeURIComponent('http://localhost:' + (process.env.PORT || 3000) + '/oauth/callback')}`;
  res.redirect(authUrl);
});

app.get('/oauth/callback', async (req, res) => {
  const { code } = req.query;
  const tokenRes = await fetch('https://api.mercadopago.com/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: process.env.MP_APP_ID,
      client_secret: process.env.MP_CLIENT_SECRET,
      code,
      redirect_uri: `http://localhost:${process.env.PORT || 3000}/oauth/callback`,
    }),
  });
  const tokens = await tokenRes.json();
  if (tokens.error) return res.status(400).json(tokens);

  sellers[tokens.user_id] = {
    access_token: tokens.access_token,
    refresh_token: tokens.refresh_token, // ✅ store this — expires in 6 months
    email: `seller-${tokens.user_id}@test.com`,
  };
  res.redirect('/');
});

// ─── POST /charge — split payment ────────────────────────────────────────────
app.post('/charge', async (req, res) => {
  const { seller_id, card_token, amount, fee } = req.body;
  const seller = sellers[seller_id];
  if (!seller) return res.status(404).send('Seller not found');

  // ✅ Use seller's access_token — payments go to seller's account
  const response = await fetch('https://api.mercadopago.com/v1/payments', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${seller.access_token}`,
      'Content-Type': 'application/json',
      'X-Idempotency-Key': randomUUID(),
    },
    body: JSON.stringify({
      transaction_amount: parseFloat(amount),
      token: card_token,
      description: 'Marketplace test',
      installments: 1,
      payment_method_id: 'visa',
      application_fee: parseFloat(fee), // ✅ your commission — required
      payer: { email: 'buyer@test.com' },
      external_reference: `mkt-${Date.now()}`,
    }),
  });

  const payment = await response.json();
  if (!response.ok) return res.status(400).json(payment);

  res.send(`
    <h1>✅ Payment ${payment.status}</h1>
    <p>Payment ID: ${payment.id}</p>
    <p>Amount: BRL ${amount} · Your fee: BRL ${fee}</p>
    <a href="/">← Back</a>
  `);
});

app.listen(process.env.PORT || 3000, () =>
  console.log(`\n🚀 Marketplace Server at http://localhost:${process.env.PORT || 3000}\n`)
);
```

### .env

```
MP_ACCESS_TOKEN=APP_USR-...   # your marketplace access token
MP_APP_ID=                    # your app ID (numeric)
MP_CLIENT_SECRET=             # your app client secret
PORT=3000
```

### package.json

```json
{ "type": "module", "scripts": { "start": "node server.js" } }
```

### Run

```bash
node server.js
# open: http://localhost:3000
# 1. Click "Connect Seller Account" → authorize with test seller account
# 2. Process a split payment
```

---

## Critical rules

- Both `collector_id` and `application_fee` required (missing either = 100% to marketplace)
- Use seller's `access_token` in Authorization header
- Store `refresh_token` — renew before 6-month expiry
- `application_fee` cannot exceed MP country limits

---

## Pre-production checklist

- [ ] OAuth flow implemented and tested
- [ ] `refresh_token` stored per seller
- [ ] Token renewal scheduled
- [ ] `application_fee` set on every transaction
- [ ] Run `/mp-review` before production
