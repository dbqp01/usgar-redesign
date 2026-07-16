# Payment Patterns

---

## PaymentModule Class

All payment modules extend `PaymentModule` from `classes/PaymentModule.php`.

**Key properties:**
- `$currentOrder` — Order ID set by `validateOrder()`
- `$currentOrderReference` — Order reference set by `validateOrder()`
- `$currencies = true` — Enable currency restrictions
- `$currencies_mode = 'checkbox'` — `'checkbox'` (multiple) or `'radio'` (single)
- `$payment_type` — Set from `OrderPayment` constants (see below)
- `$validateOrderAmount = true` — When true, `validateOrder()` checks paid amount matches cart total

**Payment type constants** (from `OrderPayment`):
- `PAYMENT_TYPE_ONLINE = 1` — Credit cards, PayPal, payment gateways
- `PAYMENT_TYPE_PAY_AT_HOTEL = 2` — Payment on arrival (QloApps specific)
- `PAYMENT_TYPE_REMOTE_PAYMENT = 3` — Bank transfer, cheque, cash

---

## Offline Payments

Payment happens outside the system. Order created in "Awaiting Payment" state.

**Flow:** Customer → Checkout → Payment Option → Confirm → Order Created (Awaiting Payment) → Email with Instructions → Customer Pays Offline → Admin Confirms

**Reference:** `modules/bankwire/bankwire.php`, `modules/cheque/cheque.php`

### Main Module Class Structure

Constructor must set:
- `$this->name`, `$this->tab = 'payments_gateways'`, `$this->version`, `$this->author`
- `$this->currencies = true`, `$this->currencies_mode = 'checkbox'`
- `$this->payment_type = OrderPayment::PAYMENT_TYPE_REMOTE_PAYMENT`
- Call `parent::__construct()` BEFORE `$this->displayName` and `$this->description`
- Load saved configuration via `Configuration::getMultiple()`
- Set `$this->warning` if configuration is missing

Install: `parent::install()` + register hooks (`payment`, `paymentReturn`, `displayPaymentEU`)
Uninstall: Delete configuration keys + `parent::uninstall()`

### Hooks

**hookPayment($params):**
- Check `$this->active`, return if false
- Assign template variables (`this_path`, `this_path_ssl`)
- Return `$this->display(__FILE__, 'payment.tpl')`

**hookPaymentReturn($params):**
- Check `$this->active`
- Get order from `$params['objOrder']`
- Check state against `PS_OS_PAYMENT_ACCEPTED` for success status
- Use `$objOrder->advance_paid_amount` if `$objOrder->is_advance_payment`, else `$objOrder->total_paid`
- Assign to Smarty, render `payment_return.tpl`

### getExtraMailContent()

Injects payment instructions into order confirmation emails. Called automatically by `OrderHistory::sendEmail()` via `method_exists()` — no hook registration needed.

```php
public function getExtraMailContent($id_order_state, $order)
{
    if (Configuration::get('PS_OS_AWAITING_PAYMENT') == $id_order_state) {
        $this->context->smarty->assign(array(/* payment details + lang + total_paid */));
        return array(
            '{extra_mail_content_html}' => $this->context->smarty->fetch(
                $this->local_path.'views/templates/mail/mail_template_html.tpl'
            ),
            '{extra_mail_content_txt}' => $this->context->smarty->fetch(
                $this->local_path.'views/templates/mail/mail_template_text.tpl'
            )
        );
    }
    return array();
}
```

Return empty array for non-matching states.

**Mail templates** go in `views/templates/mail/`. Create two files:
- `mail_template_html.tpl` — HTML version with payment instructions (bank details, cheque address, etc.). Use Smarty variables assigned in `getExtraMailContent()`.
- `mail_template_text.tpl` — Plain text version with the same content, no HTML tags.

### Payment Controller (`controllers/front/payment.php`)

Class: `{ModuleName}PaymentModuleFrontController extends ModuleFrontController`
Method: `initContent()`
- Call `parent::initContent()`
- Check currency via `$this->module->checkCurrency($cart)`
- Calculate total with advance payment support (see [Advance Payment](#advance-payment-support))
- Call `ServiceProductCartDetail::validateServiceProductsInCart()`
- Check order restrictions via `HotelOrderRestrictDate::validateOrderRestrictDateOnPayment($this)` (when hotelreservationsystem module is installed)
- Assign variables to Smarty, call `$this->setTemplate('payment_execution.tpl')`

### Validation Controller (`controllers/front/validation.php`)

Class: `{ModuleName}ValidationModuleFrontController extends ModuleFrontController`
Method: `postProcess()`

**Validation sequence (in order):**
1. Check `$cart->id_customer != 0` and `$this->module->active`
2. Verify module is in `Module::getPaymentModules()` list
3. Call `ServiceProductCartDetail::validateServiceProductsInCart()`
4. Check `HotelOrderRestrictDate::validateOrderRestrictDateOnPayment()` (when hotelreservationsystem installed)
5. Validate customer with `Validate::isLoadedObject()`
6. Calculate total with advance payment support

**Create order:**

```php
$this->module->validateOrder(
    $cart->id,
    Configuration::get('PS_OS_AWAITING_PAYMENT'),
    $total,
    $this->module->displayName,
    NULL,
    $mailVars,  // array of '{key}' => value for email template
    (int)$currency->id,
    false,
    $customer->secure_key
);
```

**Redirect to confirmation:**
`index.php?controller=order-confirmation&id_cart={}&id_module={}&id_order={$this->module->currentOrder}&key={secure_key}`

---

## Online Payments

Payment processed in real-time via gateway API. Order created after payment confirmation.

**Flow:** Customer → Checkout → Payment Option → Gateway Payment → Callback/Webhook → Order Created (Payment Accepted) → Confirmation

**Reference:** `modules/qlopaypalcommerce/`

### Main Module Class Structure

Same as offline, plus:
- `$this->payment_type = OrderPayment::PAYMENT_TYPE_ONLINE`
- Include gateway SDK: `include_once 'libs/init.php'`
- Register additional hook: `actionFrontControllerSetMedia` (for JS/CSS on checkout)
- Configuration form: see [integration-api.md](./integration-api.md#configuration-page-getcontent--helperform)

### Additional Hooks

**hookActionFrontControllerSetMedia():**
- Check `Tools::getValue('controller') == 'orderopc'`
- Add gateway SDK JS via `addJS()` with `array('server' => 'remote')`
- Add module JS/CSS via `addJS()` / `addCSS()`
- Pass variables to JS via `Media::addJsDef()` (publishable key, token, callback URLs)

**hookDisplayPayment():**
- Check gateway is configured
- Return `$this->display(__FILE__, 'payment.tpl')`

### Helper/Service Classes

Create in `classes/` folder:
- **PaymentGatewayService** — Static methods for `getSecretKey()`, `getPublishableKey()`, `initializeGateway()`, environment switching (sandbox/live based on `Configuration::get()`)
- **PaymentHelper** — Order creation, transaction recording
- **PaymentDb** — Table create/drop for install/uninstall

### Checklist

- Set `payment_type = PAYMENT_TYPE_ONLINE`
- Create configuration for test/live API keys
- Include gateway SDK in `libs/` folder
- Create service/helper classes for API
- Implement payment controller + callback controller + webhook controller
- Use `PS_OS_PAYMENT_ACCEPTED` (or `PS_OS_PARTIAL_PAYMENT_ACCEPTED` for advance payment)
- Create transaction logging
- Implement refund capability
- Register hooks: `payment`, `paymentReturn`, `actionFrontControllerSetMedia`

---

## Advance Payment Support

QloApps supports advance/partial payments for hotel bookings. Always check this in payment flows.

**Cart total calculation:**

```php
if ($cart->is_advance_payment) {
    $total = $cart->getOrderTotal(true, Cart::ADVANCE_PAYMENT);
} else {
    $total = $cart->getOrderTotal(true, Cart::BOTH);
}
```

**Order total (in hookPaymentReturn / display):**

```php
if ($objOrder->is_advance_payment) {
    $order_total = $objOrder->advance_paid_amount;
} else {
    $order_total = $objOrder->total_paid;
}
```

**Order state selection:**
- Advance payment + success → `PS_OS_PARTIAL_PAYMENT_ACCEPTED`
- Full payment + success → `PS_OS_PAYMENT_ACCEPTED`

---

## Choosing the Right Pattern

**Use Offline when:** Payment happens outside system, no API integration, admin manually confirms payment. Examples: Bankwire, Cheque, Cash on Delivery.

**Use Online when:** Real-time processing, gateway API available, immediate confirmation needed, refunds through API. Examples: PayPal, Credit Card gateways.

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
