<?php

namespace App\Http\Controllers\Admin\V1\System;

use App\Actions\Admin\System\ManageSettingsAction;
use App\Http\Controllers\AdminController;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingController extends AdminController
{
    public function groups(): JsonResponse
    {
        $groups = Setting::select('group')->distinct()->orderBy('group')->pluck('group');

        $labels = [
            'general' => 'Основные',
            'limits' => 'Лимиты',
            'moderation' => 'Модерация',
            'security' => 'Безопасность',
            'finance' => 'Финансы',
            'seo' => 'SEO',
            'social' => 'Соц. сети',
        ];

        $formatted = $groups->map(function ($group) use ($labels) {
            return [
                'key' => $group,
                'label' => $labels[$group] ?? ucfirst($group)
            ];
        });

        return response()->json($formatted);
    }

    public function index(Request $request): JsonResponse
    {
        $group = $request->input('group', 'general');

        $settings = Setting::where('group', $group)->orderBy('id')->get();

        $formatted = $settings->map(function ($item) {
            $value = $item->value;

            if ($item->type === Setting::TYPE_BOOLEAN) {
                $value = (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif ($item->type === Setting::TYPE_INTEGER) {
                $value = (int) $value;
            } elseif ($item->type === Setting::TYPE_JSON) {
                $value = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            return [
                'id' => $item->id,
                'key' => $item->key,
                'value' => $value,
                'type' => $item->type,
                'label' => $item->label,
                'description' => $item->description,
                'options' => $item->options,
                'is_public' => $item->is_public,
            ];
        });

        return response()->json($formatted);
    }

    public function save(Request $request, ManageSettingsAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group' => 'required|string',
            'settings' => 'required|array',
            'settings.*.id' => 'required|exists:settings,id',
            'settings.*.value' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $group = $data['group'];
        $admin = $request->user();

        try {
            // Транзакция (JSON-валидация, coercion, save) + аудит — в Action.
            // Исключения пробрасываются наверх и мапятся ниже в 422/500.
            $action->save($group, $data['settings'], $admin);

            return response()->json(['message' => 'Настройки сохранены!']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Ошибка сохранения: ' . $e->getMessage()], 500);
        }
    }

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
            // Транзакция + аудит — в Action. Исключения пробрасываются наверх.
            $action->reset($group, $admin);

            return response()->json(['message' => 'Настройки сброшены к заводским значениям!']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Ошибка сброса: ' . $e->getMessage()], 500);
        }
    }
}
