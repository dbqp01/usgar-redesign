# Guide: QR Code Payments
# Updated: 2026-06-22 | Source: Mercado Pago MCP (search_documentation)
#
# TRAP TO AVOID FIRST:
#   Store and POS MUST exist before creating a QR order — not auto-created.
#   Always use Orders API — legacy /v1/qr is deprecated.

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

const TOKEN = process.env.MP_ACCESS_TOKEN;
const USER_ID = process.env.MP_USER_ID;       // your collector user ID
const POS_ID = process.env.MP_EXTERNAL_POS_ID; // external POS ID you chose at creation

// ─── GET / — dashboard ────────────────────────────────────────────────────────
app.get('/', (req, res) => {
  res.send(`
    <!DOCTYPE html><html><body>
    <h1>QR Code Test</h1>
    <form action="/create-qr-order" method="POST">
      <label>Amount: <input name="amount" type="number" value="10" step="0.01" /></label>
      <button type="submit">Create QR Order</button>
    </form>
    </body></html>
  `);
});

// ─── POST /create-qr-order ────────────────────────────────────────────────────
app.post('/create-qr-order', express.urlencoded({ extended: true }), async (req, res) => {
  const amount = parseFloat(req.body.amount || 10);
  const url = `https://api.mercadopago.com/instore/orders/qr/seller/collectors/${USER_ID}/pos/${POS_ID}/qrs`;

  const response = await fetch(url, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      external_reference: `qr-order-${Date.now()}`,
      title: 'QR Test Order',
      description: 'Test payment via QR',
      total_amount: amount,
      items: [{
        title: 'Test Item',
        unit_price: amount,
        quantity: 1,
        unit_measure: 'unit',
        total_amount: amount,
      }],
      notification_url: process.env.WEBHOOK_URL || undefined,
    }),
  });

  const data = await response.json();
  if (!response.ok) return res.status(400).json(data);

  // Show QR image
  res.send(`
    <!DOCTYPE html><html><body>
    <h1>QR Order Created</h1>
    <p>Scan with the Mercado Pago app (test user buyer)</p>
    <img src="${data.qr_data ? 'https://api.qrserver.com/v1/create-qr-code/?data=' + encodeURIComponent(data.qr_data) + '&size=300x300' : ''}" />
    <p>QR data: <code>${data.qr_data || JSON.stringify(data)}</code></p>
    <a href="/">← New order</a>
    </body></html>
  `);
});

app.listen(process.env.PORT || 3000, () =>
  console.log(`\n🚀 QR Server at http://localhost:${process.env.PORT || 3000}\n`)
);
```

### .env

```
MP_ACCESS_TOKEN=APP_USR-...
MP_USER_ID=               # your MP user ID (numeric)
MP_EXTERNAL_POS_ID=POS-001   # the external_id you used when creating the POS
WEBHOOK_URL=              # optional ngrok URL
PORT=3000
```

### package.json

```json
{ "type": "module", "scripts": { "start": "node server.js" } }
```

### Setup (one-time — requires MCP or API)

Create Store and POS before running the server:

```bash
# Create store
# ⚠️ state_name must be the FULL official state name (e.g. "São Paulo", "Bahia"),
#    not an abbreviation like "SP" — the API rejects invalid/placeholder values with 400.
curl -X POST https://api.mercadopago.com/users/$USER_ID/stores \
  -H "Authorization: Bearer $MP_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Store","external_id":"STORE-001","location":{"street_name":"Test St","street_number":"1","city_name":"São Paulo","state_name":"São Paulo","latitude":-23.5,"longitude":-46.6}}'

# Create POS (use store_id from above)
curl -X POST https://api.mercadopago.com/pos \
  -H "Authorization: Bearer $MP_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Terminal 1","store_id":"STORE_ID_FROM_ABOVE","external_id":"POS-001","category":621102}'
```

### Run

```bash
node server.js
# open: http://localhost:3000
# set amount → click "Create QR Order" → scan QR with test buyer MP app
```

---

## Pre-production checklist

- [ ] Store and POS created before first QR order
- [ ] Dynamic QR: new order per transaction (PUT replaces previous)
- [ ] Webhook on `orders` topic (not `merchant_order`)
- [ ] `external_reference` set on every order
- [ ] Run `/mp-review` before production
