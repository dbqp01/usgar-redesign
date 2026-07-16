---
name: php-8-modern
description: Code standards and security practices for PHP 8.x backend development. Covers secure PDO prepared statements, database sanitization, JSON response conventions, error logging, and inputs filtering. Use when writing, refactoring, or debugging PHP files inside public/api/.
license: MIT
metadata:
  version: "8.3.0"
  author: "Antigravity Dev Experience"
---

# Modern PHP 8.x Best Practices & Security Guidelines

For the hybrid architecture (Astro static frontend + PHP backend), all backend API routes in `public/api/` must follow strict PHP 8.x modern security and performance conventions.

---

## 1. Type Safety & Strict Types

Always declare strict types at the very top of files to avoid type coercion issues:

```php
<?php
declare(strict_types=1);
```

---

## 2. Secure Database Operations (PDO)

Never concatenate variables directly into SQL strings. Always use prepared statements with placeholders (either positional `?` or named `:name`).

### Safe Query Example:
```php
<?php
require_once __DIR__ . '/db.php';

$pdo = getDbConnection();
if (!$pdo) {
    sendError('Database connection unavailable', 500);
}

$roomId = $_GET['roomId'] ?? null;

if (!$roomId) {
    sendError('Missing roomId parameter', 400);
}

try {
    // 1. Prepare statements with named parameters
    $stmt = $pdo->prepare("SELECT * FROM ps_htl_room_type WHERE id_room_type = :roomId AND active = 1");
    
    // 2. Bind and execute (PDO automatically sanitizes inputs)
    $stmt->execute([':roomId' => (int)$roomId]);
    $room = $stmt->fetch();
    
    if (!$room) {
        sendError('Room not found', 404);
    }
    
    sendJson($room);
} catch (PDOException $e) {
    // 3. Log error details securely on the server; never expose to client
    error_log("[PDO SELECT Error] Room ID: {$roomId}. Details: " . $e->getMessage());
    sendError('An internal database error occurred', 500);
}
```

---

## 3. JSON Output Conventions

Always set the correct `Content-Type` header and call `exit()` immediately after outputting JSON to prevent trailing HTML or warnings from corrupting the payload.

```php
function sendJson(array|object $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit();
}

function sendError(string $message, int $status = 500): void {
    sendJson(['error' => $message], $status);
}
```

---

## 4. Input Sanitization & Verification

- **XSS Prevention:** Escape outputs before outputting HTML:
  ```php
  $cleanName = htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8');
  ```
- **Validation:** Validate formatting before using variables:
  ```php
  $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
  if (!$email) {
      sendError('Invalid email format', 400);
  }
  ```
- **Path Traversal Guard:** Never include files using direct user input names. Always map inputs to a predefined whitelist.
- **CSRF & Webhook Signatures:** For webhooks (e.g., Mercado Pago), verify the signature headers using HMAC-SHA256 comparison before processing.
