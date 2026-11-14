<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Actions\V1\Admin\System\ManageSettingsAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\System\SettingResource;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Настройки системы (Setting).
 *
 * Three operations:
 *  - groups() — список групп настроек (general, limits, moderation, security,
 *    finance, seo, social) с человекочитаемыми labels.
 *  - index(?group=...) — список настроек конкретной группы (value type-cast).
 *  - save(group, settings[]) — массовое сохранение (через ManageSettingsAction:
 *    транзакция, JSON-валидация, coercion, аудит).
 *  - reset(group) — сброс к заводским значениям (через Action: транзакция + аудит).
 *
 * Save/reset возвращают 422 на \InvalidArgumentException (невалидный JSON в value)
 * и 500 на прочие исключения.
 */
class SettingController extends AdminController
{
    /**
     * GET /api/admin/settings/groups — список групп с labels.
     */
    public function groups(): JsonResponse
    {
        $groups = Setting::select('group')->distinct()->orderBy('group')->pluck('group');

        $labels = [
            'general'    => 'Основные',
            'limits'     => 'Лимиты',
            'moderation' => 'Модерация',
            'security'   => 'Безопасность',
            'finance'    => 'Финансы',
            'seo'        => 'SEO',
            'social'     => 'Соц. сети',
        ];

        $formatted = $groups->map(fn($group) => [
            'key'   => $group,
            'label' => $labels[$group] ?? ucfirst($group),
        ]);

        return response()->json($formatted);
    }

    /**
     * GET /api/admin/settings?group=general — список настроек группы.
     */
    public function index(Request $request): JsonResponse
    {
        $group = $request->input('group', 'general');

        $settings = Setting::where('group', $group)->orderBy('id')->get();

        return response()->json(SettingResource::collection($settings)->resolve($request));
    }

    /**
     * POST /api/admin/settings/save — массовое сохранение настроек группы.
     *
     * Транзакция (JSON-валидация, coercion, save) + аудит — в ManageSettingsAction.
     */
    public function save(Request $request, ManageSettingsAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group'              => 'required|string',
            'settings'           => 'required|array',
            'settings.*.id'      => 'required|exists:settings,id',
            'settings.*.value'   => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $group = $data['group'];
        $admin = $request->user();

        try {
            $action->save($group, $data['settings'], $admin);

            return $this->message('Настройки сохранены!');
        } catch (\InvalidArgumentException $e) {
            return $this->message($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->message('Ошибка сохранения: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/admin/settings/reset — сброс настроек группы к заводским.
     *
     * Транзакция + аудит — в ManageSettingsAction::reset().
     */
    public function reset(Request $request, ManageSettingsAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $group = $validator->validated()['group'];
        $admin = $request->user();

        try {
            $action->reset($group, $admin);

            return $this->message('Настройки сброшены к заводским значениям!');
        } catch (\Exception $e) {
            return $this->message('Ошибка сброса: ' . $e->getMessage(), 500);
        }
    }
}
