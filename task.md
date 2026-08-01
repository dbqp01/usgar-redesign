# Mercado Pago Custom Checkout Migration

- [x] Backend: Remove `createPreference` and `PreferenceClient` usage from `MercadoPagoAdapter.php` and `PaymentGatewayPortInterface.php`.
- [x] Backend: Refactor `CreateBookingAction.php` to avoid generating preferences and simply return `cart_id`, `access_token`, `gateway_price`, and `mp_public_key`.
- [x] Backend: Verify changes with `api-harness.php` confirming no `init_point` is returned and happy path returns correct data.
- [ ] Frontend: Initialize MP SDK on the client side using Custom Checkout and the properties returned by the backend.
