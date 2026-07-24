# API Testing Harness — USGAR Hotels

## Prerrequisitos

1. PHP server corriendo: `php -S localhost:8000 -t public`
2. Archivo `.env` configurado con las credenciales necesarias
3. MySQL accesible (para endpoints que consultan QloApps)

## Tests Rápidos (Smoke Tests)

### Health Check
```bash
curl -s http://localhost:8000/api/health | jq .
# Esperado: { "success": true, "status": "ok" }
```

### Rooms (sin fechas)
```bash
curl -s http://localhost:8000/api/rooms | jq .
# Esperado: { "success": true, "rooms": [...] }
# Si falta DB: { "success": false, "error": { "code": "MISSING_CREDENTIALS" } }
```

### Rooms (con fechas)
```bash
curl -s "http://localhost:8000/api/rooms?checkIn=2026-08-01&checkOut=2026-08-03" | jq .
```

### Booking (validación de campos)
```bash
curl -s -X POST http://localhost:8000/api/booking \
  -H "Content-Type: application/json" \
  -d '{}' | jq .
# Esperado: { "success": false, "error": { "code": "VALIDATION_ERROR" } }
```

### Auth Register (validación)
```bash
curl -s -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{}' | jq .
# Esperado: error de validación
```

### Auth Me (sin token)
```bash
curl -s http://localhost:8000/api/auth/me | jq .
# Esperado: { "success": false, "error": { "code": "UNAUTHORIZED" } }
```

## Script Automatizado

Usar `tests/api-harness.sh` o `tests/api-harness.php` para ejecutar todos los tests de una vez.

```bash
# Bash
bash tests/api-harness.sh

# PHP (para Hostinger donde no hay bash)
php tests/api-harness.php
```

## Interpretación de Resultados

| Símbolo | Significado |
|---------|------------|
| ✅ | Endpoint responde correctamente |
| ⚠️ | Endpoint responde pero con error esperado (falta config) |
| ❌ | Endpoint no responde o error inesperado |
