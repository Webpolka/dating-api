<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Models\InterestCategory;
use Illuminate\Http\JsonResponse;

/**
 * ============================================================
 * InterestController — публичные endpoints для интересов
 * ============================================================
 *
 * Пока нужен только один метод:
 *   GET /api/interests — список категорий с интересами (для multi-select)
 *
 * Методы для редактирования интересов юзера (attach/detach/my) добавим
 * позже, когда будем делать страницу профиля.
 */
class InterestController extends Controller
{
    /**
     * GET /api/interests
     *
     * Список всех интересов сгруппированных по категориям.
     * Используется в multi-select на странице поиска.
     *
     * Возвращает только категории, у которых есть хотя бы один интерес.
     */
    public function index(): JsonResponse
    {
        $categories = InterestCategory::with(['interests' => function ($q) {
            $q->select('id', 'name', 'slug', 'category_id', 'users_count')
              ->orderBy('name');
        }])
        ->select('id', 'name', 'slug', 'icon')
        ->ordered()
        ->whereHas('interests')
        ->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }
}