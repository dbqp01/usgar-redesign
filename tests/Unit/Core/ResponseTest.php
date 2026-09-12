<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Response;
use App\Core\HttpException;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase {
    public function testJsonSerializationFailureUsesErrorEnvelope(): void {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        ob_start();
        Response::json(['invalid' => $resource], 200);
        $payload = json_decode((string)ob_get_clean(), true);
        fclose($resource);

        self::assertSame([
            'success' => false,
            'error' => [
                'code' => 'SERIALIZATION_ERROR',
                'message' => 'Internal serialization error',
            ],
        ], $payload);
    }

   public function testAsyncSerializationFailureUsesErrorEnvelope(): void {
       $resource = fopen('php://memory', 'rb');
       self::assertIsResource($resource);

       ob_start();
       Response::jsonAsync(['invalid' => $resource], 200);
       $payload = json_decode((string)ob_get_clean(), true);
       fclose($resource);

       self::assertSame([
           'success' => false,
           'error' => [
               'code' => 'SERIALIZATION_ERROR',
               'message' => 'Internal serialization error',
           ],
       ], $payload);
       self::assertSame(500, http_response_code());
   }

    public function testFromHttpExceptionPreservesStatusCodeAndDetails(): void {
        ob_start();
        Response::fromHttpException(new HttpException('No permitido', 403, 'FORBIDDEN', ['reason' => 'csrf']));
        $payload = json_decode((string)ob_get_clean(), true);

        self::assertSame([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'No permitido',
                'details' => ['reason' => 'csrf'],
            ],
        ], $payload);
    }
}
