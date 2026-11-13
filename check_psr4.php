<?php
/**
 * check_psr4.php — единый скрипт: проверяет и чинит соответствие namespace ↔ пути по PSR-4.
 *
 * Запускать из корня проекта (рядом с composer.json):
 *
 *   php check_psr4.php              — проверка (выводит проблемы, ничего не меняет)
 *   php check_psr4.php fix          — правит `namespace ...;` внутри файлов в app/
 *   php check_psr4.php fix-uses     — обновляет use/FQCN во всём проекте под новые namespace
 *   php check_psr4.php fix-all      — делает fix + fix-uses подряд
 *   php check_psr4.php status       — коротко: сколько файлов с расхождением
 *
 * ПЕРЕД fix / fix-all:  git add -A && git commit -m "backup before psr-4 fix"
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$root = realpath(getcwd());
$cmd  = $argv[1] ?? '';

/* ---------- Читаем PSR-4 правило из composer.json ---------- */

$composerJsonPath = $root . '/composer.json';
if (!file_exists($composerJsonPath)) {
    fwrite(STDERR, "composer.json not found — run me from project root.\n");
    exit(1);
}
$composer = json_decode(file_get_contents($composerJsonPath), true);
$psr4 = $composer['autoload']['psr-4'] ?? [];

$rule = null;
foreach ($psr4 as $prefix => $dir) {
    if ($prefix === 'App\\') {
        $rule = ['prefix' => $prefix, 'dir' => trim($dir, '/')];
        break;
    }
}
if (!$rule) {
    foreach ($psr4 as $prefix => $dir) {
        $rule = ['prefix' => $prefix, 'dir' => trim($dir, '/')];
        break;
    }
}
if (!$rule) {
    fwrite(STDERR, "No psr-4 autoload rule found in composer.json.\n");
    exit(1);
}

$appDir = $root . '/' . $rule['dir'];
$prefix = $rule['prefix']; // e.g. "App\\"

/* ---------- Сбор метаданных по всем .php в app/ ---------- */

function collectMappings(string $appDir, string $root, string $prefix, array $rule): array {
    if (!is_dir($appDir)) return [];

    $mappings = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $path = $file->getPathname();
        $content = file_get_contents($path);

        if (!preg_match('/^(\s*namespace\s+)([A-Za-z0-9_\\\\]+)\s*;/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $declaredNs = $m[2][0];
        $offset = $m[2][1];
        $lineNo = substr_count(substr($content, 0, $offset), "\n") + 1;

        // expected namespace из пути файла
        $rel = substr($path, strlen($root) + 1);
        $rel = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rel);
        $dir = dirname($rel);
        $expectedNs = str_replace(DIRECTORY_SEPARATOR, '\\', $dir);
        $expectedNs = preg_replace('/^[^\\\\]+\\\\/', $prefix, $expectedNs);

        // имена классов/интерфейсов/трейтов/энумов в файле
        $classes = [];
        foreach (['class', 'interface', 'enum', 'trait'] as $kw) {
            if (preg_match_all('/^\s*(?:abstract\s+|final\s+|readonly\s+)*' . $kw . '\s+(\w+)/m', $content, $cm)) {
                $classes = array_merge($classes, $cm[1]);
            }
        }
        $classes = array_values(array_unique($classes));

        $mappings[] = [
            'file'       => $path,
            'rel'        => str_replace(DIRECTORY_SEPARATOR, '/', $rel),
            'declaredNs' => $declaredNs,
            'expectedNs' => $expectedNs,
            'line'       => $lineNo,
            'classes'    => $classes,
            'changed'    => ($declaredNs !== $expectedNs),
        ];
    }
    return $mappings;
}

/* ---------- Применение: правим `namespace` внутри файлов ---------- */

function applyNamespaceFixes(array $mappings, bool $dry): int {
    $count = 0;
    foreach ($mappings as $row) {
        if (!$row['changed']) continue;
        $content = file_get_contents($row['file']);
        $new = preg_replace(
            '/^(\s*namespace\s+)[A-Za-z0-9_\\\\]+(\s*;)/m',
            '$1' . $row['expectedNs'] . '$2',
            $content,
            1
        );
        if ($new !== null && $new !== $content) {
            if (!$dry) file_put_contents($row['file'], $new);
            $count++;
            echo sprintf(
                "  %s:%d  %s -> %s\n",
                $row['rel'],
                $row['line'],
                $row['declaredNs'],
                $row['expectedNs']
            );
        }
    }
    return $count;
}

/* ---------- Применение: заменяем use/FQCN во всём проекте ---------- */

function buildReplacements(array $mappings): array {
    $repl = [];
    foreach ($mappings as $row) {
        if (!$row['changed']) continue;
        foreach ($row['classes'] as $cls) {
            $old = $row['declaredNs'] . '\\' . $cls;
            $new = $row['expectedNs'] . '\\' . $cls;
            if ($old !== $new) $repl[$old] = $new;
        }
    }
    // сортировка по длине ключа DESC — чтобы длинные имена заменялись раньше
    // и не портили созвучные подстроки
    uksort($repl, fn($a, $b) => strlen($b) <=> strlen($a));
    return $repl;
}

function applyUseReplacements(array $repl, string $root, bool $dry): int {
    if (!$repl) return 0;

    $dirs = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];
    $files = [];
    foreach ($dirs as $d) {
        $p = $root . '/' . $d;
        if (!is_dir($p)) continue;
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getPathname();
        }
    }

    $total = 0;
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $orig = $content;
        foreach ($repl as $old => $new) {
            // Заменяем только полное FQCN. После имени не должно идти [A-Za-z0-9_\]
            // чтобы не задеть созвучные классы (AdminLogger vs AdminLoggerExtended).
            $pattern = '/\b' . preg_quote($old, '/') . '(?![A-Za-z0-9_\\\\])/';
            $content = preg_replace($pattern, $new, $content);
        }
        if ($content !== $orig) {
            if (!$dry) file_put_contents($file, $content);
            $total++;
            echo "  " . substr($file, strlen($root) + 1) . " (updated)\n";
        }
    }
    return $total;
}

/* ---------- CLI ---------- */

$mappings = collectMappings($appDir, $root, $prefix, $rule);
$changed  = array_filter($mappings, fn($r) => $r['changed']);

switch ($cmd) {
    case '':
    case 'check':
        if (!$changed) {
            echo "OK: all namespaces comply with PSR-4.\n";
            exit(0);
        }
        foreach ($changed as $row) {
            echo sprintf(
                "%s:%d: namespace mismatch: declared \"%s\", expected \"%s\"\n",
                $row['rel'],
                $row['line'],
                $row['declaredNs'],
                $row['expectedNs']
            );
        }
        fwrite(STDERR, sprintf("\n%d problem(s) found.\n", count($changed)));
        break;

    case 'status':
        echo "Files scanned: " . count($mappings) . "\n";
        echo "Files with mismatched namespace: " . count($changed) . "\n";
        break;

    case 'fix':
        if (!$changed) {
            echo "Nothing to fix — all namespaces are already correct.\n";
            exit(0);
        }
        echo "Applying namespace fixes...\n";
        $n = applyNamespaceFixes($mappings, false);
        echo "\nDone. Namespaces updated in $n file(s).\n";
        echo "Next: run `php check_psr4.php fix-uses` to update references,\n";
        echo "      then `composer dump-autoload && php artisan package:discover`.\n";
        break;

    case 'fix-uses':
        $repl = buildReplacements($mappings);
        if (!$repl) {
            echo "Nothing to replace — no namespace changes detected.\n";
            echo "Run `php check_psr4.php fix` first.\n";
            exit(0);
        }
        echo "Replacing use/FQCN references across project...\n";
        echo "Replacements prepared: " . count($repl) . "\n\n";
        $n = applyUseReplacements($repl, $root, false);
        echo "\nDone. use/FQCN references updated in $n file(s).\n";
        echo "Next: `composer dump-autoload && php artisan package:discover && php artisan optimize:clear`.\n";
        break;

    case 'fix-all':
        if (!$changed) {
            echo "Nothing to fix — all namespaces are already correct.\n";
            exit(0);
        }
        echo "=== Step 1/2: fixing namespaces inside files ===\n";
        $n1 = applyNamespaceFixes($mappings, false);
        echo "Namespaces updated in $n1 file(s).\n\n";

        // building replacements from the ORIGINAL mapping (before fix)
        $repl = buildReplacements($mappings);
        echo "=== Step 2/2: updating use/FQCN references ===\n";
        echo "Replacements prepared: " . count($repl) . "\n";
        $n2 = applyUseReplacements($repl, $root, false);
        echo "use/FQCN references updated in $n2 file(s).\n\n";

        echo "=== Final steps ===\n";
        echo "  composer dump-autoload\n";
        echo "  php artisan package:discover\n";
        echo "  php artisan optimize:clear\n";
        echo "  php check_psr4.php            (should print: OK)\n";
        break;

    default:
        fwrite(STDERR, "Unknown command: $cmd\n");
        fwrite(STDERR, "Usage: php check_psr4.php [check|status|fix|fix-uses|fix-all]\n");
        exit(1);
} 