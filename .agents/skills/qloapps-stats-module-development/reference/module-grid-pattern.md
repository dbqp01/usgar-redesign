# ModuleGrid Pattern

---

## Overview

ModuleGrid renders tabular data with built-in pagination, sorting, and CSV export. Your module defines columns and implements `getData()` -- the framework handles everything else.

**Reference implementations:** `modules/statsbestproducts/statsbestproducts.php`, `modules/statsbestcustomers/statsbestcustomers.php`

---

## Required Properties

Declare these private properties in every ModuleGrid module. Copy them exactly from reference modules:

`$html`, `$query`, `$columns`, `$default_sort_column`, `$default_sort_direction`, `$empty_message`, `$paging_message`

---

## Constructor Setup

1. Set `$this->name` (lowercase, alphanumeric), `$this->tab = 'analytics_stats'`, version, author
2. Set `$this->need_instance = 0`
3. Call `parent::__construct()`
4. Set `$this->default_sort_column` and `$this->default_sort_direction`
5. Set `$this->empty_message` and `$this->paging_message` (use `sprintf` with `{0} - {1}` and `{2}` placeholders for paging)
6. Define `$this->columns` array
7. Set `$this->displayName` and `$this->description` using `$this->l()`

---

## Column Definition

Each column needs: `id`, `header` (translated), `dataIndex` (must match SQL alias or post-processed key), `align` (left/center/right). Optional: `tooltip` (translated).

Reference: `statsbestproducts.php` constructor -- 8 columns defined with all properties.

---

## install()

Chain `parent::install()` with `$this->registerHook('AdminStatsModules')`. No other hooks needed.

---

## hookAdminStatsModules()

This method builds the page HTML. Keep it simple:

1. Build `$engine_params` array with: `id`, `title`, `columns`, `defaultSortColumn`, `defaultSortDirection`, `emptyMessage`, `pagingMessage`
2. Check `Tools::getValue('export')` -- if true, call `$this->csvExport($engine_params)` (built-in)
3. Build HTML: panel heading + `$this->engine($engine_params)` + CSV export link
4. **Return** the HTML string (never echo)

The `engine()` call returns an iframe that triggers AJAX to `grider.php`, which calls `create()` then your `getData()`.

---

## getData()

This is where SQL executes. Must set `$this->_values` (array of rows) and `$this->_totalCount` (total rows for pagination).

**Steps:**

1. Get date range: `$date_between = $this->getDate()` -- never create date inputs
2. Get language: `$id_lang = $this->getLang()`
3. Build `$this->query` with `SQL_CALC_FOUND_ROWS`, proper JOINs, date filtering, and hotel restriction
4. Append sorting: use `$this->_sort` and `$this->_direction` (provided by engine). Validate with `Validate::IsName()` and escape with `bqSQL()`
5. Append pagination: use `$this->_start` and `$this->_limit` (provided by engine). Validate with `Validate::IsUnsignedInt()`
6. Execute query with `Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS()`
7. Get total: `Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('SELECT FOUND_ROWS()')`
8. Post-process values (formatting, links)
9. Set `$this->_values = $values`

**Reference:** `statsbestproducts.php` getData() -- complete implementation with subqueries, hotel restriction, sorting, pagination, and post-processing.

---

## Separate Date Values

When subqueries need `date_from` and `date_to` individually (e.g., LEAST/GREATEST overlap calculations):

- Extract from `$this->_employee->stats_date_from` and `$this->_employee->stats_date_to`
- Handle same-day case: if `$date_from == $date_to`, add one day to `$date_to`
- Escape with `pSQL()`

Reference: `statsbestproducts.php` lines 152-156.

---

## Post-Processing Patterns

Apply after query execution, inside `foreach ($values as &$value)`:

**Currency formatting:** Use `Tools::displayPrice($value['field'], $currency)` where `$currency = new Currency(Configuration::get('PS_CURRENCY_DEFAULT'))`

**Admin links:** Wrap names in `<a>` tags using `$this->context->link->getAdminLink()`. Skip when `Tools::getValue('export')` is true so CSV gets raw data.

**Active/Inactive badges:** Use `<span class="badge badge-success">` / `badge-danger` pattern.

**Computed columns:** Calculate derived values in PHP after query (e.g., bookings per day = totalBooked / numberOfDays). Use `HotelHelper::getNumberOfDays()` for day count.

---

## Engine Properties Reference

These are set automatically by the grid engine before `getData()` is called:

| Property | Description |
|----------|-------------|
| `$this->_employee` | Current employee (has `stats_date_from`, `stats_date_to`) |
| `$this->_start` | Pagination offset |
| `$this->_limit` | Rows per page |
| `$this->_sort` | Sort column name |
| `$this->_direction` | Sort direction (ASC/DESC) |
| `$this->_id_lang` | Current language ID |

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
