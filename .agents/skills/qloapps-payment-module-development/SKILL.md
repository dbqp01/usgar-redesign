---
name: qloapps-payment-module-development
description: Use whenever creating, modifying, or debugging payment modules in QloApps — offline methods and online payment gateways. Covers PaymentModule class, payment hooks, checkout integration, validateOrder flow, API credentials, webhook handling, transaction logging, and refunds.
license: OSL-3.0
metadata:
  author: QloApps
---

# QloApps Payment Module Development

Create payment gateway modules that extend `PaymentModule` using hooks-first architecture.

## Quick Commands

```bash
# Payment module structure
modules/qlopaymentname/
├── qlopaymentname.php           # Main PaymentModule class
├── controllers/front/
│   ├── payment.php              # Payment confirmation page
│   ├── validation.php           # Order processing (offline)
│   ├── callback.php             # Payment callback (online)
│   └── webhook.php              # Payment webhook (online)
├── classes/                     # Helper/Service classes
├── views/templates/
│   ├── front/payment.tpl        # Payment option display
│   └── hook/payment_return.tpl  # Success/failure message
└── LICENSE.md
```

Common operations:
- Create offline payment — See [payment-patterns.md](./reference/payment-patterns.md#offline-payments)
- Create online payment — See [payment-patterns.md](./reference/payment-patterns.md#online-payments)
- Setup API credentials — See [integration-api.md](./reference/integration-api.md)
- Process payment — See [controllers-transactions.md](./reference/controllers-transactions.md)
- Handle webhooks — See [integration-api.md](./reference/integration-api.md#webhook-management)

---

## When to Use This Skill

Applies to:
- Creating payment gateway integration
- Adding payment method to QloApps
- Integrating third-party payment processors
- Building custom payment solutions

## When NOT to Use

- Feature modules → `module-development` skill
- Statistics modules → `stats-module-development` skill

---

## Payment Module Types

| Type | Example | Controllers | API | Order State |
|------|---------|-------------|-----|-------------|
| Offline | Bankwire, Cheque | payment, validation | No | Awaiting Payment |
| Online | PayPal, Credit Card Gateways | payment, callback, webhook | Yes | Payment Accepted |

Payment type constants (from `OrderPayment`):
- `PAYMENT_TYPE_ONLINE = 1` — Real-time processing (credit cards, PayPal)
- `PAYMENT_TYPE_PAY_AT_HOTEL = 2` — Payment on arrival (QloApps specific)
- `PAYMENT_TYPE_REMOTE_PAYMENT = 3` — Offline (bank transfer, cheque)

The `PaymentModule` base class also provides:
- `$validateOrderAmount` — When `true` (default), `validateOrder()` checks that the paid amount matches the cart total

---

## Skill Components

Reference guides for each area of payment module development:

- [module-structure.md](./reference/module-structure.md) — Folder structure, mandatory files, install/uninstall, currency/country restrictions, payment hooks
- [payment-patterns.md](./reference/payment-patterns.md) — PaymentModule class, offline pattern, online pattern, advance payment, pattern selection
- [integration-api.md](./reference/integration-api.md) — Configuration forms, API credentials, sandbox/production, webhooks, authentication
- [controllers-transactions.md](./reference/controllers-transactions.md) — Payment/validation/callback/webhook controllers, validateOrder(), transactions, refunds, order states, security

---

## Quick Reference

### Offline Payment Checklist
- [ ] Create main module class (extends PaymentModule)
- [ ] Set `payment_type = REMOTE_PAYMENT`
- [ ] Create payment.php controller (show confirmation page)
- [ ] Create validation.php controller (create order)
- [ ] Register payment hooks
- [ ] Create payment.tpl template
- [ ] Add configuration for payment details
- [ ] Setup email template with instructions

### Online Payment Checklist
- [ ] Create main module class (extends PaymentModule)
- [ ] Set `payment_type = ONLINE`
- [ ] Create configuration form (API keys, test/live mode)
- [ ] Create payment.php controller
- [ ] Create callback.php controller (handle return)
- [ ] Create webhook.php controller (handle notifications)
- [ ] Create helper/service classes for API
- [ ] Implement API authentication
- [ ] Setup webhook URL
- [ ] Handle payment success/failure
- [ ] Implement refund capability
- [ ] Create transaction logging

### Security Checklist
- [ ] Never store full credit card data
- [ ] Use HTTPS for all payment pages
- [ ] Validate secure_key before order creation
- [ ] Verify webhook signatures
- [ ] Use Configuration::get() for API keys
- [ ] Sanitize all user inputs
- [ ] Log payment transactions
- [ ] Handle errors gracefully

---

## Common Pitfalls

1. **Storing credit card data** — Use tokenization; gateway handles card data, store only token.
2. **Missing webhook signature verification** — Always verify webhook signatures from gateway.
3. **No test/live mode separation** — Separate test and live API keys, mode switcher in config.
4. **Creating order before payment confirmation** — Offline: use "Awaiting Payment" state. Online: create order only after success callback/webhook.
5. **Missing advance payment check** — Always check `$cart->is_advance_payment` and use `Cart::ADVANCE_PAYMENT` for total calculation.

---

## Reference Modules

| Module | Type | Key Concepts |
|--------|------|-------------|
| `modules/bankwire/` | Offline | Simple config, email instructions, basic pattern |
| `modules/cheque/` | Offline | Minimal setup example |
| `modules/qlopaypalcommerce/` | Online | OAuth, webhooks, refunds, API integration |

Core files:
- `classes/PaymentModule.php` — Base payment module class
- `classes/order/Order.php` — Order creation
- `classes/order/OrderPayment.php` — Payment type constants

---

## Troubleshooting

1. **Payment option not showing** — Check: module active, `hookPayment` registered, currency enabled for module, configuration completed.
2. **Webhook not working** — Check: webhook URL registered with gateway, signature verification passing, controller accessible (no 404), module logs.
3. **Order creation fails** — Check: cart exists and not converted, `secure_key` matches, order state exists, all `validateOrder()` parameters provided.

---

## Development Workflow

1. **Plan** — Choose payment type (offline vs online), review gateway documentation, get test credentials
2. **Setup** — Create folder structure, main module file, mandatory files, install/uninstall, configuration form
3. **Payment Flow** — Create payment controller, validation/callback controller, implement `validateOrder()`, add templates
4. **Advanced (Online)** — Integrate gateway API, setup webhook handling, add transaction logging, implement refunds
5. **Test** — Test currencies, advance payment, success/failure flows, webhook notifications, refunds

---

## Additional Resources

- QloApps DevDocs: https://devdocs.qloapps.com/
