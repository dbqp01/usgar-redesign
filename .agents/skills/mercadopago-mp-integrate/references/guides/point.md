# Guide: MP Point (Card Readers)
# Updated: 2026-06-22 | Source: Mercado Pago MCP (search_documentation)
#
# TRAP TO AVOID FIRST:
#   Device must be paired to correct USER ID — not just the application.
#   Use Orders API — Payment Intents API is deprecated.

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

const TOKEN = process.env.MP_ACCESS_TOKEN;

// ─── GET / — list devices + create order ─────────────────────────────────────
app.get('/', async (req, res) => {
  let devices = [];
  try {
    const r = await fetch('https://api.mercadopago.com/terminals/v1/list', {
      headers: { Authorization: `Bearer ${TOKEN}` },
    });
    const data = await r.json();
    devices = data.data || [];
  } catch (e) { /* ignore */ }

  res.send(`
    <!DOCTYPE html><html><body>
    <h1>MP Point Test</h1>
    <h2>Devices</h2>
    ${devices.length === 0
      ? '<p>No devices found. Pair your Point device to this account first.</p>'
      : devices.map(d => `<p>${d.id} — ${d.operating_mode}</p>`).join('')
    }
    <hr/>
    <h2>Create Payment Order</h2>
    <form action="/create-order" method="POST">
      <label>Device ID: <input name="device_id" value="${devices[0]?.id || ''}" required /></label><br/>
      <label>Amount (BRL): <input name="amount" type="number" value="10" step="0.01" /></label><br/>
      <button type="submit">Send to Device</button>
    </form>
    </body></html>
  `);
});

// ─── POST /create-order ───────────────────────────────────────────────────────
app.post('/create-order', async (req, res) => {
  const { device_id, amount } = req.body;

  const response = await fetch('https://api.mercadopago.com/v1/orders', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Content-Type': 'application/json',
      'X-Idempotency-Key': randomUUID(),
    },
    body: JSON.stringify({
      type: 'instore',
      processing_mode: 'automatic',
      total_amount: String(parseFloat(amount)),
      external_reference: `point-${Date.now()}`,
      transactions: {
        payments: [{
          amount: String(parseFloat(amount)),
          payment_method: { type: 'credit_card', installments: 1 },
        }],
      },
      config: {
        device: { id: device_id },
      },
    }),
  });

  const order = await response.json();
  if (!response.ok) return res.status(400).json(order);

  res.send(`
    <h1>✅ Order sent to device</h1>
    <p>Order ID: ${order.id}</p>
    <p>Status: ${order.status}</p>
    <p>Present card on the Point terminal.</p>
    <a href="/">← New order</a>
  `);
});

app.listen(process.env.PORT || 3000, () =>
  console.log(`\n🚀 Point Server at http://localhost:${process.env.PORT || 3000}\n`)
);
```

### .env

```
MP_ACCESS_TOKEN=APP_USR-...
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
# Pair your device in developer mode first
# Select device → set amount → click "Send to Device" → tap card on terminal
```

---

## Critical rules

- Device must be paired to correct `user_id` (not just app)
- Device must be in **developer mode** for testing
- Use `orders` webhook topic — `point_integration_wh` is legacy
- After firmware update: wait ~2 min before retrying

---

## Pre-production checklist

- [ ] Device paired to correct user_id
- [ ] Orders API used (not Payment Intents)
- [ ] Webhook on `orders` topic
- [ ] `X-Idempotency-Key` on every order
- [ ] Run `/mp-review` before production
