<?php

// Static audit for error-handling bugs that `php -l` and the test suites
// cannot see: catch clauses that can never match, so the error they are
// meant to handle escapes instead.
//
// Three ways a catch clause is dead, all of them silent:
//
//   1. A GLOBAL class referred to by short name in a namespaced file without
//      an import. `catch (Throwable)` then resolves to a class inside the
//      file's own namespace, which does not exist, so the clause never
//      matches. Fully-qualified `catch (\Throwable)` is correct.
//   2. A class that exists in a DIFFERENT namespace of this extension,
//      caught by short name without an import.
//   3. An imported extension class whose file is missing (typo, rename), so
//      the type can never be thrown.
//
// Usage:
//   php tools/audit-php.php [path ...] [--json]
//
// Exit code is 1 when anything is found, so it can gate a commit or CI.

const AUDIT_GLOBAL_CLASSES = [
    'Throwable', 'Exception', 'Error', 'TypeError', 'ValueError',
    'RuntimeException', 'InvalidArgumentException', 'LogicException',
    'DomainException', 'OutOfRangeException', 'OutOfBoundsException',
    'RangeException', 'UnexpectedValueException', 'LengthException',
    'OverflowException', 'UnderflowException', 'BadFunctionCallException',
    'BadMethodCallException', 'JsonException', 'ErrorException',
    'ArgumentCountError', 'ArithmeticError', 'DivisionByZeroError',
    'ZipArchive', 'ArrayObject', 'ArrayIterator', 'Closure', 'Generator',
    'PDO', 'PDOException', 'SplFileObject', 'SplFileInfo', 'DateTime',
    'DateTimeImmutable', 'DateInterval', 'DateTimeZone', 'Stringable',
];

const AUDIT_NAMESPACE_PREFIX =
    'Pterodactyl\\BlueprintFramework\\Extensions\\modpackinstaller\\';

/**
 * @param array<int, string> $paths
 * @return array<int, string>
 */
function auditFiles(array $paths): array
{
    $files = [];

    foreach ($paths as $path) {
        if (is_file($path)) {
            if (str_ends_with($path, '.php')) {
                $files[] = $path;
            }

            continue;
        }

        if (!is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    $files = array_values(array_unique($files));

    sort($files);

    return $files;
}

/**
 * Class/interface/trait/enum names declared in one file.
 *
 * @return array<int, string>
 */
function auditDeclaredClasses(string $source): array
{
    if (
        preg_match_all(
            '/^\s*(?:(?:final|abstract|readonly)\s+)*'
                . '(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m',
            $source,
            $matches,
        ) === 0
    ) {
        return [];
    }

    return $matches[1];
}

/**
 * Every class/interface/trait/enum declared in the scanned tree.
 *
 * @param array<int, string> $files
 * @return array<string, array<int, string>> short name => namespaces
 */
function auditClassIndex(array $files): array
{
    $index = [];

    foreach ($files as $path) {
        $source = @file_get_contents($path);

        if ($source === false) {
            continue;
        }

        if (
            preg_match('/^namespace\s+([^;{]+)[;{]/m', $source, $namespace) !== 1
        ) {
            continue;
        }

        foreach (auditDeclaredClasses($source) as $name) {
            $index[$name][] = trim($namespace[1]);
        }
    }

    return $index;
}

/**
 * Names imported into a file, keyed by the name used in code.
 *
 * @return array<string, string> local name => fully qualified name
 */
function auditImports(string $source): array
{
    if (preg_match_all('/^use\s+([^;]+);/m', $source, $matches) === 0) {
        return [];
    }

    $imports = [];

    foreach ($matches[1] as $clause) {
        $clause = trim($clause);

        // `use function foo;` / `use const FOO;` are not classes.
        if (preg_match('/^(function|const)\s+/i', $clause) === 1) {
            continue;
        }

        // `use Vendor\{A, B as C};` — expand the group.
        $prefix = '';
        $names = [$clause];

        if (preg_match('/^(.+?)\{(.+)\}$/', $clause, $group) === 1) {
            $prefix = rtrim(trim($group[1]), '\\') . '\\';
            $names = array_map('trim', explode(',', $group[2]));
        }

        foreach ($names as $name) {
            foreach (explode(',', $name) as $piece) {
                $piece = trim($piece);

                if ($piece === '') {
                    continue;
                }

                $alias = null;

                if (
                    preg_match(
                        '/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i',
                        $piece,
                        $aliasMatch,
                    ) === 1
                ) {
                    $alias = $aliasMatch[1];
                    $piece = trim(substr($piece, 0, -strlen($aliasMatch[0])));
                }

                $piece = $prefix . ltrim($piece, '\\');
                $short = str_contains($piece, '\\')
                    ? substr($piece, strrpos($piece, '\\') + 1)
                    : $piece;

                $imports[$alias ?? $short] = $piece;
            }
        }
    }

    return $imports;
}

/**
 * @return array<int, array{name: string, offset: int, kind: string}>
 */
function auditReferencedClasses(string $source): array
{
    $references = [];

    if (preg_match_all('/catch\s*\(([^)]*)\)/', $source, $catches, PREG_OFFSET_CAPTURE) > 0) {
        foreach ($catches[1] as [$clause, $clauseOffset]) {
            // Types come before the variable in a catch clause, so dropping
            // `$e` keeps every type's offset valid. Splitting the rest on the
            // pattern also handles `A | B $e` unions in one pass.
            $types = preg_replace('/\$[A-Za-z_][A-Za-z0-9_]*/', '', $clause)
                ?? $clause;

            if (
                preg_match_all(
                    '/(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/',
                    $types,
                    $tokens,
                    PREG_OFFSET_CAPTURE,
                ) === 0
            ) {
                continue;
            }

            foreach ($tokens[1] as [$token, $tokenOffset]) {
                $references[] = [
                    'name' => ltrim($token, '\\'),
                    'offset' => $clauseOffset + $tokenOffset,
                    'kind' => 'catch',
                    'qualified' => str_starts_with($token, '\\'),
                ];
            }
        }
    }

    foreach (
        [
            '/new\s+([A-Za-z_][A-Za-z0-9_]*)/' => 'new',
            '/instanceof\s+([A-Za-z_][A-Za-z0-9_]*)/' => 'instanceof',
        ] as $pattern => $kind
    ) {
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        foreach ($matches[1] as [$name, $offset]) {
            $references[] = [
                'name' => $name,
                'offset' => $offset,
                'kind' => $kind,
                'qualified' => false,
            ];
        }
    }

    return $references;
}

/**
 * @param array<int, string> $files
 * @param array<string, array<int, string>> $classIndex
 * @return array<int, array<string, mixed>>
 */
function auditTree(array $files, array $classIndex): array
{
    $findings = [];
    $skipped = [];

    foreach ($files as $path) {
        $source = @file_get_contents($path);

        if ($source === false) {
            continue;
        }

        // Unqualified names resolve to the file's namespace, so a file that
        // declares none needs no import and is always correct.
        if (preg_match('/^namespace\s+([^;{]+);/m', $source, $namespace) !== 1) {
            continue;
        }

        // Bracketed/multi-namespace files (test harnesses that stub models in
        // one file) resolve names per block; auditing them would be guesswork.
        if (preg_match('/^namespace\s+[^;{]+{/m', $source) === 1) {
            $skipped[] = $path;

            continue;
        }

        $namespace = trim($namespace[1]);
        $imports = auditImports($source);

        // A class declared in this very file is catchable by short name.
        $declaredHere = auditDeclaredClasses($source);

        foreach (auditReferencedClasses($source) as $reference) {
            $name = $reference['name'];

            // Fully qualified references are always correct.
            if ($reference['qualified']) {
                continue;
            }

            // A name that is a namespace prefix, not a class.
            if (substr($source, $reference['offset'] + strlen($name), 1) === '\\') {
                continue;
            }

            // Declared here, or namespaced by this file's own namespace.
            if (
                in_array($name, $declaredHere, true)
                || is_file(dirname($path) . '/' . $name . '.php')
            ) {
                continue;
            }

            $line = substr_count(
                substr($source, 0, $reference['offset']),
                "\n",
            ) + 1;

            if (isset($imports[$name])) {
                // Imported. If it is one of our own classes, the file must
                // exist, or the catch can never match.
                $imported = $imports[$name];

                if (!str_starts_with($imported, AUDIT_NAMESPACE_PREFIX)) {
                    continue;
                }

                $relative = substr($imported, strlen(AUDIT_NAMESPACE_PREFIX));
                $expected = str_replace('\\', '/', $relative) . '.php';

                if (!is_file(dirname(__DIR__) . '/app/' . $expected)) {
                    $findings[] = [
                        'file' => $path,
                        'line' => $line,
                        'kind' => 'missing class',
                        'name' => $name,
                        'detail' => "imports {$imported}, but that file does not exist",
                    ];
                }

                continue;
            }

            if (in_array($name, AUDIT_GLOBAL_CLASSES, true)) {
                $findings[] = [
                    'file' => $path,
                    'line' => $line,
                    'kind' => $reference['kind'],
                    'name' => $name,
                    'detail' => 'global class used by short name — resolves to '
                        . "{$namespace}\\{$name}, which does not exist "
                        . '(add `use ' . $name . ';` or prefix with `\\`)',
                ];

                continue;
            }

            // A class of this name declared elsewhere in the tree: the catch
            // looks right but the type cannot be thrown from here.
            if (isset($classIndex[$name])) {
                $homes = array_values(array_unique($classIndex[$name]));

                $findings[] = [
                    'file' => $path,
                    'line' => $line,
                    'kind' => $reference['kind'],
                    'name' => $name,
                    'detail' => 'not imported — resolves to '
                        . "{$namespace}\\{$name}; the class lives in "
                        . implode(', ', $homes),
                ];
            }
        }
    }

    return [$findings, $skipped];
}

/**
 * @param array<int, array<string, mixed>> $findings
 * @param array<int, string> $skipped
 */
function auditReport(
    array $findings,
    array $skipped,
    int $fileCount,
    int $catchCount,
    bool $json,
): int {
    if ($json) {
        echo json_encode(
            [
                'files_scanned' => $fileCount,
                'catch_clauses' => $catchCount,
                'skipped' => $skipped,
                'findings' => $findings,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";

        return $findings === [] ? 0 : 1;
    }

    $tty = defined('STDOUT') && @stream_isatty(STDOUT);
    $reset = $tty ? "\e[0m" : '';
    $dim = $tty ? "\e[2m" : '';
    $bold = $tty ? "\e[1m" : '';
    $green = $tty ? "\e[38;5;114m" : '';
    $red = $tty ? "\e[38;5;203m" : '';
    $yellow = $tty ? "\e[38;5;179m" : '';

    echo "\n{$bold}Static error-handling audit{$reset}\n";
    echo "{$dim}  {$fileCount} files · {$catchCount} catch clauses scanned";

    if ($skipped !== []) {
        echo ' · ' . count($skipped) . ' multi-namespace file(s) skipped';
    }

    echo "{$reset}\n\n";

    if ($findings === []) {
        echo "{$green}✓{$reset} no dead catches — every caught type resolves\n";
        echo "{$dim}  (unqualified globals, foreign-namespace classes and "
            . "missing imports all checked){$reset}\n";

        return 0;
    }

    foreach ($findings as $finding) {
        echo "{$red}✗{$reset} {$bold}{$finding['file']}:{$finding['line']}{$reset}"
            . "  {$yellow}{$finding['kind']}{$reset} {$bold}{$finding['name']}{$reset}\n";
        echo "    {$finding['detail']}\n";
    }

    echo "\n{$red}{$bold}" . count($findings) . " dead catch(es){$reset}"
        . "{$dim} — a clause that can never match lets the error escape; "
        . "fix the import or qualify the name{$reset}\n";

    return 1;
}

$arguments = array_slice($argv, 1);
$json = in_array('--json', $arguments, true);
$paths = array_values(array_filter(
    $arguments,
    static fn (string $argument): bool => !str_starts_with($argument, '--'),
));

if ($paths === []) {
    $paths = ['app'];
}

$files = auditFiles($paths);

if ($files === []) {
    fwrite(STDERR, "No PHP files found in: " . implode(', ', $paths) . "\n");

    exit(2);
}

[$findings, $skipped] = auditTree($files, auditClassIndex($files));

$catchCount = 0;

foreach ($files as $file) {
    $source = @file_get_contents($file);

    if ($source !== false) {
        $catchCount += preg_match_all('/catch\s*\(/', $source);
    }
}

exit(auditReport($findings, $skipped, count($files), $catchCount, $json));
