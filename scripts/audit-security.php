<?php
declare(strict_types=1);

// scripts/audit-security.php - Scan PHP backend for basic security patterns

$targetDir = __DIR__ . '/../src';
$issuesFound = 0;
$report = [];

function scanDirectory(string $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            scanFile($file->getPathname());
        }
    }
}

function scanFile(string $filePath) {
    global $report, $issuesFound;
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);
    $fileName = basename($filePath);
    $relativePath = str_replace(realpath(__DIR__ . '/../'), '', realpath($filePath));

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        // 1. Check for CORS wildcards
        if (preg_match('/Access-Control-Allow-Origin\s*,\s*[\'"]\*[\'"]/', $line) || preg_match('/Allow-Origin:\s*\*/', $line)) {
            $report[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'severity' => 'MEDIUM',
                'description' => 'CORS wildcard (*) detected. Restrict allowed origins to trusted domains.',
                'code' => trim($line)
            ];
            $issuesFound++;
        }

        // 2. Check for potentially unsafe SQL execution
        if (preg_match('/\$stmt\s*=\s*\$pdo->(query|prepare|exec)\s*\(\s*["\'].*?\$[a-zA-Z0-9_]+.*?["\']\s*\)/i', $line)) {
            // Check if it is a prepared statement that interpolates variables directly instead of using parameters
            $report[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'severity' => 'CRITICAL',
                'description' => 'Potential SQL injection: raw variable interpolation detected in PDO query/prepare/exec. Use placeholders instead.',
                'code' => trim($line)
            ];
            $issuesFound++;
        }

        // 3. Check for dangerous command executions
        if (preg_match('/\b(eval|exec|shell_exec|system|passthru)\b\s*\(/i', $line)) {
            $report[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'severity' => 'CRITICAL',
                'description' => 'Dangerous PHP function execution detected. Avoid executing raw commands from user input.',
                'code' => trim($line)
            ];
            $issuesFound++;
        }

        // 4. Check for webhook mock bypass in production code
        if (preg_match('/\$mock\s*=\s*.*?["\']mock["\']\s*===\s*["\']true["\']/i', $line)) {
            $report[] = [
                'file' => $relativePath,
                'line' => $lineNumber,
                'severity' => 'HIGH',
                'description' => 'Mock mode activation parameter detected in webhook. Ensure this is disabled or locked to local/dev environment.',
                'code' => trim($line)
            ];
            $issuesFound++;
        }
    }
}

echo "Starting static security scan for: $targetDir\n";
scanDirectory($targetDir);

$outputFile = __DIR__ . '/../dist/audit-screenshots/security-audit-report.md';
if (!file_exists(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0777, true);
}

$markdown = "# Static Security Scan Report\n";
$markdown .= "Generated on: " . date('Y-m-d H:i:s') . "\n";
$markdown .= "Issues found: " . $issuesFound . "\n\n";

if ($issuesFound === 0) {
    $markdown .= "## ✅ Clean Scan\nNo critical security vulnerabilities or patterns were detected in the backend PHP scripts.\n";
} else {
    $markdown .= "## 🚨 Vulnerabilities and Security Concerns\n\n";
    $markdown .= "| File | Line | Severity | Description | Code Snippet |\n";
    $markdown .= "|------|------|----------|-------------|--------------|\n";
    foreach ($report as $issue) {
        $markdown .= "| " . $issue['file'] . " | " . $issue['line'] . " | **" . $issue['severity'] . "** | " . $issue['description'] . " | `" . htmlspecialchars($issue['code']) . "` |\n";
    }
}

file_put_contents($outputFile, $markdown);
echo "Security scan complete! Report saved to: " . realpath($outputFile) . "\n";
?>
