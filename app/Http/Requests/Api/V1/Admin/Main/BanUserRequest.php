<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

use App\Enums\V1\BanReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация запроса на блокировку пользователя.
 *
 * POST /api/admin/users/{userId}/ban
 *
 * Вынесено из контроллера, чтобы:
 *   1. правила были переиспользуемыми и самодокументируемыми;
 *   2. контроллер не занимался разбором входных данных;
 *   3. `php artisan`-инструменты (ide-helper, openapi) подхватывали схему.
 *
 * Типы бана:
 *   - permanent — бессрочный, status='banned', banned_until=null
 *   - temp      — на N дней (1..365), status='banned', banned_until=now+N
 *   - shadow    — теневой, status='shadowbanned', пользователь этого не видит
 *
 * Поле `days` имеет смысл ТОЛЬКО для temp-бана; для остального игнорируется.
 */
class BanUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Маршрут уже закрыт middleware admin.role:admin,moderator.
        // Дополнительную per-action проверку можно делать в Policy/Gate.
        return true;
    }

    public function rules(): array
    {
        return [
            'type'   => ['required', Rule::in(['permanent', 'temp', 'shadow'])],
            'reason' => ['required', 'string', Rule::in(BanReason::cases())],
            // Исторически temp-бан был захардкожен на 3 дня в Action.
            // Теперь длительность управляется фронтом; null сохраняет дефолт.
            'days'   => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'   => 'Не указан тип блокировки.',
            'type.in'         => 'Недопустимый тип блокировки. Допустимо: permanent, temp, shadow.',
            'reason.required' => 'Причина блокировки обязательна.',
            'reason.in'       => 'Недопустимая причина блокировки.',
            'days.integer'    => 'Количество дней должно быть целым числом.',
            'days.min'         => 'Минимальная длительность — 1 день.',
            'days.max'         => 'Максимальная длительность — 365 дней.',
        ];
    }

    /**
     * Нормализованные данные для передачи в ToggleUserBanAction.
     * Возвращает только то, что реально нужно action — никакого мусора.
     */
    public function banPayload(): array
    {
        $validated = $this->validated();

        return [
            'type'   => $validated['type'],
            'reason' => $validated['reason'],
            'days'   => array_key_exists('days', $validated) ? (int) $validated['days'] : null,
        ];
    }
}
