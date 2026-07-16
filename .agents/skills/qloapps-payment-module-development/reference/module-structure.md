# Payment Module Structure

---

## Folder Structure

### Offline Payment Module
```
modules/qlopaymentname/
├── qlopaymentname.php              # Main PaymentModule class
├── config.xml                      # Module metadata
├── index.php                       # Security (every folder)
├── logo.png / logo.gif             # Module logos
├── LICENSE.md / Readme.md / CHANGELOG.txt
├── controllers/front/
│   ├── payment.php                 # Payment confirmation page
│   └── validation.php              # Order creation
├── views/templates/
│   ├── front/payment_execution.tpl
│   ├── hook/payment.tpl, payment_return.tpl
│   └── mail/mail_template_html.tpl, mail_template_text.tpl
├── views/js/front/ , views/css/
├── translations/ , upgrade/
```

### Online Payment Module (adds)
```
├── classes/
│   ├── PaymentHelper.php           # API helper
│   ├── PaymentService.php          # Gateway service
│   ├── PaymentDb.php               # Table create/drop
│   ├── PaymentTransaction.php      # Transaction ObjectModel
│   └── PaymentRefund.php           # Refund ObjectModel
├── controllers/
│   ├── admin/AdminTransactions.php # Transaction listing (optional)
│   └── front/
│       ├── callback.php            # Payment return/callback
│       └── webhook.php             # Webhook handler
├── libs/gateway-sdk/               # Third-party SDK
├── logs/                           # Payment logs
```

---

## Main Module File Template

This is the one template that must be followed closely — the constructor property order and `parent::__construct()` placement matter.

```php
<?php
/**
* @author {moduleAuthor}
* @copyright Since {copyrightYear} {moduleAuthor}
* @license https://opensource.org/license/osl-3-0-php Open Software License version 3.0
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class QloPaymentName extends PaymentModule
{
    public function __construct()
    {
        $this->name = 'qlopaymentname';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = '{moduleAuthor}';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Payment Method Name');
        $this->description = $this->l('Accept payments via gateway');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->payment_type = OrderPayment::PAYMENT_TYPE_ONLINE;  // or REMOTE_PAYMENT

        // Warnings for missing configuration
        if (!Configuration::get('PAYMENT_NAME_API_KEY')) {
            $this->warning = $this->l('API credentials must be configured.');
        }
        $currencies = Currency::checkPaymentCurrencies($this->id);
        if (!$currencies || !count($currencies)) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }
}
```

**Key rules:**
- `$this->tab` must be `'payments_gateways'`
- Set `$this->currencies = true` and `$this->currencies_mode` BEFORE `parent::__construct()`
- Set `$this->payment_type` AFTER `parent::__construct()`
- `$this->displayName` and `$this->description` use `$this->l()` for translations

---

## Mandatory Files

**config.xml** — Module metadata. Must match main class values:
```xml
<?xml version="1.0" encoding="UTF-8" ?>
<module>
    <name>qlopaymentname</name>
    <displayName><![CDATA[Payment Method Name]]></displayName>
    <version><![CDATA[1.0.0]]></version>
    <description><![CDATA[Accept payments via gateway]]></description>
    <author><![CDATA[{moduleAuthor}]]></author>
    <tab><![CDATA[payments_gateways]]></tab>
    <is_configurable>1</is_configurable>
    <need_instance>0</need_instance>
</module>
```

**LICENSE.md** — OSL-3.0 license text with `{copyrightYear}` and `{moduleAuthor}`

**Readme.md** — Features, installation steps, configuration guide, `{supportEmail}`

**CHANGELOG.txt** — Version history: `{releaseDate} - v1.0.0`

**index.php** — Security file in EVERY folder. Prevents directory listing. Standard QloApps header-redirect pattern.

---

## Install / Uninstall

`parent::install()` automatically handles:
- Module registration
- `addCheckboxCountryRestrictionsForModule()` — enables payment for all active countries
- Currency restrictions via `module_currency` table

`parent::uninstall()` automatically cleans up `module_country`, `module_currency`, `module_group` tables.

**Install pattern:**
1. Call `parent::install()`
2. Register hooks (chain with `&&`)
3. Create database tables (if online payment — use PaymentDb class)
4. Set default configuration values via `Configuration::updateValue()`
5. Install admin tabs (if needed)
6. Return `false` on any failure

**Uninstall pattern:**
1. Drop database tables (if any)
2. Delete all configuration values via `Configuration::deleteByName()`
3. Uninstall admin tabs (if any)
4. Call `parent::uninstall()` LAST

**Required hooks:**
- Offline: `payment`, `paymentReturn`, `displayPaymentEU`
- Online: Above + `actionFrontControllerSetMedia`

**Hook naming:** Register hooks as `payment`, `paymentReturn`, etc. (short names). These are aliases — the framework maps them to `displayPayment`, `displayPaymentReturn` internally. Always use the short form in `registerHook()`.

---

## Payment Hooks

### hookPayment($params) — Display payment option

- Check `$this->active` and `$this->checkCurrency($params['cart'])`
- For online: also check `$this->isConfigured()`
- Assign `this_path` and `this_path_ssl` to Smarty
- Return `$this->display(__FILE__, 'payment.tpl')`

### hookPaymentReturn($params) — Display confirmation

See [payment-patterns.md](./payment-patterns.md) for complete implementation with advance payment handling.

### hookDisplayPaymentEU($params) — EU payment display

Return associative array with:
- `'cta_text'` → `$this->l('Pay with') . ' ' . $this->displayName`
- `'logo'` → `Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/logo.png')`
- `'action'` → `$this->context->link->getModuleLink($this->name, 'payment', array(), true)`

### hookActionFrontControllerSetMedia() — Add JS/CSS (online only)

- Check `Tools::getValue('controller') == 'orderopc'`
- Add gateway SDK: `$this->context->controller->addJS($url, array('server' => 'remote'))`
- Add module JS/CSS: `addJS($this->local_path.'views/js/...')`, `addCSS($this->_path.'views/css/...')`
- Pass JS variables: `Media::addJsDef(array('KEY' => $value))`

---

## Currency and Country Restrictions

**Currency setup:** Automatic via `parent::install()` based on `$this->currencies_mode`:
- `'checkbox'` → `addCheckboxCurrencyRestrictionsForModule()`
- `'radio'` → `addRadioCurrencyRestrictionsForModule()`

**checkCurrency() method** — Must be defined in your module class:

```php
public function checkCurrency($cart)
{
    $currency_order = new Currency($cart->id_currency);
    $currencies_module = $this->getCurrency($cart->id_currency);
    if (is_array($currencies_module)) {
        foreach ($currencies_module as $currency_module) {
            if ($currency_order->id == $currency_module['id_currency']) {
                return true;
            }
        }
    }
    return false;
}
```

**Country restrictions:** Automatically handled by `parent::install()` via `addCheckboxCountryRestrictionsForModule()`.

---

## Database Setup

For online payment modules, create a `PaymentDb` class in `classes/`:

**Transaction table schema:**
- `id_transaction` INT PRIMARY KEY AUTO_INCREMENT
- `id_order` INT, `id_cart` INT, `id_customer` INT
- `transaction_id` VARCHAR(255) — gateway's transaction ID
- `amount` DECIMAL(20,6), `currency` VARCHAR(3)
- `status` VARCHAR(50), `gateway_response` TEXT
- `date_add` DATETIME, `date_upd` DATETIME
- Keys on `id_order`, `transaction_id`

**Refund table schema:**
- `id_refund` INT PRIMARY KEY AUTO_INCREMENT
- `id_transaction` INT, `id_order` INT
- `refund_id` VARCHAR(255), `amount` DECIMAL(20,6)
- `reason` TEXT, `status` VARCHAR(50)
- `date_add` DATETIME
- Key on `id_transaction`

**Pattern:** Use `_DB_PREFIX_` + module-specific prefix in table names. Engine: `_MYSQL_ENGINE_`. Execute via `Db::getInstance()->execute()`. Return false on failure.

---

## File Naming Conventions

**Classes:** `PaymentHelper.php`, `PaymentService.php`, `PaymentDb.php`, `PaymentTransaction.php`, `PaymentRefund.php`
**Front controllers:** `payment.php`, `validation.php`, `callback.php`, `webhook.php`, `notify.php`
**Templates:** `payment.tpl`, `payment_execution.tpl`, `payment_return.tpl`, `infos.tpl`
**Assets:** `payment.js`, `payment.css`

---

## Upgrade Scripts

File: `upgrade/upgrade-{version}.php` (e.g., `upgrade-1.0.1.php`)
Function: `upgrade_module_{version_underscored}($module)` (e.g., `upgrade_module_1_0_1`)
- Add new configuration via `Configuration::updateValue()`
- Alter tables via `Db::getInstance()->execute('ALTER TABLE...')`
- Return `true` on success, `false` on failure
- Guard with `if (!defined('_PS_VERSION_')) { exit; }`

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
