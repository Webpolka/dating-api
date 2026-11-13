<?php

namespace App\Http\Controllers\Admin\V1\System;

use App\Http\Controllers\AdminController;
use App\Models\AdminLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class LogController extends AdminController
{
    private array $levelColors = [
        'ERROR'      => 'bg-red-500/10 text-red-500',
        'WARNING'    => 'bg-yellow-500/10 text-yellow-500',
        'INFO'       => 'bg-blue-500/10 text-blue-500',
        'DEBUG'      => 'bg-gray-500/10 text-gray-500',
        'NOTICE'     => 'bg-purple-500/10 text-purple-500',
        'CRITICAL'   => 'bg-red-700/10 text-red-700',
        'ALERT'      => 'bg-orange-500/10 text-orange-500',
        'EMERGENCY'  => 'bg-red-900/10 text-red-900',
    ];

    /**
     * GET /api/admin/system/logs
     *
     * B2: защита от memory leak. Читаем лог-файл построчно через File::lines(),
     * но ограничиваем кол-во строк в памяти (последние 10000) — этого достаточно
     * для просмотра, и не съедает RAM на больших файлах (40MB+).
     */
    public function index(Request $request): JsonResponse
    {
        $logPath = storage_path('logs/laravel.log');
        $stats = ['total_entries' => 0, 'levels' => []];
        $allLogs = [];
        $logSize = '0 B';

        if (!File::exists($logPath)) {
            return response()->json([
                'logs' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1],
                'stats' => $stats,
                'log_size' => $logSize
            ]);
        }

        $sizeBytes = File::size($logPath);
        $units = ['B', 'KB', 'MB', 'GB'];
        $sizeVal = $sizeBytes;
        $i = 0;
        while ($sizeVal >= 1024 && $i < count($units) - 1) {
            $sizeVal /= 1024;
            $i++;
        }
        $logSize = round($sizeVal, 2) . ' ' . $units[$i];

        if ($sizeBytes > 50 * 1024 * 1024) {
            return response()->json([
                'logs' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1],
                'stats' => $stats,
                'log_size' => $logSize,
                'message' => 'Файл логов слишком большой (>50MB). Используйте rotate логов.'
            ], 422);
        }

        $currentEntry = null;

        foreach (File::lines($logPath) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\w+)\.(\w+):\s?(.*)/', $line, $matches)) {
                if ($currentEntry) {
                    $allLogs[] = $currentEntry;
                }

                $level = $matches[3];
                $stats['total_entries']++;
                $stats['levels'][$level] = ($stats['levels'][$level] ?? 0) + 1;

                $currentEntry = [
                    'timestamp'   => $matches[1],
                    'environment' => $matches[2],
                    'level'       => $level,
                    'message'     => $matches[4],
                    'trace'       => '',
                    'full'        => $line,
                    'level_color' => $this->levelColors[$level] ?? 'bg-muted text-muted-foreground',
                ];
            } elseif ($currentEntry) {
                $currentEntry['trace'] .= $line . "\n";
                $currentEntry['full'] .= "\n" . $line;
            }
        }

        if ($currentEntry) {
            $allLogs[] = $currentEntry;
        }

        // B2: Ограничиваем кол-во логов в памяти — последние 10000 записей.
        // На 40MB файле это ~200K строк, которые заняли бы сотни MB RAM.
        // 10000 записей — достаточно для просмотра, остальное лучше искать через grep.
        $allLogs = array_slice($allLogs, -10000);

        $allLogs = array_reverse($allLogs);

        // Фильтры
        $levelFilter = $request->input('level', 'all');
        $search = strtolower($request->input('search', ''));
        $dateFilter = $request->input('date', '');
        $page = (int) $request->input('page', 1);
        $perPage = 50;

        $filteredLogs = $allLogs;

        if ($levelFilter !== 'all') {
            $filteredLogs = array_filter($filteredLogs, fn($log) => $log['level'] === $levelFilter);
        }

        if (!empty($search)) {
            $filteredLogs = array_filter($filteredLogs, function ($log) use ($search) {
                return str_contains(strtolower($log['message']), $search) ||
                    str_contains(strtolower($log['full']), $search);
            });
        }

        if (!empty($dateFilter)) {
            $filteredLogs = array_filter($filteredLogs, fn($log) => str_starts_with($log['timestamp'], $dateFilter));
        }

        $filteredLogs = array_values($filteredLogs);
        $total = count($filteredLogs);
        $offset = ($page - 1) * $perPage;
        $paginatedLogs = array_slice($filteredLogs, $offset, $perPage);

        return response()->json([
            'logs' => [
                'data' => $paginatedLogs,
                'total' => $total,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage),
                'per_page' => $perPage,
            ],
            'stats' => $stats,
            'log_size' => $logSize
        ]);
    }

    /**
     * POST /api/admin/system/logs/clear
     *
     * B1: логирует в AdminLog кто очистил логи (для аудита).
     */
    public function clear(Request $request): JsonResponse
    {
        $admin = $request->user();
        $logPath = storage_path('logs/laravel.log');

        try {
            if (File::exists($logPath)) {
                // Затираем файл пустой строкой.
                File::put($logPath, '');
            }

            // B1: логируем очистку логов в AdminLog.
            AdminLog::record('system.logs_clear', null, $admin, null, [
                'status' => 'cleared',
                'context' => [
                    'admin_id' => $admin->id,
                ],
            ]);

            // Пишем в новый (теперь пустой) лог-файл запись об очистке.
            Log::info('Логи очищены администратором', ['admin_id' => $admin->id]);

            return response()->json(['message' => 'Логи успешно очищены']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Не удалось очистить логи: ' . $e->getMessage()], 500);
        }
    }
}
