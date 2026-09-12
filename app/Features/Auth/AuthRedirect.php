<?php
declare(strict_types=1);

namespace App\Features\Auth;

/** Normalizes redirects to local paths used by authentication flows. */
final class AuthRedirect {
    public static function safePath(mixed $candidate, string $fallback): string {
        if (!is_string($candidate)) {
            return $fallback;
        }

        $candidate = trim($candidate);
        if ($candidate === ''
            || !str_starts_with($candidate, '/')
            || str_starts_with($candidate, '//')
            || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
        ) {
            return $fallback;
        }

        return $candidate;
    }
}
