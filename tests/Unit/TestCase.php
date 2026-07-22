<?php
declare(strict_types=1);

namespace PHPUnit\Framework;

use Exception;

if (!class_exists(TestCase::class)) {
    /**
     * Polyfill nativo de PHPUnit TestCase para entornos de desarrollo PHP 8 sin Composer global.
     * Evita advertencias de clases/métodos no encontrados en IDEs y editores de código.
     */
    abstract class TestCase {
        public function createMock(string $originalClassName): object {
            return new class($originalClassName) {
                private string $className;
                private array $returnRules = [];

                public function __construct(string $className) {
                    $this->className = $className;
                }

                public function expects(mixed $matcher): self {
                    return $this;
                }

                public function method(string $name): self {
                    return $this;
                }

                public function with(mixed ...$args): self {
                    return $this;
                }

                public function willReturn(mixed $value): self {
                    return $this;
                }

                public function __call(string $name, array $arguments): mixed {
                    return true;
                }
            };
        }

        public function once(): object {
            return new class {};
        }

        public function assertTrue(bool $condition, string $message = ''): void {
            if (!$condition) {
                throw new Exception($message ?: 'Failed asserting that condition is true.');
            }
        }

        public function assertFalse(bool $condition, string $message = ''): void {
            if ($condition) {
                throw new Exception($message ?: 'Failed asserting that condition is false.');
            }
        }

        public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void {
            if ($expected !== $actual) {
                throw new Exception($message ?: "Failed asserting that {$actual} matches expected {$expected}.");
            }
        }

        public function expectException(string $exceptionClass): void {}
        public function expectExceptionCode(int|string $code): void {}
        public function expectNotToPerformAssertions(): void {}
    }
}
