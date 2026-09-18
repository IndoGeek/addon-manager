<?php

// Guards tools/audit-php.php: the static audit must flag every shape of dead
// catch (the bug class it was written for) and stay quiet on correct code.
// A detector that silently stops detecting is worse than none, so the shapes
// are exercised against a fixture written for each one.

$root = dirname(__DIR__, 2);

$fixture = sys_get_temp_dir()
    . '/mi-audit-fixture-'
    . bin2hex(random_bytes(6))
    . '.php';

// Each shape lives in its own namespace so imports cannot leak between them,
// exactly like the audit expects in real files.
file_put_contents($fixture, <<<'PHP'
<?php

namespace AuditFixture\Nested;

use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\Nope;
use RuntimeException;
use Throwable;

final readonly class Fixture
{
    // DEAD: not declared here and not imported.
    public function deadGlobal(): void
    {
        try {
            $this->work();
        } catch (JsonException $exception) {
            $this->swallow($exception);
        }
    }

    // DEAD: both members of the union are unimported globals.
    public function deadUnion(): void
    {
        try {
            $this->work();
        } catch (JsonException | InvalidArgumentException $exception) {
            $this->swallow($exception);
        }
    }

    // DEAD: the class exists, but in another namespace, unimported.
    public function deadForeign(): void
    {
        try {
            $this->work();
        } catch (CatalogVersion $exception) {
            $this->swallow($exception);
        }
    }

    // DEAD: imported extension class with no file on disk.
    public function deadMissingImport(): void
    {
        try {
            $this->work();
        } catch (Nope $exception) {
            $this->swallow($exception);
        }
    }

    // CORRECT: imported global.
    public function correctImport(): void
    {
        try {
            $this->work();
        } catch (RuntimeException $exception) {
            $this->swallow($exception);
        }
    }

    // CORRECT: fully qualified globals.
    public function correctQualified(): void
    {
        try {
            $this->work();
        } catch (\Throwable $exception) {
            $this->swallow($exception);
        }
    }

    // CORRECT: the class declared in this file.
    public function correctLocal(): void
    {
        try {
            $this->work();
        } catch (Fixture $exception) {
            $this->swallow($exception);
        }
    }

    private function work(): void
    {
    }

    private function swallow(object $exception): void
    {
    }
}
PHP);

function pass(string $name): void
{
    echo "PASS: {$name}\n";
}

// @param array<int, string> $arguments @return array{0: string, 1: int}
function runAudit(array $arguments, bool $json = false): array
{
    $command = escapeshellarg(PHP_BINARY);

    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    if ($json) {
        $command .= ' --json';
    }

    $output = [];
    $status = 0;

    exec($command . ' 2>&1', $output, $status);

    return [implode("\n", $output), $status];
}

$binary = $root . '/tools/audit-php.php';

if (!is_file($binary)) {
    throw new RuntimeException('tools/audit-php.php is missing.');
}

try {
    // The fixture is scanned alongside the real tree so the audit can see
    // which namespaces this extension's classes live in.
    [$output, $status] = runAudit([$binary, $root . '/app', $fixture]);

    if ($status !== 1) {
        throw new RuntimeException(
            "Audit must fail on dead catches, exited {$status}: {$output}",
        );
    }

    foreach (
        [
            'JsonException' => 'unqualified global',
            'InvalidArgumentException' => 'the second member of a union',
            'CatalogVersion' => 'a class from another namespace',
            'Nope' => 'an import with no file',
        ] as $name => $description
    ) {
        if (!str_contains($output, $name)) {
            throw new RuntimeException(
                "Audit missed {$description} ({$name}): {$output}",
            );
        }
    }

    pass('every dead catch shape is reported');

    $reportedNames = [];

    if (preg_match_all('/✗ [^\n]+?catch (\w+)/', $output, $reported) > 0) {
        $reportedNames = $reported[1];
    }

    foreach (['RuntimeException', 'Throwable', 'Fixture'] as $name) {
        if (in_array($name, $reportedNames, true)) {
            throw new RuntimeException(
                "Audit flagged correct code ({$name}): {$output}",
            );
        }
    }

    pass('correct catches are left alone');

    // Findings carry a file and a line so they can be clicked through.
    if (
        !str_contains($output, $fixture . ':')
        || preg_match('/' . preg_quote($fixture, '/') . ':\d+ {2}/', $output) !== 1
    ) {
        throw new RuntimeException("Findings must carry file:line: {$output}");
    }

    pass('findings report file and line');

    // The real tree must stay clean — the audit is what keeps it that way.
    [$cleanOutput, $cleanStatus] = runAudit([$binary, $root . '/app']);

    if ($cleanStatus !== 0) {
        throw new RuntimeException(
            "Audit must pass on app/: {$cleanOutput}",
        );
    }

    if (!str_contains($cleanOutput, 'no dead catches')) {
        throw new RuntimeException(
            "Audit must say the tree is clean: {$cleanOutput}",
        );
    }

    pass('the extension tree is clean');

    // Machine-readable output for CI.
    [$jsonText, $jsonStatus] = runAudit(
        [$binary, $root . '/app', $fixture],
        json: true,
    );

    if ($jsonStatus !== 1) {
        throw new RuntimeException('Audit --json must fail on dead catches.');
    }

    $decoded = json_decode($jsonText, true);

    if (!is_array($decoded) || !isset($decoded['findings'])) {
        throw new RuntimeException(
            'Audit --json must emit findings: ' . $jsonText,
        );
    }

    if (count($decoded['findings']) !== 5) {
        throw new RuntimeException(
            'Audit --json must report every finding, got '
                . count($decoded['findings'])
                . ': '
                . $jsonText,
        );
    }

    foreach ($decoded['findings'] as $finding) {
        foreach (['file', 'line', 'kind', 'name', 'detail'] as $key) {
            if (!isset($finding[$key])) {
                throw new RuntimeException("Finding is missing {$key}.");
            }
        }
    }

    if (($decoded['catch_clauses'] ?? 0) < 7) {
        throw new RuntimeException('Audit must count scanned catch clauses.');
    }

    pass('json output is machine readable');
} finally {
    @unlink($fixture);
}

echo "\nAll audit tool tests passed.\n";
