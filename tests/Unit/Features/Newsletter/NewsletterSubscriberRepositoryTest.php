<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Newsletter;

use App\Features\Newsletter\Domain\NewsletterSubscriberRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * SQL de la tabla newsletter_subscribers: evidencia de consentimiento,
 * auto-migracion de la tabla existente y transiciones de estado.
 */
final class NewsletterSubscriberRepositoryTest extends TestCase {
    /** @var list<string> */
    private array $executed = [];
    /** @var list<array{0:string,1:array<string,mixed>}> */
    private array $prepared = [];
    /** @var array<string,mixed>|false */
    private array|false $fetchRow = false;

    private function buildRepo(bool $columnsExist = true): NewsletterSubscriberRepository {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($columnsExist): PDOStatement {
            $stmt = $this->createMock(PDOStatement::class);

            if (str_contains($sql, 'information_schema.COLUMNS')) {
                $stmt->method('fetchColumn')->willReturn($columnsExist ? 1 : 0);
                return $stmt;
            }

            $this->prepared[] = [$sql, []];
            $index = count($this->prepared) - 1;
            $stmt->method('execute')->willReturnCallback(function (array $params = []) use ($index): bool {
                $this->prepared[$index][1] = $params;
                return true;
            });
            $stmt->method('fetch')->willReturnCallback(fn (): array|false => $this->fetchRow);
            $stmt->method('rowCount')->willReturn(1);
            return $stmt;
        });

        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->executed[] = $sql;
            return 1;
        });

        $pdo->method('query')->willReturnCallback(function (): PDOStatement {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetchColumn')->willReturn(1000);
            return $stmt;
        });

        return new NewsletterSubscriberRepository($pdo);
    }

    private function assertExecutedContains(string $needle): void {
        foreach ($this->executed as $sql) {
            if (str_contains($sql, $needle)) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail("No se ejecuto SQL con '{$needle}'. SQL: " . json_encode($this->executed));
    }

    private function assertPreparedContains(string $needle): void {
        foreach ($this->prepared as [$sql]) {
            if (str_contains($sql, $needle)) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail("No se preparo SQL con '{$needle}'. SQL: " . json_encode(array_column($this->prepared, 0)));
    }

    public function testSchemaCreatesTableWithConsentColumnsForFreshInstall(): void {
        $repo = $this->buildRepo(true);

        $repo->ensureSchema();

        $create = implode("\n", $this->executed);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS newsletter_subscribers', $create);
        foreach (['user_agent', 'status', 'consent_at', 'consent_version', 'confirmed_at', 'unsubscribed_at'] as $column) {
            self::assertStringContainsString($column, $create);
        }
        self::assertStringContainsString("status VARCHAR(16) NOT NULL DEFAULT 'pending'", $create);
    }

    public function testSchemaAddsMissingColumnsToTheExistingTable(): void {
        $repo = $this->buildRepo(false);

        $repo->ensureSchema();

        self::assertExecutedContains('ALTER TABLE newsletter_subscribers ADD COLUMN status');
        self::assertExecutedContains('ADD COLUMN consent_at');
        self::assertExecutedContains('ADD COLUMN consent_version');
        self::assertExecutedContains('ADD COLUMN confirmed_at');
        self::assertExecutedContains('ADD COLUMN unsubscribed_at');
        self::assertExecutedContains('ADD COLUMN user_agent');
    }

    public function testLegacyRowsAreGrandfatheredAsConfirmedWithoutInventingConsentTimestamps(): void {
        $repo = $this->buildRepo(false);

        $repo->ensureSchema();

        $legacy = null;
        foreach ($this->executed as $sql) {
            if (str_contains($sql, 'UPDATE newsletter_subscribers')) {
                $legacy = $sql;
            }
        }
        self::assertNotNull($legacy, 'La migracion debe preservar a los suscriptores existentes. SQL: ' . json_encode($this->executed));
        self::assertStringContainsString("status = 'confirmed'", (string)$legacy);
        self::assertStringContainsString("consent_version = 'legacy'", (string)$legacy);
        self::assertStringContainsString('AND consent_at IS NULL', (string)$legacy);
        self::assertStringNotContainsString('consent_at =', (string)$legacy, 'No se inventa una fecha de consentimiento.');
    }

    public function testExistingColumnsDoNotTriggerAlterStatements(): void {
        $repo = $this->buildRepo(true);

        $repo->ensureSchema();

        foreach ($this->executed as $sql) {
            self::assertStringNotContainsString('ALTER TABLE', $sql);
            self::assertStringNotContainsString('UPDATE newsletter_subscribers', $sql);
        }
    }

    public function testUpsertPendingStoresConsentEvidenceAndResetsState(): void {
        $repo = $this->buildRepo(true);

        $repo->upsertPending('guest@example.com', '203.0.113.7', 'en', 'Mozilla/5.0', '2026-10-29 18:00:00');

        $this->assertPreparedContains('INSERT INTO newsletter_subscribers');
        $this->assertPreparedContains('ON DUPLICATE KEY UPDATE');

        $params = null;
        foreach ($this->prepared as [$sql, $bound]) {
            if (str_contains($sql, 'INSERT INTO newsletter_subscribers')) {
                $params = $bound;
            }
        }
        self::assertIsArray($params);
        self::assertSame('guest@example.com', $params[':email']);
        self::assertSame('203.0.113.7', $params[':ip']);
        self::assertSame('web', $params[':source']);
        self::assertSame('en', $params[':locale']);
        self::assertSame('Mozilla/5.0', $params[':user_agent']);
        self::assertSame('pending', $params[':status']);
        self::assertSame('2026-10-29 18:00:00', $params[':consent_at']);
        self::assertSame(NewsletterSubscriberRepository::CONSENT_VERSION, $params[':consent_version']);
    }

    public function testFindByEmailReturnsNullWhenAbsent(): void {
        $repo = $this->buildRepo(true);
        $this->fetchRow = false;

        self::assertNull($repo->findByEmail('nobody@example.com'));
    }

    public function testFindByEmailReturnsStoredStatusAndLocale(): void {
        $repo = $this->buildRepo(true);
        $this->fetchRow = ['id' => 7, 'email' => 'guest@example.com', 'locale' => 'pt', 'status' => 'confirmed'];

        self::assertSame('confirmed', $repo->findByEmail('guest@example.com')['status']);
    }

    public function testMarkConfirmedOnlyTransitionsPendingRows(): void {
        $repo = $this->buildRepo(true);

        $repo->markConfirmed('guest@example.com', '2026-10-29 18:05:00');

        $this->assertPreparedContains("WHERE email = :email AND status = 'pending'");
        $this->assertPreparedContains('confirmed_at = :confirmed_at');
    }

    public function testMarkUnsubscribedStoresStatusAndTimestamp(): void {
        $repo = $this->buildRepo(true);

        $repo->markUnsubscribed('guest@example.com', '2026-10-29 18:10:00');

        $this->assertPreparedContains('SET status = :status, unsubscribed_at = :unsubscribed_at');
        $this->assertPreparedContains('WHERE email = :email');
    }
}
