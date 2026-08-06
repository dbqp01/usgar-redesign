<?php
declare(strict_types=1);

namespace App\Test\Fixtures;

/**
 * Fixtures FIRMADOS de webhook para la Wave 3 (todo 13/14/16).
 *
 * MANDATO r10 + estrategia del plan: el handler de webhook se prueba con el
 * payload oficial (`{"type":"payment","data":{"id":...}}`) y firma HMAC-SHA256
 * REAL, generada con el MISMO algoritmo que el SDK/adaptador usa para validar:
 *   manifest = "id:{dataId};request-id:{requestId};ts:{ts};"  (pares ausentes omitidos)
 *   v1 = hash_hmac('sha256', manifest, secret)
 *   x-signature = "ts={ts},v1={v1}"
 *
 * El ts va en MILISEGUNDOS: la doc MP (search_documentation "webhooks
 * x-signature timestamp", es/MPE) declara "El valor para el prefijo ts es el
 * timestamp (en milisegundos) de la notificacion" y el WebhookSignatureValidator
 * del SDK compara ts contra now() en ms (verificado empiricamente en W3).
 */
final class W3WebhookFixtures {
    public const TEST_SECRET = 'test-webhook-secret';

    /**
     * Construye el header x-signature con HMAC real (mismo algoritmo del
     * SDK WebhookSignatureValidator::buildManifest).
     *
     * @param int|null $tsMs Timestamp en ms; null = ahora.
     */
    public static function signatureHeader(string $dataId, ?string $requestId, ?int $tsMs = null, string $secret = self::TEST_SECRET): string {
        $ts = $tsMs ?? (int) (microtime(true) * 1000);

        $parts = [];
        if ($dataId !== '') {
            $parts[] = 'id:' . $dataId;
        }
        if ($requestId !== null && $requestId !== '') {
            $parts[] = 'request-id:' . $requestId;
        }
        $parts[] = 'ts:' . $ts;

        $manifest = implode(';', $parts) . ';';
        $v1 = hash_hmac('sha256', $manifest, $secret);
        return 'ts=' . $ts . ',v1=' . $v1;
    }
}
