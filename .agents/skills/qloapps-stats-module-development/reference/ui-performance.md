# UI Layout and Performance

---

## Module Layout Order

Every stats module follows this layout sequence in `hookAdminStatsModules()`:

1. **Panel heading** -- `$this->displayName`
2. **Guide section** -- Optional help text (alert box)
3. **Hotel selector** -- If module filters by hotel (form with dropdown)
4. **Grid/Graph engine output** -- `$this->engine($params)`
5. **CSV export button** -- Standard export link

Never reorder these sections. Never put the selector before the guide, in a separate panel, or after the engine output.

---

## Guide Section

Use `alert alert-warning` for important context, `alert alert-info` for general help. Place a `<h4>Guide</h4>` heading above it.

Reference: `statsbestcustomers.php` lines 123-137, `statssales.php` lines 91-104.

---

## Hotel Selector Pattern

When a module needs a hotel filter dropdown:

1. Get accessible hotels: `$objHotelBranchInformation->getProfileAccessedHotels($this->context->employee->id_profile, 1)`
2. Render a `<form>` with `class="form-horizontal alert"`, `method="post"`, `action` using `Tools::safeOutput($_SERVER['REQUEST_URI'])`
3. Inside the form: `<select name="id_hotel">` with "All hotels" as first option (value=0), then loop hotels
4. Mark selected option using `Tools::getValue('id_hotel')`
5. Add submit button with `class="btn btn-default pull-right"`

In your queries, read the filter with `(int)Tools::getValue('id_hotel')` and pass to `addHotelRestriction()`.

Reference: `statssales.php` lines 106-131 for the exact layout.

---

## Standard Terminology

Use existing terms from the codebase. Do not invent synonyms.

| Concept | Use This | Not This |
|---------|----------|----------|
| Hotel filter default | "All hotels" | "All Properties", "Every Hotel", "Show All" |
| Room category | "Room type" | "Room Category", "Type of Room" |
| Date range | (global picker -- never in module) | "From Date" / "To Date" |
| Export button | "CSV Export" | "Download", "Export Data" |

Check existing stats modules for terminology before adding new labels.

---

## CSV Export Button

Standard pattern, placed after engine output:

Use `<a>` tag with `class="btn btn-default export-csv"`, href pointing to current URL with `&export=1` appended (escaped via `Tools::safeOutput()`), with `icon-cloud-download` icon and translated "CSV Export" text.

Reference: `statsbestproducts.php` lines 141-143.

---

## No Queries Inside Loops (Non-Negotiable)

This is the most critical performance rule. A 30-day report with queries in a loop produces 60+ database round-trips instead of 1-2.

### Solution Strategies

**Strategy 1: Correlated subqueries in a single query.** Use subqueries in SELECT clause to compute per-row aggregates from different tables -- all in one query execution.

Reference: `statsbestproducts.php` getData() -- uses 4 correlated subqueries (room nights booked, selling price, revenue, available rooms) in a single SELECT instead of querying per product.

**Strategy 2: Pre-aggregate then merge in PHP.** Run 2-3 separate aggregate queries (each with GROUP BY), then merge results in PHP using `array_column()` to build a lookup map. This is 2-3 queries total regardless of result count.

**Strategy 3: JOINs with GROUP BY.** Combine related data into a single query using LEFT JOINs and aggregate functions. Use COALESCE/IFNULL for NULL handling.

### When to Apply

- If you find yourself writing `Db::getInstance()` inside a `foreach`/`for`/`while` -- stop and restructure
- Do aggregations (SUM, COUNT, AVG) in SQL, not in PHP loops
- Target: 1-3 total queries per page load (data + FOUND_ROWS + optional summary)

---

## Performance Checklist

- [ ] No `Db::getInstance()` calls inside any loop
- [ ] Aggregations done in SQL, not PHP
- [ ] Total query count: 1-3 per page load
- [ ] Tested with large date ranges (30+ days)
- [ ] `SQL_CALC_FOUND_ROWS` used for pagination (ModuleGrid)
- [ ] COALESCE/IFNULL for NULL handling

---

## Validation Checklist

### Structure
- [ ] Class name is PascalCase: `Stats{ModuleName}`
- [ ] `$this->name` matches directory name (lowercase, alphanumeric)
- [ ] `$this->tab = 'analytics_stats'`
- [ ] `$this->need_instance = 0`
- [ ] `registerHook('AdminStatsModules')` in `install()`
- [ ] config.xml present and valid
- [ ] index.php security file in every folder

### Data
- [ ] `$this->_values` and `$this->_totalCount` set (ModuleGrid)
- [ ] `$this->_values`, `$this->_legend`, `$this->_titles` set (ModuleGraph)
- [ ] Date filtering uses `$this->getDate()` or `ModuleGraph::getDateBetween()`
- [ ] Hotel restriction applied for hotel-specific data
- [ ] Calculations match AdminStatsController canonical logic
- [ ] No queries inside loops

### Security
- [ ] All SQL strings escaped with `pSQL()`
- [ ] All SQL integers cast with `(int)`
- [ ] ORDER BY columns validated and escaped with `bqSQL()`
- [ ] No direct `$_GET`/`$_POST` access
- [ ] `Tools::safeOutput()` used for URL output

### Functionality
- [ ] Module appears in Stats sidebar after install
- [ ] Date range changes update data correctly
- [ ] Sorting and pagination work (ModuleGrid)
- [ ] CSV export generates valid file
- [ ] `hookAdminStatsModules()` returns string (no echo)

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
