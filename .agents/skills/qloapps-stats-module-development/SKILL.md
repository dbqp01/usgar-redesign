---
name: qloapps-stats-module-development
description: Create, modify, or debug QloApps statistics modules displayed under AdminStats. Covers ModuleGrid (tables), ModuleGraph (charts), and plain Module (dashboards). Use for any QloApps stats module task including occupancy reports, revenue charts, booking analytics, or custom dashboards.
license: OSL-3.0
metadata:
  author: QloApps
---

# QloApps Stats Module Development

Create statistics modules that appear under Stats > Stats in the QloApps back office.

## Quick Commands

```bash
# Stats module structure (single-file module)
modules/stats{modulename}/
├── stats{modulename}.php      # Main module class
├── config.xml                  # Module metadata
├── index.php                   # Security file (every folder)
└── logo.png                    # Module icon (optional)
```

Common operations:
- Create tabular report (ModuleGrid) -- See [module-grid-pattern.md](./reference/module-grid-pattern.md)
- Create chart/graph (ModuleGraph) -- See [module-graph-pattern.md](./reference/module-graph-pattern.md)
- Hotel restriction and SQL patterns -- See [query-patterns.md](./reference/query-patterns.md)
- UI layout and performance rules -- See [ui-performance.md](./reference/ui-performance.md)

---

## When to Use This Skill

Applies to:
- Creating statistics/analytics modules for the Stats dashboard
- Building reports (occupancy, revenue, bookings, customers)
- Creating charts and trend visualizations
- Building complex dashboard widgets under AdminStats

## When NOT to Use

- Feature modules → `module-development` skill
- Payment modules → `payment-module-development` skill

---

## Critical Rules

### 1. Data Consistency (Non-Negotiable)

When a stats module calculates a metric that already exists in QloApps (in a KPI, dashboard widget, or another stats module), the calculation logic MUST be consistent.

**Canonical source:** `controllers/admin/AdminStatsController.php` contains 80+ static methods that serve KPIs and dashboard modules. Before writing any calculation, check if AdminStatsController already computes it.

Consistency means:
- Same tables used
- Same columns used
- Same JOIN conditions
- Same WHERE filters (e.g., `is_refunded = 0`, `o.valid = 1`)
- Same arithmetic operations

**Ideal:** Reuse the exact AdminStatsController method. **Acceptable:** When a report needs multiple columns from a single query for performance, replicate the same logic inline. **Not acceptable:** Different calculation logic that produces different numbers for the same metric.

Key canonical calculations in AdminStatsController:
- `getOccupancyData()` -- total rooms, booked rooms, unavailable rooms, available rooms
- `getAverageDailyRate()` -- total room revenue / total booked rooms
- `getAverageOccupancyRate()` -- (totalBooked / totalRooms) * 100
- `getRevenuePerAvailableRoom()` -- RevPAR
- `getRoomsRevenueForDiscreteDates()` -- per-night normalization: `SUM(total_price_tax_excl / conversion_rate) / DATEDIFF(date_to, date_from)`
- `getCancellationRate()` -- cancelled bookings / total bookings
- `getAverageLengthOfStay()` -- ALOS

### 2. No Queries Inside Loops (Non-Negotiable)

Never execute database queries inside `foreach`, `for`, or `while` loops. Use JOINs, subqueries, or batch queries instead.

### 3. Do Not Recreate Built-in Features

The stats dashboard already provides:

| Feature | How to Access |
|---------|---------------|
| Date range picker | `$this->getDate()` or `ModuleGraph::getDateBetween()` |
| Pagination | `$this->_start`, `$this->_limit` (ModuleGrid) |
| Sorting | `$this->_sort`, `$this->_direction` (ModuleGrid) |
| CSV export | `$this->csvExport($engine_params)` |
| Hotel filter | `HotelBranchInformation::addHotelRestriction()` |

Never create custom date pickers, pagination controls, sorting logic, or CSV generation code.

### 4. hookAdminStatsModules() Must Return a String

Never `echo`. The controller expects a return value.

### 5. SQL Security

- Strings: `pSQL()`
- Integers: `(int)` cast
- Column/table names in ORDER BY: `bqSQL()` with `Validate::IsName()` check
- Never use `$_GET`/`$_POST` directly

---

## Module Types

| Type | Base Class | Use When | Rendering | Reference |
|------|-----------|----------|-----------|-----------|
| ModuleGrid | `ModuleGrid` | Tabular data (lists, rankings, reports) | Automatic via grid engine | [module-grid-pattern.md](./reference/module-grid-pattern.md) |
| ModuleGraph | `ModuleGraph` | Charts (line, column, pie) | Automatic via graph engine | [module-graph-pattern.md](./reference/module-graph-pattern.md) |
| Module | `Module` | Complex dashboards, multiple views, custom forms | Manual HTML | `modules/statsforecast/statsforecast.php` |

**Decision:** If the output is rows and columns, use ModuleGrid. If it is a chart, use ModuleGraph. Use plain Module only when neither fits.

---

## Skill Components

Reference guides for each area:

- [module-grid-pattern.md](./reference/module-grid-pattern.md) -- ModuleGrid class, columns, getData(), hookAdminStatsModules(), CSV export, rendering pipeline
- [module-graph-pattern.md](./reference/module-graph-pattern.md) -- ModuleGraph class, graph types, setDateGraph() callback pattern, setOption(), multi-graph modules
- [query-patterns.md](./reference/query-patterns.md) -- Hotel restriction, revenue normalization, occupancy calculation, date filtering, SQL security, data consistency examples
- [ui-performance.md](./reference/ui-performance.md) -- UI layout order, hotel selector pattern, terminology, performance optimization, no-queries-in-loops patterns

---

## Rendering Pipeline

### ModuleGrid
```
hookAdminStatsModules() --> engine() --> iframe HTML
--> grider.php (AJAX) --> create() --> getData() --> render() --> JSON
--> JavaScript populates table
```

### ModuleGraph
```
hookAdminStatsModules() --> engine() --> AJAX HTML
--> drawer.php --> create() --> getData() --> draw() --> JSON
--> NVD3 renders chart
```

### Plain Module
```
hookAdminStatsModules() --> return custom HTML string directly
```

The grid/graph engines handle all rendering. Your module only implements `getData()` and `hookAdminStatsModules()`.

---

## Quick Reference

### Module Creation Checklist
- [ ] Determine module type (ModuleGrid / ModuleGraph / Module)
- [ ] Check AdminStatsController for existing calculation logic
- [ ] Create module file extending correct base class
- [ ] Set `$this->tab = 'analytics_stats'` and `$this->need_instance = 0`
- [ ] Register hook: `$this->registerHook('AdminStatsModules')`
- [ ] Implement `getData()` with date filtering and hotel restriction
- [ ] Set `$this->_values` and `$this->_totalCount` (ModuleGrid)
- [ ] Return HTML string from `hookAdminStatsModules()`
- [ ] Create config.xml
- [ ] Add index.php security file
- [ ] Verify no queries inside loops
- [ ] Verify data consistency with AdminStatsController

### Constructor Pattern (All Types)
```php
$this->name = 'stats{modulename}';       // lowercase, alphanumeric
$this->tab = 'analytics_stats';           // always this value
$this->version = '1.0.0';
$this->author = '{author}';
$this->need_instance = 0;

parent::__construct();

$this->displayName = $this->l('Module Name');
$this->description = $this->l('Description.');
```

### config.xml Template
```xml
<?xml version="1.0" encoding="UTF-8" ?>
<module>
    <name>stats{modulename}</name>
    <displayName><![CDATA[Module Name]]></displayName>
    <version><![CDATA[1.0.0]]></version>
    <description><![CDATA[Description.]]></description>
    <author><![CDATA[{author}]]></author>
    <tab><![CDATA[analytics_stats]]></tab>
    <is_configurable>0</is_configurable>
    <need_instance>0</need_instance>
</module>
```

### Required Include for Hotel Data
```php
// At top of module file (NOT inside getData)
require_once _PS_MODULE_DIR_.'hotelreservationsystem/define.php';
```

---

## Common Pitfalls

1. **Creating custom date pickers** -- Use `$this->getDate()`. The system already provides a global date range picker.
2. **Queries inside loops** -- Use JOINs and subqueries. See [ui-performance.md](./reference/ui-performance.md#no-queries-in-loops).
3. **Inconsistent calculations** -- Check AdminStatsController before writing metric logic. See [query-patterns.md](./reference/query-patterns.md#data-consistency-reference).
4. **Echo instead of return** -- `hookAdminStatsModules()` must return a string.
5. **Missing hotel restriction** -- Hotel-specific data must use `HotelBranchInformation::addHotelRestriction()`.
6. **Missing `_DB_PREFIX_`** -- All table names must use `_DB_PREFIX_` constant.
7. **Forgetting `$this->_values` / `$this->_totalCount`** -- ModuleGrid requires both in `getData()`.
8. **Wrong hook name** -- Must be `AdminStatsModules` (or alias `displayAdminStatsModules`).

---

## Reference Modules

| Module | Type | Key Concepts |
|--------|------|-------------|
| `modules/statsbestproducts/` | ModuleGrid | Complex subqueries, revenue normalization, available rooms |
| `modules/statsbestcustomers/` | ModuleGrid | Customer rankings, hotel restriction EXISTS pattern |
| `modules/statssales/` | ModuleGraph | setOption(), multi-graph, setDateGraph() callbacks |
| `modules/statsvisits/` | ModuleGraph | Simple line chart, layers |
| `modules/statsforecast/` | Module | Complex dashboard, time granularity, conversion funnel |
| `modules/statscheckup/` | Module | Configuration thresholds, color scoring, multiple tabs |

Core files:
- `classes/module/ModuleGrid.php` -- Base grid class with `getData()`, `engine()`, `create()`
- `classes/module/ModuleGraph.php` -- Base graph class with `setDateGraph()`, `setOption()`
- `controllers/admin/AdminStatsController.php` -- Canonical calculation engine (80+ methods)
- `controllers/admin/AdminStatsTabController.php` -- Stats page controller, date picker, module loading
- `adminhtl/grider.php` -- AJAX entry point for grid rendering
- `adminhtl/drawer.php` -- AJAX entry point for graph rendering

---

## Development Workflow

1. **Plan** -- Determine module type, identify data sources, check AdminStatsController for existing calculations
2. **Setup** -- Create module directory, main file, config.xml, index.php
3. **Implement** -- Write `getData()` with proper date filtering, hotel restriction, and SQL security
4. **Validate** -- Verify data matches AdminStatsController calculations, no queries in loops, proper escaping
5. **Test** -- Install module, verify in Stats dashboard, test date range changes, CSV export, sorting/pagination

---

## Troubleshooting

1. **Module not showing in sidebar** -- Verify `registerHook('AdminStatsModules')` in `install()`, reinstall module, clear `cache/class_index.php`.
2. **Grid shows empty** -- Check `$this->_values` is set in `getData()`, verify SQL returns data, check browser console for AJAX errors.
3. **Graph not rendering** -- Verify `graphnvd3` module is installed and active, check `$this->_values` array structure.
4. **CSV export broken** -- Ensure `$engine_params` in `csvExport()` matches the ones passed to `engine()`.

---

## Additional Resources

- QloApps DevDocs: https://devdocs.qloapps.com/
