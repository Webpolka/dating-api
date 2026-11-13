<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Enums\V1\MediaCollection;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\BulkDeleteMediaRequest;
use App\Http\Requests\Api\V1\Admin\System\UploadMediaRequest;
use App\Jobs\V1\Admin\System\ProcessMediaUploadJob;
use App\Models\AdminLog;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class MediaController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        // B1: убрал избыточный ->toString()
        $search = (string) $request->string('search', '');
        $collection = (string) $request->string('collection', 'all');
        $perPage = min(max((int) $request->integer('per_page', 24), 1), 100);
        $isId = $search !== '' && ctype_digit($search);

        // B2: оператор поиска через config
        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        // Логика поиска по ID:
        // - Если collection=all → ищем по всем, при нахождении подставляем реальную коллекцию
        // - Если collection=forced → ищем строго в этой коллекции
        $actualCollection = $collection;
        if ($isId && $collection === 'all') {
            $found = Media::find((int) $search);
            if ($found) {
                $actualCollection = $found->collection;
            }
        }

        $collectionEnum = MediaCollection::tryFrom($actualCollection) ?? MediaCollection::Default;

        $media = Media::query()
            ->where('type', Media::TYPE_IMAGE)
            ->when($actualCollection !== 'all', fn($q) => $q->where('collection', $actualCollection))
            ->when($search !== '' && !$isId, function ($q) use ($search, $searchOperator) {
                $q->where('file_name', $searchOperator, "%{$search}%");
            })
            ->when($isId, function ($q) use ($search) {
                $q->where('id', (int) $search)
                    ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $media->map(fn(Media $m) => [
                'id'            => $m->id,
                'file_name'     => $m->file_name,
                'disk_path'     => $m->disk_path,
                'url'           => $m->url,
                'thumb_url'     => $m->getVariantUrl('thumb'),
                'sm_url'        => $m->getVariantUrl('sm'),
                'lg_url'        => $m->getVariantUrl('lg'),
                'collection'    => $m->collection,
                'is_processing' => $m->type === Media::TYPE_IMAGE && empty($m->variants),
                'size'          => $m->size,
                'mime_type'     => $m->mime_type,
                'created_at'    => $m->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $media->currentPage(),
                'last_page'    => $media->lastPage(),
                'per_page'     => $media->perPage(),
                'total'        => $media->total(),
                'from'         => $media->firstItem(),
                'to'           => $media->lastItem(),
            ],
            'collection' => $actualCollection,
            'collection_label' => $collectionEnum->label(),
            'crop_rules' => $this->getCropRules($actualCollection),
        ]);
    }

    public function counts(): JsonResponse
    {
        $counts = Cache::remember('admin_media_counts', 60, function () {
            $dbCounts = Media::where('type', Media::TYPE_IMAGE)
                ->selectRaw('collection, count(*) as count')
                ->groupBy('collection')
                ->pluck('count', 'collection');

            $result = ['all' => $dbCounts->sum()];
            foreach (MediaCollection::cases() as $case) {
                $result[$case->value] = $dbCounts[$case->value] ?? 0;
            }
            return $result;
        });

        return response()->json(['data' => $counts]);
    }

    public function show(Media $media): JsonResponse
    {
        $variants = [];
        if (!empty($media->variants)) {
            $config = config("media.collections.{$media->collection}.variants", []);
            foreach ($media->variants as $key => $path) {
                $variants[] = [
                    'key'    => $key,
                    'url'    => Storage::url($path),
                    'size'   => $config[$key]['size'] ?? '—',
                    'fit'    => $config[$key]['fit'] ?? '—',
                    'format' => $config[$key]['format'] ?? '—',
                ];
            }
        }

        $collectionEnum = MediaCollection::tryFrom($media->collection);

        return response()->json([
            'data' => [
                'id'                => $media->id,
                'file_name'         => $media->file_name,
                'collection'        => $media->collection,
                'collection_label'  => $collectionEnum?->label() ?? $media->collection,
                'size'              => $media->size,
                'mime_type'         => $media->mime_type,
                'created_at'        => $media->created_at?->toIso8601String(),
                'variants'          => $variants,
                'is_processing'     => empty($media->variants),
                'lg_url'            => $media->getVariantUrl('lg'),
                'thumb_url'         => $media->getVariantUrl('thumb'),
            ]
        ]);
    }

    /**
     * Загрузка файлов.
     *
     * B3: добавлена 'image' rule — проверяет РЕАЛЬНЫЙ mime, а не расширение.
     * Раньше можно было залить .php переименованный в .jpg.
     */
    public function upload(UploadMediaRequest $request): JsonResponse
    {
        $admin = $request->user();
        $collection = (string) $request->string('collection', 'default');

        $validated = $request->validated();

        $collectionEnum = MediaCollection::tryFrom($collection) ?? MediaCollection::Default;
        $uploadedIds = [];

        foreach ($validated['files'] as $file) {
            $tempPath = $file->store('media/temp', 'public');
            $media = Media::create([
                'collection'    => $collection,
                'file_name'     => $file->getClientOriginalName(),
                'disk_path'     => $tempPath,
                'url'           => Storage::url($tempPath),
                'type'          => Media::TYPE_IMAGE,
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
                'uploaded_by'   => $admin->id,
                'variants'      => null,
            ]);
            $uploadedIds[] = $media->id;

            ProcessMediaUploadJob::dispatch($media->id, $tempPath, $collectionEnum, $file->getClientOriginalName());
        }

        if (!empty($uploadedIds)) {
            $count = count($uploadedIds);
            $actionName = $count === 1 ? 'media.upload' : 'media.upload_bulk';
            $loggableMedia = $count === 1 ? Media::find($uploadedIds[0]) : null;

            AdminLog::record($actionName, $loggableMedia, $admin, null, [
                'status' => 'created',
                'count' => $count,
                'uploaded_by' => $admin->id,
                'context' => [
                    'collection' => $collection,
                    'media_ids' => array_slice($uploadedIds, 0, 100),
                    'admin_id' => $admin->id
                ]
            ]);
        }

        Cache::forget('admin_media_counts');

        return response()->json(['message' => 'Загружено файлов: ' . count($uploadedIds)]);
    }

    public function destroy(Media $media, Request $request): JsonResponse
    {
        $admin = $request->user();

        $before = [
            'file_name' => $media->file_name,
            'collection' => $media->collection,
            'size' => $media->size
        ];

        $after = [
            'status' => 'destroyed',
            'deleted_by' => $admin->id,
            'context' => [
                'media_id' => $media->id,
                'file_name' => $media->file_name,
                'admin_id' => $admin->id
            ]
        ];

        AdminLog::record('media.delete', $media, $admin, $before, $after);
        $media->safeDelete();

        Cache::forget('admin_media_counts');

        return response()->json(['message' => 'Файл удален']);
    }

    public function bulkDelete(BulkDeleteMediaRequest $request): JsonResponse
    {
        $admin = $request->user();

        $validated = $request->validated();

        $mediaIds = $validated['ids'];

        $medias = Media::whereIn('id', $mediaIds)->get();
        $deletedCount = 0;

        foreach ($medias as $media) {
            $media->safeDelete();
            $deletedCount++;
        }

        AdminLog::record('media.delete_bulk', null, $admin, null, [
            'status' => 'destroyed',
            'count' => $deletedCount,
            'deleted_by' => $admin->id,
            'context' => [
                'media_ids' => array_slice($mediaIds, 0, 100),
                'admin_id' => $admin->id
            ]
        ]);

        Cache::forget('admin_media_counts');

        return response()->json(['message' => "Удалено файлов: {$deletedCount}"]);
    }

    private function getCropRules(string $collection): array
    {
        $config = config("media.collections.{$collection}", []);
        $variants = [];

        if (isset($config['variants'])) {
            foreach ($config['variants'] as $key => $variant) {
                $variants[] = [
                    'key'     => $key,
                    'size'    => $variant['size'],
                    'fit'     => $variant['fit'],
                    'format'  => $variant['format'],
                    'quality' => $variant['quality'] ?? 80,
                ];
            }
        }

        return [
            'keep_original' => $config['keep_original'] ?? false,
            'alpha'         => $config['alpha'] ?? false,
            'max_size_mb'   => round(($config['max_file_size_kb'] ?? 5120) / 1024, 1),
            'variants'      => $variants,
        ];
    }
}
