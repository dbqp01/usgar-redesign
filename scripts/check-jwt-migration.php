<?php
declare(strict_types=1);

// Self-check de migracion JWT: firebase/php-jwt + compatibilidad con tokens
// emitidos por la implementacion casera anterior (mismo secret/alg/payload).
// Uso: php scripts/check-jwt-migration.php
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Features\Auth\SessionService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Usa la MISMA clave que SessionService (Config cache del .env local): si el
// .env no la define, SessionService lanzaria 401 y el check falla con claridad.
$secret = Config::get('AUTH_JWT_SECRET');
if ($secret === null || strlen($secret) < 32) {
    fwrite(STDERR, "AUTH_JWT_SECRET no configurado (>=32 chars) en el .env local. Abortando check.\n");
    exit(1);
}

$user = ['id' => 42, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@test.dev', 'photo_url' => null, 'provider' => 'email'];

// 1. Token emitido por la lib nueva valida y conserva los claims
$token = SessionService::createToken($user);
$payload = SessionService::validateToken($token);
assert(is_array($payload), 'token nuevo debe validar');
assert((int)$payload['sub'] === 42 && $payload['email'] === 'ada@test.dev', 'claims intactos');

// 2. Compatibilidad: token emitido con el algoritmo casero (mismo secret, HS256)
$now = time();
$h = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
$p = rtrim(strtr(base64_encode(json_encode([
    'sub' => 42, 'name' => 'Ada Lovelace', 'email' => 'ada@test.dev',
    'photo' => null, 'provider' => 'email', 'iat' => $now, 'exp' => $now + 3600,
])), '+/', '-_'), '=');
$legacy = $h . '.' . $p . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
$legacyPayload = SessionService::validateToken($legacy);
assert(is_array($legacyPayload) && (int)$legacyPayload['sub'] === 42, 'token legacy (casero) debe seguir validando');

// 3. Firma alterada -> null
$tampered = substr($token, 0, -2) . 'xx';
assert(SessionService::validateToken($tampered) === null, 'firma alterada debe rechazarse');

// 4. Exp -> token con exp pasado debe rechazarse (la lib valida exp)
$expired = JWT::encode(array_merge($payload, ['exp' => $now - 10]), $secret, 'HS256');
assert(SessionService::validateToken($expired) === null, 'token expirado debe rechazarse');

// 5. alg=none / alg-confusion -> null
$noneH = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
$noneToken = $noneH . '.' . $p . '.';
assert(SessionService::validateToken($noneToken) === null, 'alg=none debe rechazarse');

$rsaH = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
$confused = $rsaH . '.' . $p . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', "$rsaH.$p", $secret, true)), '+/', '-_'), '=');
assert(SessionService::validateToken($confused) === null, 'alg-confusion (RS256 con clave HMAC) debe rechazarse');

// 6. Clave distinta -> null
$otherKey = JWT::encode(['sub' => 1, 'exp' => $now + 3600], str_repeat('x', 36), 'HS256');
assert(SessionService::validateToken($otherKey) === null, 'clave distinta debe rechazarse');

// --- PanelAuth: misma migracion, token casero previo debe seguir validando ---
use App\Features\Panel\Domain\PanelAuth;
$panelToken = PanelAuth::issueToken();
$_COOKIE['usgar_panel'] = $panelToken;
assert(PanelAuth::isAuthenticated() === true, 'cookie panel nueva debe autenticar');

// Token panel legacy (implementacion casera anterior: b64 manual + claim role=panel)
$legacyPanelH = rtrim(strtr(base64_encode('{"alg":"HS256","typ":"JWT"}'), '+/', '-_'), '=');
$legacyPanelP = rtrim(strtr(base64_encode(json_encode(['role' => 'panel', 'iat' => $now, 'exp' => $now + 3600])), '+/', '-_'), '=');
$legacyPanel = $legacyPanelH . '.' . $legacyPanelP . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', "$legacyPanelH.$legacyPanelP", $secret, true)), '+/', '-_'), '=');
$_COOKIE['usgar_panel'] = $legacyPanel;
assert(PanelAuth::isAuthenticated() === true, 'token panel legacy (casero) debe seguir autenticando');

// Token panel con rol distinto -> rechazado
$guestRole = JWT::encode(['role' => 'guest', 'iat' => $now, 'exp' => $now + 3600], $secret, 'HS256');
$_COOKIE['usgar_panel'] = $guestRole;
assert(PanelAuth::isAuthenticated() === false, 'claim role != panel debe rechazarse');

echo "JWT migration check: 10/10 PASS (nuevo, legacy, tampered, expirado, alg=none, alg-confusion, clave ajena, panel nuevo, panel legacy, panel role)\n";
