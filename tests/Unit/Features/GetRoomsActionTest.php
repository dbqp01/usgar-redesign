<?php
declare(strict_types=1);

namespace App\Test\Unit\Features;

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/../TestCase.php';
}

use PHPUnit\Framework\TestCase;
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Shared\Ports\PmsPortInterface;

/**
 * Pruebas unitarias para la clase-acción ADR GetRoomsAction.
 */
final class GetRoomsActionTest extends TestCase {
    public function testGetRoomsActionInstantiation(): void {
        /** @var PmsPortInterface $pmsMock */
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $action = new GetRoomsAction($pmsMock);
        $this->assertTrue($action instanceof GetRoomsAction);
    }
}
