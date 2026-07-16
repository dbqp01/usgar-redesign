# Guide: Subscriptions (Recurring Payments)
# Updated: 2026-06-22 | Source: Mercado Pago MCP (search_documentation)
#
# TRAP TO AVOID FIRST:
#   A preapproval without preapproval_plan_id CANNOT be migrated to a plan later.
#   status: 'authorized' is REQUIRED on creation.

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

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

const TOKEN = process.env.MP_ACCESS_TOKEN;

// ─── GET / — subscription signup page ────────────────────────────────────────
app.get('/', (req, res) => {
  res.send(`
    <!DOCTYPE html><html><body>
    <h1>Subscriptions Test</h1>
    <h2>Available Plans</h2>
    <form action="/subscribe" method="POST">
      <p><strong>Monthly Plan — BRL 9.99/month</strong></p>
      <input type="hidden" name="plan_id" value="${process.env.MP_PLAN_ID || 'CREATE_PLAN_FIRST'}" />
      <label>Email: <input name="email" type="email" required placeholder="buyer@example.com" /></label><br/>
      <label>Card Token: <input name="card_token" required placeholder="token from tokenization" /></label><br/>
      <button type="submit">Subscribe</button>
    </form>
    <hr/>
    <form action="/create-plan" method="POST">
      <h3>Create a plan first</h3>
      <button type="submit">Create Monthly Plan (BRL 9.99)</button>
    </form>
    </body></html>
  `);
});

// ─── POST /create-plan ────────────────────────────────────────────────────────
app.post('/create-plan', async (req, res) => {
  const response = await fetch('https://api.mercadopago.com/preapproval_plan', {
    method: 'POST',
    headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      reason: 'Monthly Plan — Test',
      auto_recurring: {
        frequency: 1,
        frequency_type: 'months',
        transaction_amount: 9.99,
        currency_id: process.env.CURRENCY_ID || 'BRL',
      },
      back_url: `http://localhost:${process.env.PORT || 3000}/`,
    }),
  });
  const plan = await response.json();
  res.send(`
    <h1>Plan created!</h1>
    <p>Plan ID: <strong>${plan.id}</strong></p>
    <p>Add to .env: <code>MP_PLAN_ID=${plan.id}</code></p>
    <a href="/">← Back</a>
  `);
});

// ─── POST /subscribe ──────────────────────────────────────────────────────────
app.post('/subscribe', async (req, res) => {
  const { plan_id, email, card_token } = req.body;
  const response = await fetch('https://api.mercadopago.com/preapproval', {
    method: 'POST',
    headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      preapproval_plan_id: plan_id,
      payer_email: email,
      card_token_id: card_token,
      status: 'authorized', // ✅ REQUIRED — subscription starts active
      back_url: `http://localhost:${process.env.PORT || 3000}/`,
      auto_recurring: {
        frequency: 1,
        frequency_type: 'months',
        transaction_amount: 9.99,
        currency_id: process.env.CURRENCY_ID || 'BRL',
      },
    }),
  });
  const sub = await response.json();
  if (!response.ok) return res.status(400).json(sub);
  res.send(`
    <h1>✅ Subscribed!</h1>
    <p>Subscription ID: ${sub.id}</p>
    <p>Status: ${sub.status}</p>
    <a href="/">← Back</a>
  `);
});

app.listen(process.env.PORT || 3000, () =>
  console.log(`\n🚀 Subscriptions Server at http://localhost:${process.env.PORT || 3000}\n`)
);
```

### .env

```
MP_ACCESS_TOKEN=APP_USR-...
MP_PLAN_ID=              # fill after running /create-plan
CURRENCY_ID=BRL
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
# 1. Click "Create Monthly Plan" → copy plan ID to .env → restart server
# 2. Fill email + card token → Subscribe
```

---

## Critical rules

- `status: 'authorized'` REQUIRED — without it, subscription starts paused
- `back_url` must be HTTPS in production
- Monitor webhooks: `subscription_preapproval` + `subscription_authorized_payment`
- Cannot migrate without-plan → with-plan after creation

---

## Pre-production checklist

- [ ] `status: 'authorized'` on creation
- [ ] `back_url` is HTTPS in production
- [ ] Webhooks on both subscription topics
- [ ] Run `/mp-review` before production
