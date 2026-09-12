<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\HttpException;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase {
    public function testMissingCredentialsUsesServiceUnavailableContract(): void {
        $exception = HttpException::missingCredentials();

        self::assertSame(503, $exception->getStatusCode());
        self::assertSame('MISSING_CREDENTIALS', $exception->getErrorCode());
    }
}
