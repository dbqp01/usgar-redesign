# Query Patterns

---

## Data Consistency Principle

Before writing any calculation query, check `controllers/admin/AdminStatsController.php` for existing methods that compute the same metric. If a canonical method exists, your stats module must follow the same logic -- same tables, same columns, same JOINs, same WHERE conditions, same arithmetic.

**Canonical methods and what they compute:**

| Metric | Method | Key Logic |
|--------|--------|-----------|
| Occupancy data | `getOccupancyData()` | Total rooms, booked rooms, unavailable rooms, available rooms |
| Occupancy rate | `getAverageOccupancyRate()` | (totalBooked / totalRooms) * 100 |
| ADR | `getAverageDailyRate()` | totalRoomsRevenue / totalBookedRooms |
| RevPAR | `getRevenuePerAvailableRoom()` | totalRoomsRevenue / totalRooms |
| TrevPAR | `getTotalRevenuePerAvailableRoom()` | (roomRevenue + serviceRevenue) / totalRooms |
| ALOS | `getAverageLengthOfStay()` | Average DATEDIFF(date_to, date_from) per booking |
| Cancellation rate | `getCancellationRate()` | cancelledBookings / totalBookings |
| Room revenue (per-day) | `getRoomsRevenueForDiscreteDates()` | Per-night normalization with conversion_rate |
| Service revenue (per-day) | `getServicesRevenueForDiscreteDates()` | SUM(total_price_tax_excl / conversion_rate) |
| Booked rooms (per-day) | `getOccupiedRoomsForDiscreteDates()` | is_refunded=0, date overlap, uses caching |
| Total rooms (per-day) | `getTotalRoomsForDiscreteDates()` | product.active=1, from htl_room_information |
| Available rooms (per-day) | `getAvailableRoomsForDiscreteDates()` | total - booked - disabled |

**How to apply:**
- **Ideal:** Call the AdminStatsController method directly if it returns what you need
- **Acceptable:** When a report needs multiple columns from a single query for performance, replicate the same logic inline (same tables, same conditions, same math)
- **Not acceptable:** Different logic that produces different numbers for the same metric

---

## Occupancy and Room Calculations

The canonical source for all occupancy-related calculations is `AdminStatsController::getOccupancyData()`. Instead of writing occupancy SQL from scratch, reference this method and follow its logic.

**Key principles from the canonical implementation:**

1. **Total rooms:** Count from `htl_room_information` where associated product is active (`p.active = 1`). Use `AdminStatsController::getTotalRooms()`.

2. **Booked rooms:** Count distinct rooms from `htl_booking_detail` that are not refunded (`is_refunded = 0`) and have date overlap with the query range. For checked-out bookings, use `check_out` date instead of `date_to` for the overlap calculation.

3. **Unavailable rooms:** Two categories -- permanently inactive rooms (`id_status = STATUS_INACTIVE`) and temporarily inactive rooms (`id_status = STATUS_TEMPORARY_INACTIVE` with overlapping disable dates in `htl_room_disable_dates`). Both exclude already-booked rooms.

4. **Available rooms:** `total - booked - unavailable`. Can be negative in edge cases (overbooking, mass-disable) -- use `max(result, 0)` when displaying.

When creating a stats module that reports on occupancy, refer to `getOccupancyData()` for the exact query structure, JOIN conditions, and status handling. If you need per-day breakdown, use `getOccupiedRoomsForDiscreteDates()` and `getTotalRoomsForDiscreteDates()`.

---

## Revenue Normalization (Per-Night)

Room bookings span multiple nights. When calculating revenue for a specific date range, normalize using the LEAST/GREATEST overlap pattern:

**Principle:** Calculate the proportion of the booking that falls within the query date range, then apply that proportion to the booking price. Divide by `conversion_rate` to normalize to default currency.

**Formula:** `total_price_tax_excl * (overlap_nights / total_nights) / conversion_rate`

Where:
- `overlap_nights` = DATEDIFF(LEAST(date_to, query_end), GREATEST(date_from, query_start))
- `total_nights` = DATEDIFF(date_to, date_from)

Reference: `statsbestproducts.php` lines 160-180 for the exact SQL implementation. Also see `AdminStatsController::getRoomsRevenueForDiscreteDates()` for the canonical per-day version.

---

## Hotel Restriction (3-Part EXISTS Pattern)

For order-based queries that must respect hotel access permissions, use this 3-part EXISTS pattern. It covers: room bookings, hotel-linked service products, and standalone service products.

**Structure:**
1. `EXISTS` on `htl_booking_detail` with `addHotelRestriction($idHotel)`
2. `OR EXISTS` on `service_product_order_detail` with `addHotelRestriction($idHotel, 'spod')`
3. `OR EXISTS` on `service_product_order_detail` where `id_hotel = 0 AND id_htl_booking_detail = 0` -- **only when `$idHotel` is 0** (all hotels), to include standalone services not linked to any hotel

Reference: `statssales.php` lines 196-215 for the complete pattern in `getTotals()`.

### Direct Table Restriction

For queries directly on hotel-related tables (not order-based):

`HotelBranchInformation::addHotelRestriction($idHotel, $alias, $idField)` where:
- `$idHotel` -- specific hotel ID, or `false` for employee-based access restriction
- `$alias` -- table alias (default: `'hbd'`)
- `$idField` -- column name (default: `'id_hotel'`)

Reference: `statsbestproducts.php` line 216 -- `addHotelRestriction(false, 'hbil', 'id')` on `htl_branch_info_lang`.

---

## Date Filtering

**In ModuleGrid getData():** Use `$this->getDate()` which returns ` "YYYY-MM-DD" AND "YYYY-MM-DD"`. Append directly after `BETWEEN` in your WHERE clause.

**In ModuleGraph callbacks:** Same `$this->getDate()` method, appended to the query built in `getData()`.

**Static access (outside getData):** `ModuleGraph::getDateBetween()` -- same format.

**When you need separate date_from/date_to** (for subqueries with LEAST/GREATEST): Extract from `$this->_employee->stats_date_from` and `$this->_employee->stats_date_to`, format with `date('Y-m-d', strtotime(...))`, and escape with `pSQL()`.

---

## SQL Security Rules

- **Strings:** Always wrap in `pSQL()` -- e.g., `'"'.pSQL($value).'"'`
- **Integers:** Always cast with `(int)` -- e.g., `(int)Tools::getValue('id')`
- **ORDER BY columns:** Validate with `Validate::IsName()`, escape with `bqSQL()`
- **Dates:** Validate with `Validate::isDate()` before using in queries
- **Never** use `$_GET`, `$_POST`, or unescaped `Tools::getValue()` in SQL

---

## Multi-Language Joins

When displaying translatable names (hotel names, room type names), JOIN with the corresponding `_lang` table using `$this->getLang()` for the language ID.

Tables with lang variants: `product_lang`, `htl_branch_info_lang`, `order_state_lang`, `category_lang`.

---

## Shop Restriction

Add `Shop::addSqlRestriction()` to WHERE clauses in multi-shop contexts:
- `Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'c')` for customer table
- `Shop::addSqlRestriction(false, 'o')` for order table

---

## Key QloApps Tables

| Table | Key Columns | Notes |
|-------|-------------|-------|
| `htl_booking_detail` | id_order, id_room, id_hotel, id_product, date_from, date_to, total_price_tax_excl, is_refunded, is_back_order, id_status, check_in, check_out | Core booking data. id_status tracks booking lifecycle. |
| `htl_room_information` | id, id_product, id_hotel, id_status | Room inventory. Status: ACTIVE=1, INACTIVE=2, TEMPORARY_INACTIVE=3 |
| `htl_room_disable_dates` | id_room, date_from, date_to | Disabled date ranges for temporarily inactive rooms |
| `htl_branch_info` / `_lang` | id, hotel_name, active | Hotel properties |
| `htl_room_type` | id_product, id_hotel | Links products (room types) to hotels |
| `orders` | id_order, id_customer, invoice_date, total_paid_tax_excl, valid, conversion_rate | valid=1 for confirmed orders |
| `service_product_order_detail` | id_order, id_hotel, id_htl_booking_detail, total_price_tax_excl | Service products. id_hotel=0 for standalone services |
| `product` / `product_lang` | id_product, active, booking_product, name | booking_product=1 for room types |

---

See [SKILL.md](../SKILL.md) for the full skill index and checklists.
