---
name: json-standards
description: Best practices for parsing, formatting, and structures of JSON payloads. Covers safe JSON parsing in JavaScript, JSON exceptions and flags in PHP, API response formatting, and validation. Use when writing configs, REST API responses, or JSON payload handlers.
license: MIT
metadata:
  version: "1.0.0"
  author: "Antigravity Dev Experience"
---

# JSON Standards & API Payload Best Practices

Ensure clean data exchange between Astro frontend and PHP backend APIs using standard JSON serialization and parsing rules.

---

## 1. JavaScript Safe JSON Parsing
* Always wrap `JSON.parse()` in a `try/catch` block to prevent parsing of corrupted responses or HTML error pages from crashing the UI:
```javascript
function safeParseJSON(rawString) {
  try {
    return JSON.parse(rawString);
  } catch (error) {
    console.error('[JSON Parse Error]', error);
    return null;
  }
}
```

---

## 2. PHP JSON Encoding/Decoding (PHP 8+)
* In PHP 8+, use the `JSON_THROW_ON_ERROR` flag (or configure it globally) to catch parsing errors immediately as exceptions:
```php
try {
    $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    error_log("[JSON Decode Error] " . $e->getMessage());
    sendError('Invalid JSON format received', 400);
}
```

* **Useful Encoding Flags:**
  * `JSON_UNESCAPED_SLASHES`: Prevents escaping `/` (keeps URLs readable: `https://...` instead of `https:\/\/...`).
  * `JSON_UNESCAPED_UNICODE`: Keeps UTF-8 accents and characters unescaped (keeps text like "Habitación" readable).
  * `JSON_PRETTY_PRINT`: Use only for writing config files or local database mock files (e.g. `bookings.json`).

```php
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

---

## 3. Data Representation Standards
* **Dates:** Use ISO 8601 format (`YYYY-MM-DD`) for booking dates:
  ```json
  "checkIn": "2026-07-12"
  ```
* **Money:** Keep minor units (cents) as integers on database/write endpoints, but present major units as decimal strings on read endpoints (e.g., `"90.00"`):
  ```json
  "pricePerNight": "90.00"
  ```
* **Booleans and Integers:** Keep boolean fields as `true`/`false` and counters as actual integers, rather than strings (avoid `"1"` / `"0"` or `"true"`).
