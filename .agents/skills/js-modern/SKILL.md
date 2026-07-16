---
name: js-modern
description: Code standards and best practices for modern JavaScript (ES6+). Covers optional chaining, nullish coalescing, async/await, API fetching, security (XSS avoidance), and client-side performance optimization. Use when writing front-end scripts, handling API client-side requests, or building interactive UI features.
license: MIT
metadata:
  version: "1.0.0"
  author: "Antigravity Dev Experience"
---

# Modern JavaScript (ES6+) Best Practices & Guidelines

Develop secure, performant, and clean client-side logic using modern ECMAScript standards.

---

## 1. Optional Chaining & Nullish Coalescing
* Use `?.` and `??` to handle potentially missing property values or parameters safely without crashing client scripts:
```javascript
// Safe parsing of API responses
const guestName = responseData?.customer?.name ?? 'Guest';
const checkInDate = responseData?.arrival_date ?? new Date().toISOString().split('T')[0];
```

---

## 2. Secure DOM Manipulation (Preventing XSS)
* Never insert unverified user inputs directly into `innerHTML` as it opens doors to Cross-Site Scripting (XSS).
* Always prefer `textContent` or `innerText` when outputting strings:
```javascript
// ❌ Dangerous:
const nameDisplay = document.getElementById('name');
nameDisplay.innerHTML = `<p>Welcome, ${userInputName}</p>`; // Vulnerable if name contains scripts

// ✅ Safe:
const nameDisplay = document.getElementById('name');
nameDisplay.textContent = `Welcome, ${userInputName}`;
```

---

## 3. Async/Await & Robust Error Handling
* Always wrap async operations in `try/catch` blocks and handle HTTP failures explicitly:
```javascript
async function fetchRoomAvailability(checkIn, checkOut) {
  try {
    const response = await fetch(`/api/channex/availability?checkin=${checkIn}&checkout=${checkOut}`);
    
    // Check if HTTP response is OK
    if (!response.ok) {
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    
    const data = await response.json();
    return data;
  } catch (error) {
    console.error('[Availability Fetch Failed]', error);
    // Display safe error banner to user
    showUserError('No pudimos recuperar la disponibilidad en este momento. Por favor reintenta.');
    return [];
  }
}
```

---

## 4. Debouncing & Throttling
* Use debouncing for user inputs that trigger heavy operations (like search filters or slider updates) to prevent hammering APIs:
```javascript
function debounce(fn, delay) {
  let timer;
  return function (...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), delay);
  };
}

// Example usage on calendar change
const handleDateChange = debounce((e) => {
  updateReservationDates(e.target.value);
}, 300);
```
