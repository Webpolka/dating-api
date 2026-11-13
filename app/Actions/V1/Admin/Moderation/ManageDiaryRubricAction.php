<?php

namespace App\Actions\V1\Admin\Moderation;

use App\Models\AdminLog;
use App\Models\Diary;
use App\Models\DiaryRubric;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRUD системных рубрик дневников (user_id IS NULL).
 * Извлечена из DiaryController (thin-controller pattern; модерация постов —
 * в ModerateDiaryAction).
 *
 * КОНТРАКТ ВОЗВРАТА:
 *   - storeRubric / updateRubric возвращают СВЕЖУЮ модель DiaryRubric с
 *     загруженным diaries_count ($rubric->fresh()->loadCount('diaries')) —
 *     контроллер форматирует её приватным formatRubric() для 'data' в теле ответа.
 *   - destroyRubric / toggleRubricStatus — void; тело ответа фиксировано,
 *     контроллер строит его сам.
 *
 * Slug: генерируется из name; уникальность добирается суффиксами -2, -3, ...
 * При переименовании (updateRubric) slug перегенерируется только если name
 * действительно изменился.
 *
 * Логирование: AdminLog с action'ами diary_rubric.create / diary_rubric.update /
 * diary_rubric.delete / diary_rubric.toggle_status — before/after как в исходном
 * контроллере.
 */
class ManageDiaryRubricAction
{
    // ============================================
    // РУБРИКИ — CRUD + TOGGLE
    // ============================================

    /**
     * Создать системную рубрику.
     *
     * @param  User  $admin     Кто создал.
     * @param  array $validated Данные StoreDiaryRubricRequest: name, description?, is_active?, sort_order?.
     * @return DiaryRubric Свежая модель с загруженным diaries_count (для formatRubric в ответе).
     */
    public function storeRubric(User $admin, array $validated): DiaryRubric
    {
        $slug = Str::slug($validated['name']);
        if (empty($slug)) {
            $slug = 'rubric-' . time();
        }
        $slugBase = $slug;
        $counter = 2;
        while (DiaryRubric::where('slug', $slug)->exists()) {
            $slug = $slugBase . '-' . $counter;
            $counter++;
        }

        $rubric = DiaryRubric::create(array_merge($validated, [
            'slug' => $slug,
            'user_id' => null,
        ]));

        $after = [
            'status' => 'created',
            'context' => [
                'diary_rubric_id' => $rubric->id,
                'name' => $rubric->name,
                'slug' => $rubric->slug,
                'is_system' => true,
            ],
        ];
        AdminLog::record('diary_rubric.create', $rubric, $admin, null, $after);

        $this->clearCaches();

        return $rubric->fresh()->loadCount('diaries');
    }

    /**
     * Обновить системную рубрику (name/description/is_active/sort_order;
     * slug перегенерируется при смене name).
     *
     * @param  DiaryRubric $rubric    Захвачена lockForUpdate в контроллере.
     * @param  User        $admin     Кто изменил.
     * @param  array       $validated Данные UpdateDiaryRubricRequest: name, description?, is_active?, sort_order?.
     * @return DiaryRubric Свежая модель с загруженным diaries_count (для formatRubric в ответе).
     */
    public function updateRubric(DiaryRubric $rubric, User $admin, array $validated): DiaryRubric
    {
        $before = [
            'name' => $rubric->getOriginal('name'),
            'is_active' => $rubric->getOriginal('is_active'),
            'sort_order' => $rubric->getOriginal('sort_order'),
        ];

        if (isset($validated['name']) && $validated['name'] !== $rubric->name) {
            $newSlug = Str::slug($validated['name']);
            if (empty($newSlug)) {
                $newSlug = 'rubric-' . time();
            }
            $slugBase = $newSlug;
            $counter = 2;
            while (DiaryRubric::where('slug', $newSlug)->where('id', '!=', $rubric->id)->exists()) {
                $newSlug = $slugBase . '-' . $counter;
                $counter++;
            }
            $validated['slug'] = $newSlug;
        }

        DB::transaction(function () use ($rubric, $admin, $validated, $before) {
            $rubric->update($validated);

            $after = [
                'name' => $rubric->name,
                'is_active' => $rubric->is_active,
                'context' => [
                    'diary_rubric_id' => $rubric->id,
                    'name' => $rubric->name,
                    'is_system' => is_null($rubric->user_id),
                ],
            ];
            AdminLog::record('diary_rubric.update', $rubric, $admin, $before, $after);
        });

        $this->clearCaches();

        return $rubric->fresh()->loadCount('diaries');
    }

    /**
     * Удалить системную рубрику; её записи переносятся в $validated['reassign_id']
     * (или в NULL — без рубрики).
     *
     * @param  DiaryRubric $rubric    Захвачена lockForUpdate в контроллере.
     * @param  User        $admin     Кто удалил.
     * @param  array       $validated Данные DestroyDiaryRubricRequest: reassign_id?.
     * @return void
     */
    public function destroyRubric(DiaryRubric $rubric, User $admin, array $validated): void
    {
        $reassignId = $validated['reassign_id'] ?? null;

        DB::transaction(function () use ($rubric, $reassignId) {
            Diary::where('diary_rubric_id', $rubric->id)->update(['diary_rubric_id' => $reassignId]);
            $rubric->delete();
        });

        $before = ['name' => $rubric->name, 'reassign_to' => $reassignId];
        $after = [
            'status' => 'deleted',
            'deleted_by' => $admin->id,
            'context' => [
                'diary_rubric_id' => $rubric->id,
                'name' => $rubric->name,
                'is_system' => is_null($rubric->user_id),
                'reassigned_to' => $reassignId,
            ],
        ];
        AdminLog::record('diary_rubric.delete', $rubric, $admin, $before, $after);

        $this->clearCaches();
    }

    /**
     * Переключить is_active рубрики (включить/скрыть).
     *
     * @param  DiaryRubric $rubric Захвачена lockForUpdate в контроллере.
     * @param  User        $admin  Кто переключил.
     * @return void
     */
    public function toggleRubricStatus(DiaryRubric $rubric, User $admin): void
    {
        $before = ['is_active' => $rubric->getOriginal('is_active')];
        $rubric->update(['is_active' => !$rubric->is_active]);

        $after = [
            'is_active' => $rubric->is_active,
            'toggled_by' => $admin->id,
            'context' => [
                'diary_rubric_id' => $rubric->id,
                'name' => $rubric->name,
                'is_system' => is_null($rubric->user_id),
            ],
        ];
        AdminLog::record('diary_rubric.toggle_status', $rubric, $admin, $before, $after);

        $this->clearCaches();
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCaches(): void
    {
        Cache::forget('admin_diary_counts');
        Cache::forget('admin_diary_rubrics_list');
        Cache::forget('admin_sidebar_stats');
        Cache::forget('admin_dashboard_live_v2');
    }
}
