<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Shared\Adapters;

use PHPUnit\Framework\TestCase;
use App\Core\Config;
use App\Features\Shared\Adapters\QloAppAdapter;

/**
 * Regresion del HTTP 500 del webservice de QloApps.
 *
 * El PMS valida firstname/lastname con Validate::isName() y un cliente ya
 * guardado con un nombre invalido (p.ej. "E2E") hace que POST /api/bookings
 * lance PrestaShopException, que el dispatcher no captura: 500 sin cuerpo.
 * Todo nombre que entra al PMS pasa por pmsGuestNames().
 */
final class QloAppAdapterPmsNameTest extends TestCase {
    /** Regla exacta del CMS (classes/Validate.php::isName de PrestaShop 1.7). */
    private const PMS_IS_NAME = '/^[^0-9!<>;,?=+()@#"°{}_$%:]*$/u';

    public function testRemovesDigitsAndForbiddenSymbols(): void {
        [$first, $last] = QloAppAdapter::pmsGuestNames('Prueba E2E');

        $this->assertSame('Prueba', $first);
        // "E2E" -> el digito se sustituye por espacio: "E E" (sin digitos ni simbolos).
        $this->assertSame('E E', $last);
        $this->assertValidForPms($first);
        $this->assertValidForPms($last);
    }

    public function testKeepsValidNamesWithAccents(): void {
        [$first, $last] = QloAppAdapter::pmsGuestNames('María José');

        $this->assertSame('María', $first);
        $this->assertSame('José', $last);
        $this->assertValidForPms($first);
        $this->assertValidForPms($last);
    }

    public function testFallsBackToDefaultWhenNameIsEmptyOrFullyStripped(): void {
        $default = (string)Config::get('DEFAULT_GUEST_NAME', 'Guest');

        [$first, $last] = QloAppAdapter::pmsGuestNames('');
        $this->assertSame($default, $first);
        $this->assertSame($default, $last);

        [$first, $last] = QloAppAdapter::pmsGuestNames('1234 5678');
        $this->assertSame($default, $first);
        $this->assertSame($default, $last);
    }

    public function testTruncatesToPmsMaxLength(): void {
        [$first] = QloAppAdapter::pmsGuestNames(str_repeat('a', 40));

        $this->assertSame(32, mb_strlen($first));
        $this->assertValidForPms($first);
    }

    private function assertValidForPms(string $name): void {
        $this->assertMatchesRegularExpression(self::PMS_IS_NAME, $name);
        $this->assertNotSame('', trim($name));
    }
}
