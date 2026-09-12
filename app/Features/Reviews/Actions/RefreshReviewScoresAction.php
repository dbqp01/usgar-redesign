<?php
declare(strict_types=1);

namespace App\Features\Reviews\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;

/**
 * Accion ADR: POST /api/cron/refresh-reviews (cron diario, vía
 * `php public/index.php /api/cron/refresh-reviews`).
 *
 * Actualiza los scores de reseñas de Booking/KAYAK/Expedia desde sus páginas
 * públicas. Fallback por fuente: si el fetch falla o no se parsea el score,
 * se conserva el valor anterior del JSON (nunca se degrada).
 * Extracción inicial verificada 2026-08-15: Booking 8.7/414, KAYAK 8.7/683,
 * Expedia 8.6. Nota: Expedia puede responder 429 por rate-limit (anti-bot);
 * en ese caso se conserva el valor previo.
 */
class RefreshReviewScoresAction {
    /** @var array<string, true> */
    private const ALLOWED_SOURCE_HOSTS = [
        'booking.com' => true,
        'kayak.com' => true,
        'expedia.com' => true,
    ];
    public function __invoke(Request $request): void {
        if (PHP_SAPI !== 'cli') {
            $secret = Config::get('CRON_SECRET');
            $provided = (string)($request->getHeader('x-cron-secret') ?? '');
            if ($secret === null || $secret === '') {
                Response::error('Cron secret non-configured.', 500);
                return;
            }
            if ($provided === '' || !hash_equals($secret, $provided)) {
                Response::unauthorized('Invalid cron secret token.');
                return;
            }
        }
        $file = GetReviewScoresAction::scoresFile();
        $prev = [];
        if (file_exists($file)) {
            $prev = json_decode((string) file_get_contents($file), true) ?: [];
        }
        $prevScores = $prev['scores'] ?? [];

        $sources = [
            'booking' => [
                'url' => (string) Config::get('REVIEW_BOOKING_URL', 'https://www.booking.com/hotel/pe/chavin-imperio-del-sol.html'),
                'parse' => static fn (string $html): ?array => self::parseBooking($html),
            ],
            'kayak' => [
                'url' => (string) Config::get('REVIEW_KAYAK_URL', 'https://www.kayak.com/Cusco-Hotels-Hotel-Chavin-Imperio-del-Sol.2938418.ksp'),
                'parse' => static fn (string $html): ?array => self::parseKayak($html),
            ],
            'expedia' => [
                'url' => (string) Config::get('REVIEW_EXPEDIA_URL', 'https://www.expedia.com/Cusco-Hotels-Hotel-Chavin-Imperio-Del-Sol.h22328886.Hotel-Information'),
                'parse' => static fn (string $html): ?array => self::parseExpedia($html),
            ],
        ];

        $scores = [];
        foreach ($sources as $key => $src) {
            $html = self::fetch($src['url']);
            $parsed = $html !== null ? $src['parse']($html) : null;
            if ($parsed !== null && $parsed['score'] > 0) {
                $scores[$key] = $parsed;
            } elseif (isset($prevScores[$key])) {
                $scores[$key] = $prevScores[$key]; // fallback al valor anterior
            }
        }

        $dir = dirname($file);
        $payload = json_encode(['scores' => $scores, 'updatedAt' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw HttpException::internal('No se pudo crear el almacenamiento de reseñas.');
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $payload, LOCK_EX) !== strlen($payload) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw HttpException::internal('No se pudo guardar el score de reseñas.');
        }

        Response::json(['success' => true, 'scores' => $scores, 'updatedAt' => date('c')]);
    }

    /** Fetch con User-Agent de navegador; null si falla o no hay body. */
    private static function fetch(string $url): ?string {
        if (!self::isAllowedSourceUrl($url)) {
            Logger::warning('RefreshReviewScoresAction: source URL rejected by allowlist: ' . $url);
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9', 'Accept: text/html'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || !is_string($body) || $code >= 400) {
            return null;
        }
        return $body;
    }

    /** Validate configured review URLs before any network request. */
    private static function isAllowedSourceUrl(string $url): bool {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !empty($parts['user']) || !empty($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            return false;
        }
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if ($host === '') return false;
        $allowedHost = false;
        foreach (array_keys(self::ALLOWED_SOURCE_HOSTS) as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                $allowedHost = true;
                break;
            }
        }
        if (!$allowedHost) return false;
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || $records === []) return false;
        foreach ($records as $record) {
            $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{score: float, count: int|null}|null
     */
    private static function parseBooking(string $html): ?array {
        // JSON-LD aggregateRating (formato robusto) o texto "Scored 8.7".
        if (preg_match('/"ratingValue"\s*:\s*"?([0-9]+\.[0-9])"?/', $html, $m)) {
            $score = (float) $m[1];
        } elseif (preg_match('/Scored\s+([0-9]+\.[0-9])/i', $html, $m)) {
            $score = (float) $m[1];
        } else {
            return null;
        }
        $count = null;
        if (preg_match('/"reviewCount"\s*:\s*"?([0-9,]+)"?/', $html, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        } elseif (preg_match('/([0-9,]+)\s+reviews/i', $html, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        }
        return ['score' => $score, 'count' => $count];
    }

    /**
     * @return array{score: float, count: int|null}|null
     */
    private static function parseKayak(string $html): ?array {
        if (!preg_match('/([0-9]+\.[0-9])[^0-9]{0,60}Very good/i', $html, $m)) {
            return null;
        }
        $count = null;
        if (preg_match('/based on\s+([0-9,]+)\s+reviews/i', $html, $m2)) {
            $count = (int) str_replace(',', '', $m2[1]);
        }
        return ['score' => (float) $m[1], 'count' => $count];
    }

    /**
     * @return array{score: float, count: int|null}|null
     */
    private static function parseExpedia(string $html): ?array {
        if (preg_match('/"ratingValue"\s*:\s*"?([0-9]+\.[0-9])"?/', $html, $m)) {
            $score = (float) $m[1];
        } elseif (preg_match('/([0-9]+\.[0-9])\s*out of 10/i', $html, $m)) {
            $score = (float) $m[1];
        } else {
            return null;
        }
        $count = null;
        if (preg_match('/"reviewCount"\s*:\s*"?([0-9,]+)"?/', $html, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        }
        return ['score' => $score, 'count' => $count];
    }
}
