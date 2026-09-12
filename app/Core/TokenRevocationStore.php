<?php
declare(strict_types=1);

namespace App\Core;

/** File-backed deny list for short-lived JWTs. */
final class TokenRevocationStore
{
    private static ?string $directory = null;
    private static ?bool $storageHealthy = null;

    public static function revoke(string $jti, int $expiresAt, string $scope): bool
    {
        if ($jti === '' || $expiresAt <= time()) {
            return $jti !== '';
        }

        $directory = self::directory();
        if (!self::ensureStorage()) {
            Logger::warning('TokenRevocationStore: could not create revocation directory.');
            return false;
        }

        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $scope . ':' . $jti) . '.revoked';
        if (@file_put_contents($path, (string)$expiresAt, LOCK_EX) === false) {
            self::$storageHealthy = false;
            Logger::warning('TokenRevocationStore: could not persist token revocation.');
            return false;
        }

        return true;
    }

    public static function isRevoked(string $jti, string $scope): bool
    {
        if ($jti === '') {
            return true;
        }

        // Authentication fails closed when the deny-list storage cannot be inspected.
        if (!self::ensureStorage()) {
            Logger::error('TokenRevocationStore: revocation storage unavailable; rejecting token.');
            return true;
        }

        // The health flag is cached for the normal path, but the runtime
        // directory can disappear after it was checked (deploy, mount or
        // manual cleanup). Re-check it before accepting a token so storage
        // loss never turns into a fail-open authentication decision.
        $directory = self::directory();
        if (!is_dir($directory) || !is_readable($directory)) {
            Logger::error('TokenRevocationStore: revocation directory became unavailable; rejecting token.');
            return true;
        }

        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $scope . ':' . $jti) . '.revoked';
        $expiresAt = @file_get_contents($path);
        if ($expiresAt === false) {
            if (file_exists($path)) {
                Logger::error('TokenRevocationStore: revoked-token record is unreadable; rejecting token.');
                return true;
            }
            return false;
        }

        $expiresAtValue = trim($expiresAt);
        if ($expiresAtValue === '' || !ctype_digit($expiresAtValue)) {
            Logger::error('TokenRevocationStore: revoked-token record is malformed; rejecting token.');
            return true;
        }

        if ((int)$expiresAtValue <= time()) {
            @unlink($path);
            return false;
        }

        return true;
    }

    /** @internal Only tests should override the runtime directory. */
    public static function setDirectoryForTesting(?string $directory): void
    {
        self::$directory = $directory;
        self::$storageHealthy = null;
    }

    private static function ensureStorage(): bool
    {
        if (self::$storageHealthy !== null) {
            return self::$storageHealthy;
        }

        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return self::$storageHealthy = false;
        }

        return self::$storageHealthy = is_readable($directory) && is_writable($directory);
    }

    private static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $envPath = Config::loadedEnvPath();
        $runtimeRoot = $envPath !== null ? dirname($envPath) : self::deploymentRoot();
        return $runtimeRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'revoked-tokens';
    }

    private static function deploymentRoot(): string
    {
        $probe = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($probe === '') {
            $probe = dirname(__DIR__, 2);
        }

        $probe = realpath($probe) ?: $probe;
        while ($probe !== dirname($probe)) {
            if (strcasecmp(basename($probe), 'public_html') === 0) {
                return dirname($probe);
            }
            $probe = dirname($probe);
        }

        return dirname(__DIR__, 2);
    }
}
