# Guide: Checkout Bricks
# Updated: 2026-06-22 | Source: Mercado Pago MCP (search_documentation)
#
# TRAP TO AVOID FIRST:
#   CardForm does NOT exist — use CardPayment.
#   StatusScreen needs payment_id (NOT order_id).
#   onSubmit MUST return a Promise.
#   Bricks uses Payments API (/v1/payments), NOT Orders API.
#   Server calls POST /v1/payments with the token from CardPayment onSubmit.

---

## What it is

Modular React components. PCI-compliant — you never handle card data.

**Components:** `CardPayment` · `Payment` · `Wallet` · `StatusScreen`

---

## ℹ️ Bricks uses Payments API — no test user required

Bricks tokenizes the card client-side and the server calls `POST /v1/payments`. Unlike checkout-api with Orders API, you can use any email for testing — no test user needed.

Simply use test card numbers from `/mp-test-cards {country}` and any valid email.

---

## Complete working app (React + Express)

### Project structure

```
my-app/
  backend/
    server.js
    .env
    package.json
  frontend/
    index.html
    src/
      main.jsx
      App.jsx
    .env
    package.json
    vite.config.js
```

### Backend — install & server.js

```bash
cd backend && npm init -y && npm install mercadopago express dotenv cors
# Add to package.json: "type": "module"
```

```js
// backend/server.js
import 'dotenv/config';
import express from 'express';
import cors from 'cors';
import { randomUUID } from 'crypto';

const app = express();
app.use(cors());
app.use(express.json());

app.post('/api/process-payment', async (req, res) => {
  // CardPayment brick sends camelCase from SDK v2
  const { token, issuerId, paymentMethodId, installments,
          transaction_amount, payer } = req.body;

  try {
    const response = await fetch('https://api.mercadopago.com/v1/payments', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${process.env.MP_ACCESS_TOKEN}`,
        'Content-Type': 'application/json',
        'X-Idempotency-Key': randomUUID(), // ✅ always send — prevents duplicate charges on retry
      },
      body: JSON.stringify({
        transaction_amount: Number(transaction_amount),
        token,
        description: 'Bricks payment',
        installments: Number(installments),
        payment_method_id: paymentMethodId, // camelCase from SDK v2
        payer: { email: payer.email },
        external_reference: `order-${Date.now()}`,
      }),
    });
    const payment = await response.json();
    if (!response.ok) return res.status(400).json(payment);
    // payment.id is the payment_id needed by StatusScreen brick
    res.json({ status: payment.status, paymentId: payment.id });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.listen(3001, () => console.log('Backend at http://localhost:3001'));
```

### Frontend — full Vite project

```bash
cd frontend && npm create vite@latest . -- --template react
npm install @mercadopago/sdk-react
```

**frontend/src/App.jsx:**

```jsx
import { useState } from 'react';
import { initMercadoPago, CardPayment, StatusScreen } from '@mercadopago/sdk-react';

initMercadoPago(import.meta.env.VITE_MP_PUBLIC_KEY);

const AMOUNT = 10.00;

export default function App() {
  const [status, setStatus] = useState('idle');
  const [paymentId, setPaymentId] = useState(null);
  const [orderId, setOrderId] = useState(null);

  const handleSubmit = async (formData) => {
    setStatus('loading');
    try {
      const res = await fetch('http://localhost:3001/api/process-payment', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...formData, transaction_amount: AMOUNT }),
      });
      const data = await res.json();
      setPaymentId(data.paymentId); // direct from POST /v1/payments response
      setStatus(data.status === 'approved' ? 'approved' : 'rejected');
      return data; // ✅ MUST return a Promise — void keeps brick in loading forever
    } catch (err) {
      setStatus('rejected');
    }
  };

  if (status === 'approved' && paymentId) {
    return (
      <div>
        <h1>✅ Payment approved!</h1>
        <p>Payment ID: {paymentId}</p>
        <StatusScreen initialization={{ paymentId }} /> {/* ✅ payment.id from POST /v1/payments */}
      </div>
    );
  }

  return (
    <div style={{ maxWidth: 500, margin: '40px auto', padding: '0 20px' }}>
      <h1>Bricks — CardPayment Test</h1>
      {/* ✅ Always show amount above brick */}
      <p style={{ fontSize: 18 }}>Total: <strong>BRL {AMOUNT.toFixed(2)}</strong></p>

      {(status === 'idle' || status === 'loading') && (
        <CardPayment
          initialization={{ amount: AMOUNT }}
          onSubmit={handleSubmit}
          onError={(e) => { console.error(e); setStatus('rejected'); }}
        />
      )}

      {status === 'loading' && <p>Processing your payment…</p>}

      {status === 'rejected' && (
        <div>
          <p>❌ Payment could not be processed. Check your card and try again.</p>
          <button onClick={() => setStatus('idle')}>Try again</button>
        </div>
      )}
    </div>
  );
}
```

**frontend/src/main.jsx:**

```jsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';

createRoot(document.getElementById('root')).render(
  <StrictMode><App /></StrictMode>
);
```

**frontend/index.html:**

```html
<!doctype html>
<html lang="en">
  <head><meta charset="UTF-8" /><title>Bricks Test</title></head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.jsx"></script>
  </body>
</html>
```

**frontend/vite.config.js:**

```js
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig({ plugins: [react()] });
```

### Environment files

**backend/.env:**
```
MP_ACCESS_TOKEN=APP_USR-...
PORT=3001
```

**frontend/.env:**
```
VITE_MP_PUBLIC_KEY=APP_USR-...
```

### Run

```bash
# Terminal 1
cd backend && node server.js

# Terminal 2
cd frontend && npm run dev
# open: http://localhost:5173
```

### Test cards (Brazil)
- Visa `4235 6477 2802 5682` · CVV `123` · Exp `11/30` · Name `APRO` · CPF `12345678909`
- Use test buyer email from `/mp-integrate test-setup`

---

## Critical gotchas

| Issue | Cause | Fix |
|---|---|---|
| 422 on payment | No test user | Run `/mp-integrate test-setup` first |
| Blank screen | Imported `CardForm` | Use `CardPayment` |
| Brick stuck loading | `onSubmit` returns void | Return a Promise |
| StatusScreen broken | Passing `orderId` | Pass `paymentId` from `transactions.payments[0].id` |
| `FIELDS_SETUP_FAILED` | Ad-blocker | Disable ad-blocker for testing |
| Wrong API | Using `/v1/orders` for Bricks | Use `/v1/payments` — Bricks is Payments API |

---

## Pre-production checklist

- [ ] `CardPayment` used (never `CardForm`) — no test user needed for Bricks
- [ ] `onSubmit` returns a Promise
- [ ] Amount shown above brick
- [ ] 3 states: loading, success, error
- [ ] `StatusScreen` receives `paymentId` (not `orderId`)
- [ ] Run `/mp-review` before production
