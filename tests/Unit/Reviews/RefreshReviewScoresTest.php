<?php
declare(strict_types=1);

namespace Tests\Unit\Reviews;

use App\Features\Reviews\Actions\RefreshReviewScoresAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Parsers de scores de plataformas (RefreshReviewScoresAction). Sin red:
 * muestras de HTML estáticas; si una plataforma cambia su markup, este test
 * lo detecta y el cron conserva el valor anterior (fallback por diseño).
 */
final class RefreshReviewScoresTest extends TestCase {
    /** @return array{score: float, count: int|null}|null */
    private function parse(string $method, string $html): ?array {
        $ref = new ReflectionMethod(RefreshReviewScoresAction::class, $method);
        return $ref->invoke(null, $html);
    }

    public function testParseBookingJsonLd(): void {
        $html = '<script type="application/ld+json">{"@type":"Hotel","aggregateRating":{"@type":"AggregateRating","ratingValue":"8.7","reviewCount":"414"}}</script>';
        $this->assertSame(['score' => 8.7, 'count' => 414], $this->parse('parseBooking', $html));
    }

    public function testParseBookingScoredFallback(): void {
        $html = 'Scored 8.7 Rated excellent Excellent · 414 reviews';
        $this->assertSame(['score' => 8.7, 'count' => 414], $this->parse('parseBooking', $html));
    }

    public function testParseKayak(): void {
        $html = '<div><span>8.7</span></div><div><div>Very good</div></div>based on 683 reviews';
        $this->assertSame(['score' => 8.7, 'count' => 683], $this->parse('parseKayak', $html));
    }

    public function testParseExpediaOutOf10(): void {
        $html = 'Rated 8.6 out of 10 Excellent hotels';
        $this->assertSame(['score' => 8.6, 'count' => null], $this->parse('parseExpedia', $html));
    }

    public function testParseReturnsNullOnUnparseableHtml(): void {
        $this->assertNull($this->parse('parseKayak', '<html><body>challenge page, no score</body></html>'));
        $this->assertNull($this->parse('parseBooking', '<html>captcha</html>'));
    }
}
