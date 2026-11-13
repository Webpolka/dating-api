<?php
/**
 * fix_base_controller.php
 *
 * Добавляет недостающий `use App\Http\Controllers\Controller;` во все .php файлы
 * в app/Http/Controllers/, где есть `extends Controller` (короткое имя без \),
 * но нет явного use на этот класс.
 *
 * Причина: после того как fix_psr4.php углубил namespace файлов (например с
 * App\Http\Controllers на App\Http\Controllers\Api\V1\Admin), выражение
 * `extends Controller` перестало резолвиться в базовый Laravel-контроллер —
 * PHP ищет его в текущем namespace и не находит.
 *
 * Запуск из корня проекта:
 *   php fix_base_controller.php
 *
 * Перед запуском: git add -A && git commit -m "backup before base controller fix"
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$root = realpath(getcwd());
$ctrlDir = $root . '/app/Http/Controllers';

if (!is_dir($ctrlDir)) {
    fwrite(STDERR, "app/Http/Controllers not found — run me from project root.\n");
    exit(1);
}

// Базовый контроллер Laravel (можно переопределить, если у тебя свой базовый).
// Если у тебя в проекте есть App\Http\Controllers\Controller — он и будет использован.
$baseFqcn = 'App\\Http\\Controllers\\Controller';

$files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ctrlDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') $files[] = $f->getPathname();
}

$fixed = 0;
$skipped = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);

    // сам базовый Controller — пропускаем
    if (preg_match('/^\s*(?:abstract\s+|final\s+)*class\s+Controller\b/m', $content)) {
        continue;
    }

    // есть ли extends Controller (имя без ведущего \, без ::, без продолжения)?
    // соответствует "extends Controller" или "extends Controller\n" или "extends Controller "
    if (!preg_match('/\bextends\s+Controller(?![A-Za-z0-9_\\\\:])/', $content)) {
        continue;
    }

    // уже есть use App\Http\Controllers\Controller; ?
    if (preg_match('/^\s*use\s+App\\\\Http\\\\Controllers\\\\Controller\s*;/m', $content)) {
        $skipped++;
        continue;
    }

    // найдём место для вставки use — сразу после строки `namespace ...;`
    if (!preg_match('/^(\s*namespace\s+[^\r\n]+;\s*\r?\n)/m', $content, $m, PREG_OFFSET_CAPTURE)) {
        // файла без namespace не трогаем
        echo "  SKIP (no namespace): " . substr($file, strlen($root) + 1) . "\n";
        continue;
    }

    $insertPos = $m[1][1] + strlen($m[1][0]);
    $useLine  = "use App\\Http\\Controllers\\Controller;\n";

    $new = substr($content, 0, $insertPos) . $useLine . substr($content, $insertPos);
    file_put_contents($file, $new);

    $fixed++;
    echo "  FIXED: " . substr($file, strlen($root) + 1) . "\n";
}

echo "\nDone.\n";
echo "  Files patched: $fixed\n";
echo "  Files already had the use: $skipped\n";
if ($fixed > 0) {
    echo "\nNext:\n";
    echo "  composer dump-autoload\n";
    echo "  php artisan optimize:clear\n";
    echo "  — retry the failing endpoints.\n";
} 