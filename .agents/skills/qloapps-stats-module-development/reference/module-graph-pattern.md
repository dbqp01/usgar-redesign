# ModuleGraph Pattern

---

## Overview

ModuleGraph renders charts (line, column, pie) using the NVD3 engine. The framework auto-determines date granularity from the selected date range and calls your callback methods to populate data.

**Reference implementations:** `modules/statssales/statssales.php`, `modules/statsvisits/statsvisits.php`

---

## Graph Types

- `line` -- Trends over time (1+ data series)
- `column` -- Comparisons (1+ data series)
- `pie` -- Distribution/proportion (single series)

---

## Constructor Setup

1. Set `$this->name`, `$this->tab = 'analytics_stats'`, version, author
2. Set `$this->need_instance = 0`
3. Call `parent::__construct()`
4. Set `$this->displayName` and `$this->description`

Declare private properties as needed: `$html`, `$query`, `$query_group_by`, plus any module-specific option/filter properties.

---

## install()

Same as ModuleGrid: chain `parent::install()` with `$this->registerHook('AdminStatsModules')`.

---

## hookAdminStatsModules()

Builds the page HTML. Can render multiple graphs by calling `engine()` multiple times with different options.

**Steps:**

1. Handle CSV export: check `Tools::getValue('export')`, call `$this->csvExport()` with matching params
2. Build HTML: panel heading + `$this->engine($graph_params)` + export links
3. Return the HTML string

**Engine parameters:** `type` (line/column/pie), `layers` (number of data series, 0 for auto), `option` (passed to `setOption()`)

For modules with hotel filter, add a hotel selector dropdown (see [ui-performance.md](./ui-performance.md#hotel-selector-pattern)) and pass the hotel ID through the option parameter.

Reference: `statssales.php` hookAdminStatsModules() -- 3 graphs (2 line + 1 pie) with hotel filter dropdown and CSV export for each.

---

## setOption() -- Option Parsing

Override this method to parse the `option` parameter from engine params. Called by the framework before `getData()`.

Use a compound format like `"optionId-countryId-hotelId"` to pass multiple filter values through a single string. Parse with `explode('-', $options)` and store in instance properties.

Set `$this->_titles['main'][]` based on the selected option to label the chart.

Reference: `statssales.php` lines 220-244 -- parses 3 options (orders count, revenue, status distribution) with country and hotel filters.

---

## getData() and the setDateGraph() Callback Pattern

For time-series charts, use `setDateGraph()` instead of manually populating `$this->_values`.

**How it works:**

1. In `getData($layers)`, build the base SQL query ending with `BETWEEN` (the date will be appended)
2. Store any GROUP BY clause in a separate property (e.g., `$this->query_group_by`)
3. Call `$this->setDateGraph($layers, true)`
4. `setDateGraph()` determines granularity from the date range and calls the corresponding callback

**Granularity auto-detection:**
- Same day -- hourly (calls `setDayValues()`)
- Up to 1 month -- daily (calls `setMonthValues()`)
- Up to 1 year -- monthly (calls `setYearValues()`)
- More than 1 year -- yearly (calls `setAllTimeValues()`)

**Callback methods:** Each callback executes the query (appending `$this->getDate()` + group by clause), then loops through results extracting the appropriate date part using `substr()`:

- `setAllTimeValues()` -- extract year: `substr($row['date'], 0, 4)`
- `setYearValues()` -- extract month (1-12): `substr($row['date'], 5, 2)`
- `setMonthValues()` -- extract day (1-31): `substr($row['date'], 8, 2)`
- `setDayValues()` -- extract hour (0-23): `substr($row['date'], 11, 2)`

Each callback adds to `$this->_values[index]` using `+=` (pre-initialized to zero by `setDateGraph()`).

Reference: `statssales.php` lines 246-331 -- complete implementation of all 4 callbacks with option-based branching (count vs revenue).

---

## Pie Charts (Non-Time-Series)

For pie charts, populate `$this->_values` and `$this->_legend` directly without `setDateGraph()`:

- `$this->_values[]` -- numeric values (one per slice)
- `$this->_legend[]` -- labels (one per slice)

Handle in `getData()` by checking the option and calling a separate method. Use `ModuleGraph::getDateBetween()` for the date range.

Reference: `statssales.php` getOrderStatusesData() -- pie chart showing order status distribution.

---

## Multi-Graph Modules

A single module can display multiple graphs by calling `engine()` multiple times with different `option` values. Each call triggers a separate AJAX request to `drawer.php`, which calls `setOption()` then `getData()` with that option.

Reference: `statssales.php` -- renders 3 separate graphs (orders line, revenue line, status pie) from one module.

---

## Date Methods

| Method | Use In |
|--------|--------|
| `$this->getDate()` | Inside `getData()` and callbacks -- appends date range to query |
| `ModuleGraph::getDateBetween()` | Outside `getData()` (e.g., in `hookAdminStatsModules()` for summary queries) |

Both return the same format: ` "YYYY-MM-DD" AND "YYYY-MM-DD"` (with leading space).

---

## Format Options

Control axis formatting by setting `$this->_formats`:
- `$this->_formats['y'] = 'd'` -- integer format (no decimals) for count-based charts

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
