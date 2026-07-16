# Payment Gateway API Integration

---

## Configuration Page (`getContent()` / HelperForm)

The `getContent()` method renders the module's configuration page in back office (Modules > Configure).

**Flow:** Check warnings → Handle form submission → Validate → Process → Render form

### getContent() Pattern

```php
private $_html = '';
private $_postErrors = array();

public function getContent()
{
    // Warnings for missing config
    if (!Configuration::get('PAYMENT_NAME_API_KEY')) {
        $this->context->controller->warnings[] = $this->l('API credentials must be configured.');
    }
    if (!count(Currency::checkPaymentCurrencies($this->id))) {
        $this->context->controller->warnings[] = $this->l('No currency has been set for this module.');
    }

    if (Tools::isSubmit('btnSubmit')) {
        $this->_postValidation();
        if (!count($this->_postErrors)) {
            $this->_postProcess();
        } else {
            foreach ($this->_postErrors as $err) {
                $this->_html .= $this->displayError($err);
            }
        }
    }

    $this->_html .= $this->renderForm();
    return $this->_html;
}
```

**_postValidation():** Check `Tools::getValue()` for each required field, push errors to `$this->_postErrors`
**_postProcess():** Save values via `Configuration::updateValue()`, show `$this->displayConfirmation()`

### HelperForm Setup

This is the exact pattern needed — the property names and setup order matter:

```php
public function renderForm()
{
    $fields_form = array(
        'form' => array(
            'legend' => array('title' => $this->l('Configuration'), 'icon' => 'icon-cog'),
            'input' => array(
                array('type' => 'switch', 'label' => $this->l('Live mode'),
                      'name' => 'PAYMENT_NAME_LIVE_MODE', 'is_bool' => true,
                      'values' => array(
                          array('id' => 'active_on', 'value' => 1),
                          array('id' => 'active_off', 'value' => 0))),
                array('type' => 'text', 'label' => $this->l('API Key'),
                      'name' => 'PAYMENT_NAME_API_KEY', 'required' => true),
                array('type' => 'text', 'label' => $this->l('Secret Key'),
                      'name' => 'PAYMENT_NAME_SECRET_KEY', 'required' => true),
            ),
            'submit' => array('title' => $this->l('Save')),
        ),
    );

    $helper = new HelperForm();
    $helper->show_toolbar = false;
    $helper->table = $this->table;
    $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    $helper->default_form_language = $lang->id;
    $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?: 0;
    $helper->identifier = $this->identifier;
    $helper->submit_action = 'btnSubmit';
    $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
        .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
    $helper->token = Tools::getAdminTokenLite('AdminModules');
    $helper->tpl_vars = array(
        'fields_value' => $this->getConfigFieldsValues(),
        'languages' => $this->context->controller->getLanguages(),
        'id_language' => $this->context->language->id,
    );

    return $helper->generateForm(array($fields_form));
}

public function getConfigFieldsValues()
{
    return array(
        'PAYMENT_NAME_LIVE_MODE' => Configuration::get('PAYMENT_NAME_LIVE_MODE'),
        'PAYMENT_NAME_API_KEY' => Configuration::get('PAYMENT_NAME_API_KEY'),
        'PAYMENT_NAME_SECRET_KEY' => Configuration::get('PAYMENT_NAME_SECRET_KEY'),
    );
}
```

**HelperForm field types:** `text`, `textarea`, `select`, `switch`, `radio`, `checkbox`, `password`, `file`, `color`, `date`, `html`

**Select field pattern:** Use `'options' => array('query' => array(array('id' => 'val', 'name' => 'Label')), 'id' => 'id', 'name' => 'name')`

---

## Environment Setup

### Sandbox / Production Switching

Create a helper/service class with static methods for environment-aware credential retrieval:

- `getSecretKey()` — Return test or live secret key based on `Configuration::get('MODULE_LIVE_MODE')`
- `getPublishableKey()` — Same pattern for publishable/public key
- `initializeGateway($secretKey)` — Initialize gateway SDK with the key
- `getBaseUrl()` — Return sandbox or production API URL based on mode

**Environment constants pattern:**
```php
const SANDBOX_URL = 'https://api.sandbox.gateway.com';
const LIVE_URL = 'https://api.gateway.com';
const ATTRIBUTION_ID = '{gatewayAttributionId}';
```

Store credentials in `Configuration` table. Use separate keys for test/live (e.g., `MODULE_TEST_SECRET_KEY`, `MODULE_LIVE_SECRET_KEY`). Never expose secret keys in frontend JavaScript.

---

## API Authentication

### API Key Authentication
Initialize gateway SDK with secret key, then use SDK classes for API calls. Simple and most common pattern.

### OAuth Token Authentication
For gateways like PayPal that use OAuth2:

1. Get `client_id` and `client_secret` from Configuration
2. Determine base URL from environment setting
3. POST to token endpoint with `grant_type=client_credentials`
4. Set `Authorization: Basic base64(client_id:client_secret)` header
5. Parse response for `access_token`
6. Use token in subsequent requests: `Authorization: Bearer {token}`
7. Include attribution header if required: `PayPal-Partner-Attribution-Id: {gatewayAttributionId}`

Return `array('success' => bool, 'access_token' => string)` pattern for consistent error handling.

---

## Webhook Management

### Creating Webhooks
- Generate webhook URL: `Context::getContext()->link->getModuleLink($moduleName, 'webhook', array(), true)`
- Use different controller names for sandbox vs production if needed (e.g., `webhook` vs `callback`)
- Register with gateway API: POST to webhook endpoint with URL and event types
- Save returned webhook ID in Configuration for later deletion

**PayPal event types to register:** `CHECKOUT.ORDER.APPROVED`, `CHECKOUT.ORDER.COMPLETED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`

### Deleting Webhooks
- Get access token, retrieve webhook ID from Configuration
- Send DELETE request to gateway's webhook endpoint
- Call during uninstall and when API keys change in `postProcess()`

### Webhook Re-registration
In `postProcess()` of `getContent()`, detect when API keys change and:
1. Delete old webhook (if webhook ID exists in Configuration)
2. Create new webhook with new credentials
3. Save new webhook ID

---

## API Communication

### cURL Request Pattern

One standard pattern for all API calls — adapt endpoint, method, and headers:

```php
$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => $base_url . $endpoint,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => $method,  // "POST", "GET", "DELETE"
    CURLOPT_POSTFIELDS => Tools::jsonEncode($postData),
    CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ),
));
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);
```

Handle errors: check `$err` for cURL errors, check response for API errors.

### Wrapper Class Pattern
Organize API calls into logical classes:
```php
$ppCommerce = new PayPalCommerce();
$ppCommerce->orders->create($orderDetails);
$ppCommerce->orders->capture($orderID);
$ppCommerce->orders->get($orderID);
```

### Reading Webhook Payload
```php
$json = Tools::file_get_contents('php://input');
$data = Tools::jsonDecode($json, true);
```

### Response Validation
Check for error keys in response, return consistent `array('success' => bool, 'data' => ...)` format.

---

## Error Handling

### Try-Catch Pattern
Wrap gateway API calls in try-catch. Catch gateway-specific exceptions (`CardError`, `ApiConnectionError`, `InvalidRequestError`, `ApiError`). Log via `FileLogger` or custom log file.

### Custom File Logger
```php
public static function logMsg($logType, $logMsg, $newLine = false)
{
    $file = fopen(dirname(__FILE__).'/../log/'.$logType.'.log', 'a');
    fwrite($file, ($newLine ? "\r\n\n" : "\n") . date('d-m-Y H:i:s') . '  ----  ' . $logMsg);
    fclose($file);
}
```

Log at key points: payment initiation, API responses, webhook events, errors.

### Timeout Handling
Set `CURLOPT_TIMEOUT` (request timeout, e.g., 30s) and `CURLOPT_CONNECTTIMEOUT` (connection timeout, e.g., 10s).

---

## Payment Data Structure

### Order Creation Payload (PayPal example)

Build `$orderData` array with:
- `'intent'` → `'CAPTURE'`
- `'payer'` → Customer name, email, address from Cart/Customer objects
- `'purchase_units'` → Cart items, amounts, currency
- `'application_context'` → `'return_url'` and `'cancel_url'` via `$this->context->link->getModuleLink()`

Address fields map: `address1` → `address_line_1`, `city` → `admin_area_2`, `state` → `admin_area_1`, `postcode` → `postal_code`, `country_iso` → `country_code`

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
