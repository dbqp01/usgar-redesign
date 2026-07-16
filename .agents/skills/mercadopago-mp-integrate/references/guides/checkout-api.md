# Guide: Checkout API (Checkout Transparente in Brazil)
# Updated: 2026-06-26 | Source: Mercado Pago MCP (search_documentation)
#
# TRAP TO AVOID FIRST:
#   Always use Orders API (POST /v1/orders) for card payments in ALL 7 countries.
#   There is NO country-conditional logic. Never use /v1/payments for checkout-api.
#   Never ask the developer — always use orders.

---

## What it is

Card payments on your page. Full UI control. Buyer never leaves. PCI-compliant via MP tokenization.

**API: Orders API (`POST /v1/orders`) — same for every country.** Available in AR, BR, MX, CL, CO, PE, UY. No fallback to Payments API for card payments.

---

## Complete working app (Vanilla JS — no build step)

### Install

```bash
npm install mercadopago express dotenv
```

### server.js

```js
import 'dotenv/config';
import express from 'express';
import { randomUUID } from 'crypto';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const app = express();
app.use(express.json());

// ─── GET / — serve index.html with the public key injected server-side ───────
// Vanilla browser JS has no build step, so process.env is NOT available in the
// browser. The server injects the public key into the HTML before sending it.
app.get('/', (req, res) => {
  const html = readFileSync(join(__dirname, 'public', 'index.html'), 'utf8')
    .replace('%MP_PUBLIC_KEY%', process.env.MP_PUBLIC_KEY);
  res.type('html').send(html);
});

// ─── POST /api/process-payment — Orders API, all countries ───────────────────
app.post('/api/process-payment', async (req, res) => {
  // SDK v2 getCardFormData() returns camelCase — map to snake_case here
  const { token, paymentMethodId, installments,
          transaction_amount, email, identification } = req.body;
  const payment_method_id = paymentMethodId;

  try {
    const response = await fetch('https://api.mercadopago.com/v1/orders', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${process.env.MP_ACCESS_TOKEN}`,
        'Content-Type': 'application/json',
        'X-Idempotency-Key': randomUUID(),
      },
      body: JSON.stringify({
        type: 'online',
        processing_mode: 'automatic',
        total_amount: Number(transaction_amount).toFixed(2), // must be '10.00' not '10'
        external_reference: `order-${Date.now()}`,
        payer: { email, identification },
        transactions: {
          payments: [{
            amount: Number(transaction_amount).toFixed(2), // must be '10.00' not '10'
            payment_method: {
              id: payment_method_id,
              type: 'credit_card',
              token,
              installments: Number(installments),
              // ⚠️ issuer_id is NOT allowed inside payment_method for Orders API
            },
          }],
        },
      }),
    });
    const order = await response.json();
    if (!response.ok) return res.status(response.status).json(order);
    res.json({ status: order.status, id: order.id });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: err.message });
  }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`\n🚀 Server at http://localhost:${PORT}\n`));
```

### public/index.html

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Checkout API Test</title>
  <script src="https://sdk.mercadopago.com/js/v2"></script>
</head>
<body>
  <h1>Checkout API — Test</h1>
  <p>Total: <strong>BRL 10.00</strong></p>

  <form id="form-checkout">
    <div id="form-checkout__cardNumber" style="border:1px solid #ccc;padding:4px;height:24px"></div>
    <div id="form-checkout__expirationDate" style="border:1px solid #ccc;padding:4px;height:24px"></div>
    <div id="form-checkout__securityCode" style="border:1px solid #ccc;padding:4px;height:24px"></div>
    <input type="text" id="form-checkout__cardholderName" placeholder="Name on card" />
    <select id="form-checkout__issuer"></select>
    <select id="form-checkout__installments"></select>
    <select id="form-checkout__identificationType"></select>
    <input type="text" id="form-checkout__identificationNumber" placeholder="Document number" />
    <input type="email" id="form-checkout__cardholderEmail" placeholder="Email" />
    <button type="submit">Pay</button>
  </form>

  <div id="result" style="margin-top:20px"></div>

  <script>
    // %MP_PUBLIC_KEY% is replaced by the server (see GET / in server.js).
    // No build step needed — the key is injected before the HTML is sent.
    const mp = new MercadoPago('%MP_PUBLIC_KEY%');
    const cardForm = mp.cardForm({
      amount: '10.00',
      iframe: true,
      form: {
        id: 'form-checkout',
        cardNumber: { id: 'form-checkout__cardNumber', placeholder: 'Card number' },
        expirationDate: { id: 'form-checkout__expirationDate', placeholder: 'MM/YY' },
        securityCode: { id: 'form-checkout__securityCode', placeholder: 'CVV' },
        cardholderName: { id: 'form-checkout__cardholderName' },
        issuer: { id: 'form-checkout__issuer' },
        installments: { id: 'form-checkout__installments' },
        identificationType: { id: 'form-checkout__identificationType' },
        identificationNumber: { id: 'form-checkout__identificationNumber' },
        cardholderEmail: { id: 'form-checkout__cardholderEmail' },
      },
      callbacks: {
        onSubmit: (event) => {
          event.preventDefault();
          // SDK v2 getCardFormData() returns camelCase fields
          const { token, paymentMethodId, installments,
                  identificationNumber, identificationType, cardholderEmail: email } =
            cardForm.getCardFormData();
          document.getElementById('result').innerHTML = '<p>Processing...</p>';
          fetch('/api/process-payment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token, paymentMethodId,
              installments, email, transaction_amount: 10.00,
              identification: { type: identificationType, number: identificationNumber } }),
          })
          .then(r => r.json())
          .then(data => {
            const icon = data.status === 'approved' || data.status === 'processed' ? '✅' : '❌';
            document.getElementById('result').innerHTML =
              `<h2>${icon} ${data.status || 'error'}</h2><p>ID: ${data.id || JSON.stringify(data)}</p>`;
          })
          .catch(err => {
            document.getElementById('result').innerHTML = `<h2>❌ Error</h2><p>${err.message}</p>`;
          });
        },
      },
    });
  </script>
</body>
</html>
```

### .env

```
MP_ACCESS_TOKEN=APP_USR-...
MP_PUBLIC_KEY=APP_USR-...
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
# test card (BR): 4235 6477 2802 5682 | CVV 123 | Exp 11/30 | Name APRO | CPF 12345678909
```

---

## Test cards by country

Run `/mp-test-cards {country}` for the full list. Quick reference for Brazil:
- Visa `4235 6477 2802 5682` · CVV `123` · Exp `11/30` · Name `APRO` · CPF `12345678909`

---

## ⛔ Blocker — Orders API requires a test user buyer

The Orders API (`/v1/orders`) does **not** accept arbitrary emails. The payer must be an actual Mercado Pago test user created via the MCP tool.

**Before testing, you MUST:**
1. Run `/mp-integrate test-setup` to create a buyer test user
2. Use the test user's email in the `payer.email` field
3. Log in at the checkout page with that test user's email + password

⚠️ **Never use your own account's email as `payer.email`** — MP returns error 4390 (`Payer email forbidden`) because you cannot pay yourself. Use the buyer test user's email.

Without a test user, the Orders API returns `422 unprocessable_content`. This is not a bug in your code.

---

## Pre-production checklist

- [ ] **Test user created** via `/mp-integrate test-setup` (blocker for Orders API)
- [ ] `payer.email` is the test buyer's email, never your own account's (avoids error 4390)
- [ ] `X-Idempotency-Key` on every creation request
- [ ] Orders API (`/v1/orders`) used for all countries — no Payments API branch
- [ ] `installments` included (required for AR even when = 1)
- [ ] `issuer_id` NOT inside `payment_method` for Orders API
- [ ] `total_amount` and `amount` use `.toFixed(2)` format
- [ ] Public key injected server-side via `%MP_PUBLIC_KEY%` (no `process.env` in browser)
- [ ] 3 UI states: loading, success, error
- [ ] Run `/mp-review` before production
