# Payment Controllers and Transactions

---

## Controller Types Overview

| Controller | Type | Purpose | Key Method |
|---|---|---|---|
| `payment.php` | Both | Display payment page / initiate payment | `initContent()` |
| `validation.php` | Offline | Create order after customer confirms | `postProcess()` |
| `callback.php` | Online | Handle return from gateway | `initContent()` |
| `webhook.php` | Online | Handle async gateway notifications | `initContent()` |
| `notify.php` | Online | Alternative notification endpoint | `initContent()` |

All extend `ModuleFrontController`. Class naming: `{ModuleName}{ControllerName}ModuleFrontController`

---

## Offline Payment Controllers

### Payment Controller (`controllers/front/payment.php`)

Method: `initContent()`
1. Call `parent::initContent()`
2. Check currency via `$this->module->checkCurrency($cart)`
3. Calculate total with advance payment support
4. Validate service products: `ServiceProductCartDetail::validateServiceProductsInCart()`
5. Check hotel order restrictions (see [Security Patterns](#hotel-order-restriction-check))
6. Assign payment details + total to Smarty
7. `$this->setTemplate('payment_execution.tpl')`

### Validation Controller (`controllers/front/validation.php`)

Method: `postProcess()` — This is where the order gets created.

**Validation sequence:**
1. Cart validation: `$cart->id_customer != 0` and `$this->module->active`
2. Module authorization: loop `Module::getPaymentModules()`, check module name exists
3. Service product validation: `ServiceProductCartDetail::validateServiceProductsInCart()`
4. Hotel order restriction check
5. Customer validation: `Validate::isLoadedObject(new Customer($cart->id_customer))`
6. Calculate total (advance payment aware)
7. Prepare `$mailVars` array with payment-specific template variables
8. Call `$this->module->validateOrder()` with `PS_OS_AWAITING_PAYMENT`
9. Redirect to `order-confirmation` page

---

## Online Payment Controllers

### Payment Controller (Online)

For gateways like PayPal, the payment controller handles AJAX actions:

Method: `init()` — Validate cart, check module authorization, validate customer
Method: `initContent()` — Route actions via `Tools::isSubmit('action')` switch

**Actions pattern:**
- **Create order:** Read JSON from `php://input`, get order details from cart, call gateway API to create payment order, return JSON response
- **Capture order:** Get order ID from request, call gateway to capture, save transaction data, call `validateOrder()`, redirect to confirmation
- **Cancel:** Handle user cancellation, redirect back to checkout

**Order status determination:**
- Gateway status `COMPLETED`/`succeeded` + advance payment → `PS_OS_PARTIAL_PAYMENT_ACCEPTED`
- Gateway status `COMPLETED`/`succeeded` + full payment → `PS_OS_PAYMENT_ACCEPTED`
- Other status → `PS_OS_AWAITING_PAYMENT`

After `validateOrder()`, update transaction record with order reference.

### Process Payment Controller (Callback)

For gateways that redirect back after payment:

1. Get session/payment ID from query parameters
2. Initialize gateway SDK with secret key
3. Retrieve payment session from gateway
4. Check `$objCart->OrderExists() == false` before creating order (prevent duplicates)
5. Call helper to create order
6. Redirect to `order-confirmation`
7. On error: catch exceptions, log, redirect to `order-opc` with `payment_err=1`

---

## Webhook Controllers

Webhooks receive async notifications from gateway. Critical for order status updates.

### Webhook Controller Structure

1. Get headers: `getallheaders()` (normalize with `array_change_key_case($headers, CASE_UPPER)`)
2. Get payload: `Tools::file_get_contents('php://input')`
3. Extract signature verification data from headers
4. Verify signature (see [Security Patterns](#webhook-signature-verification))
5. Route by event type via `switch ($eventData['event_type'])`
6. Always return `HTTP 200` to acknowledge receipt: `header("HTTP/1.1 200 OK"); die;`

**Event handling pattern:** Create a webhook handler class with methods for each event type:
- `orderCompleted($eventData)` — Update order status
- `captureCompleted($eventData)` — Mark payment captured
- `captureDenied($eventData)` — Mark payment failed
- `captureRefunded($eventData)` — Process refund notification

### Notify Controller (Generic Gateway)

Similar to webhook but with different credential handling:
1. Determine mode (test/live) from request parameter
2. Get appropriate secret key and webhook secret for mode
3. Initialize gateway SDK
4. Verify signature using gateway SDK method
5. Handle `payment.completed` → check `OrderExists()`, create or update order
6. Handle `refund.updated` → update refund status
7. Return `http_response_code(200)`

### Updating Order Status from Webhook

```php
public static function updatePaymentStatus($id_order_state, $id_order)
{
    $order = new Order($id_order);
    $currentOrderState = $order->getCurrentOrderState();
    if ($currentOrderState->id != $id_order_state) {
        $useExistPayment = !$order->hasInvoice();
        $orderHistory = new OrderHistory();
        $orderHistory->id_order = (int)$id_order;
        $orderHistory->changeIdOrderState((int)$id_order_state, $order, $useExistPayment);
        $orderHistory->addWithemail(true, null);
    }
}
```

---

## validateOrder() Method

### Parameter List

This exact parameter order is critical — it cannot be inferred:

```php
$this->module->validateOrder(
    $id_cart,                    // (int) Cart ID
    $id_order_state,             // (int) Order state ID
    $amount_paid,                // (float) Amount paid
    $payment_method,             // (string) Payment method display name
    $message,                    // (string|null) Optional message
    $extra_vars,                 // (array) Extra mail vars + transaction_id
    $currency_special,           // (int|null) Currency ID override
    $dont_touch_amount,          // (bool) Skip amount rounding
    $secure_key                  // (string) Customer secure key
);
```

### Usage Patterns

**Offline payment:**
- `$id_order_state`: `Configuration::get('PS_OS_AWAITING_PAYMENT')`
- `$payment_method`: `$this->module->displayName`
- `$extra_vars`: `$mailVars` array with `'{key}' => value` for email template
- `$secure_key`: `$customer->secure_key`

**Online payment:**
- `$id_order_state`: `PS_OS_PAYMENT_ACCEPTED` or `PS_OS_PARTIAL_PAYMENT_ACCEPTED` (advance)
- `$extra_vars`: `array('transaction_id' => $chargeId)` — records in `order_payment` table

### After validateOrder()

- `$this->module->currentOrder` — Created order ID
- `$this->module->currentOrderReference` — Order reference string
- `new Order($this->module->currentOrder)` — Full Order object

**`actionValidateOrder` hook:** Fired automatically by `validateOrder()` after order creation. Params: `cart`, `order`, `customer`, `currency`, `orderStatus`. Register this hook if your module needs to perform custom logic immediately after order creation (e.g., logging, external system sync).

**Confirmation redirect:**
`index.php?controller=order-confirmation&id_cart={}&id_module={}&id_order={currentOrder}&key={secure_key}`

---

## Transaction Recording

### Transaction ObjectModel

Create `PaymentTransaction extends ObjectModel` with fields:
- `id_payment_intent` / `id_transaction` — Gateway IDs
- `id_customer`, `id_currency`, `id_cart`
- `order_reference` — Set after `validateOrder()` from `$module->currentOrderReference`
- `amount`, `status`
- Status constants: `TRANSACTION_STATUS_SUCCESS`, `TRANSACTION_STATUS_CANCELLED`, `TRANSACTION_STATUS_PROCESSING`

### Save Pattern

After successful `validateOrder()`:
1. Create new ObjectModel instance
2. Set all fields from gateway response and cart data
3. Call `$objTransaction->save()`

For PayPal-style gateways, save transaction BEFORE `validateOrder()`, then update `order_reference` after.

---

## Refund Management

### Refund ObjectModel

Create `PaymentRefund extends ObjectModel` with:
- `id_payment_transaction` — Link to transaction
- `refund_id` — Gateway's refund ID
- `refunded_amount` DECIMAL, `refund_type` INT (FULL=1, PARTIAL=2)
- `reason` TEXT, `status` INT, `date_add`

### Key Methods

- `getTransactionRefundedAmount($idTransaction)` — `SELECT SUM(refunded_amount)` to calculate remaining refundable amount
- `getRefundListByTransID($idTrans)` — Get refund history for display

### Refund Flow
1. Validate refund amount > 0 and ≤ remaining refundable amount
2. Call gateway API to process refund
3. Create RefundObjectModel record with gateway refund ID
4. Update order status if fully refunded

---

## Order States

**Common order state constants:**

| Constant | Usage |
|---|---|
| `PS_OS_PAYMENT_ACCEPTED` | Successful full payment |
| `PS_OS_AWAITING_PAYMENT` | Offline payment methods |
| `PS_OS_PARTIAL_PAYMENT_ACCEPTED` | Advance/partial payment |
| `PS_OS_ERROR` | Payment error |
| `PS_OS_CANCELED` | Cancelled payment |

**Order state logic:**
- Payment succeeded + `$cart->is_advance_payment` → `PS_OS_PARTIAL_PAYMENT_ACCEPTED`
- Payment succeeded + full payment → `PS_OS_PAYMENT_ACCEPTED`
- Payment cancelled → `PS_OS_CANCELED`
- Payment error / requires retry → `PS_OS_ERROR`

---

## Security Patterns

### Cart and Customer Validation

Every controller must validate (in order):
1. `$cart->id_customer != 0` and `$this->module->active`
2. Module in `Module::getPaymentModules()` list
3. `Validate::isLoadedObject(new Customer($cart->id_customer))`
4. For AJAX: `$this->module->secure_key == Tools::getValue('token')`

### Prevent Duplicate Orders

Always check before creating: `if ($objCart->OrderExists() == false)`. In webhooks, existing orders should be status-updated, not recreated.

### Webhook Signature Verification

**HMAC pattern:** `hash_hmac('sha256', $payload, $secret)` + `hash_equals()` to compare

**PayPal pattern:** POST payload + headers to `/v1/notifications/verify-webhook-signature` endpoint, check `verification_status == 'SUCCESS'`

**SDK pattern:** `\Gateway\Webhook::constructEvent($payload, $sig_header, $endpoint_secret)` in try-catch

### Hotel Order Restriction Check

```php
if (Module::isInstalled('hotelreservationsystem')
    && Module::isEnabled('hotelreservationsystem')
) {
    require_once _PS_MODULE_DIR_.'hotelreservationsystem/define.php';
    if (HotelOrderRestrictDate::validateOrderRestrictDateOnPayment($this)) {
        Tools::redirect('index.php?controller=order-opc');
    }
}
```

---

## Admin Controller Pattern

For transaction listing and refund management in back office.

### Tab Registration

In `install()`: create `Tab` object with `class_name`, multilang `name`, `id_parent = Tab::getIdFromClassName('AdminParentOrders')`, `module = $this->name`. Call `$tab->add()`.

In `uninstall()`: `Tab::getIdFromClassName()` → `$tab->delete()`.

### Transaction List Controller

Extends `ModuleAdminController`. Constructor sets:
- `$this->table`, `$this->identifier`, `$this->className`
- `$this->_select`, `$this->_join` for custom SQL with JOINs (e.g., customer name)
- `$this->fields_list` — Declarative column definitions with `title`, `type` (`price`, `datetime`), `callback`

Key methods:
- `initToolbar()` — Remove "new" button: `unset($this->toolbar_btn['new'])`
- `renderView()` — Load transaction details + refund history, render admin template
- `postProcess()` — Handle `submitRefund`: validate amount, call gateway API, save refund record

**Callback for price formatting:**
```php
public function setCurrency($value, $row)
{
    return Tools::displayPrice($value, (int) Configuration::get('PS_CURRENCY_DEFAULT'));
}
```

**Reference:** `modules/qlopaypalcommerce/controllers/admin/AdminPaypalCommerceTransactionController.php`

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
