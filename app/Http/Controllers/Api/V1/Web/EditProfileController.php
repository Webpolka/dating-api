<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Validator;

use App\Http\Requests\V1\UpdateProfileInterestsRequest;
use App\Http\Requests\V1\UpdateProfileMainRequest;
use App\Http\Requests\V1\UpdateProfileHeadlineRequest;
use App\Http\Requests\V1\UpdateProfileBioRequest;
use App\Http\Requests\V1\UpdateProfileSelfPortraitRequest;
use App\Http\Requests\V1\UpdateProfilePersonalInfoRequest;
use App\Http\Requests\V1\UpdateProfileLookingForRequest;
use App\Http\Requests\V1\UpdateProfilePreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EditProfileController extends Controller
{

    /**
     * PATCH /api/profile/interests
     *
     * Обновляет список интересов юзера.
     * Принимает массив ID интересов — полностью синхронизирует (замена).
     *
     * Логика:
     *   - sync() через pivot user_interests
     *   - Триггер user_interests_update_count автоматически обновит interests.users_count
     *   - sync без detach для уже существующих (не дублирует, не падает)
     *
     * Возвращает обновлённый список интересов с категориями (для UI).
     */
    public function updateInterests(UpdateProfileInterestsRequest $request): JsonResponse
    {
        $user = $request->user();
        $interestIds = $request->input('interests', []);

        try {
            // sync — заменяет весь список (если было — убирает, новые добавляет)
            // Используем модель User с relation interests() (он без where status, т.к. модерации нет)
            $user->interests()->sync($interestIds);

            // Загружаем обновлённые интересы с категорией (для UI)
            $user->load(['interests' => function ($q) {
                $q->select('interests.id', 'interests.name', 'interests.slug', 'interests.category_id')
                  ->orderBy('user_interests.created_at', 'asc');
            }, 'interests.category:id,name,slug']);

            return response()->json([
                'success'  => true,
                'message'  => 'Интересы обновлены.',
                'interests' => $user->interests->map(fn ($interest) => [
                    'id'       => $interest->id,
                    'name'     => $interest->name,
                    'slug'     => $interest->slug,
                    'category' => $interest->category ? [
                        'id'   => $interest->category->id,
                        'name' => $interest->category->name,
                        'slug' => $interest->category->slug,
                    ] : null,
                ]),
            ]);

        } catch (\Throwable $e) {
            Log::error('updateInterests failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось сохранить интересы. Попробуйте позже.',
            ], 500);
        }
    }

    /**
 * Обновить основные данные профиля (имя, дата рождения, город).
 *
 * Endpoint: PATCH /api/profile/main
 * Body: { name, birth_date, city_id }
 *
 * Возвращает: { success, message, user } — где user это СВЕЖИЙ объект
 * со всеми обновлёнными accessors (age, zodiac_name, zodiac_icon) и
 * relations (city). Это нужно чтобы фронт реактивно обновил возраст/зодиак
 * после изменения birth_date — accessor-ы не пересчитываются на фронте.
 */
public function updateMain(UpdateProfileMainRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль не найден.',
            ], 404);
        }

        // Обновляем name в users
        if (isset($validated['name'])) {
            $user->update(['name' => $validated['name']]);
        }

        // Обновляем birth_date, city_id в user_profiles
        $profileUpdate = [];
        if (array_key_exists('birth_date', $validated)) {
            $profileUpdate['birth_date'] = $validated['birth_date'];
        }
        if (array_key_exists('city_id', $validated)) {
            $profileUpdate['city_id'] = $validated['city_id'];
        }
        if (!empty($profileUpdate)) {
            $profile->update($profileUpdate);
        }

        // FIX: возвращаем СВЕЖИЙ user со всеми accessors (age, zodiac_name, zodiac_icon)
        // и relations (city). Через fresh() — перечитываем из БД, accessors пересчитаются.
        $freshUser = $user->fresh();
        $freshUser->load('profile.city');  // загружаем relation city (если она используется)

        // Сериализуем так же как в /api/user/{slug} — через toArray() с $appends
        // Если в User::toArray есть 'photos' или 'gifts_received' — они НЕ попадут
        // в ответ (нужны отдельные relations). Фронт сохраняет их из prev.
        return response()->json([
            'success' => true,
            'message' => 'Основные данные обновлены.',
            'user' => $freshUser,  // ← NEW: возвращаем обновлённый user
        ]);
    }

    /**
     * Обновить headline (статус пользователя).
     *
     * Endpoint: PATCH /api/profile/headline
     * Body: { headline: string|null }
     *
     * TODO (будущее): backend должен СОЗДАТЬ запись в user_events:
     *   { type: 'headline_updated', user_id, meta: { old: '...', new: '...' } }
     *   — для ленты событий пользователя.
     */
    public function updateHeadline(UpdateProfileHeadlineRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль не найден.',
            ], 404);
        }

        // TODO: создать запись в user_events (type='headline_updated')
        // $oldHeadline = $profile->headline;
        // UserEvent::create([...]);

        $profile->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Статус обновлён.',
        ]);
    }

    /**
     * Обновить автопортрет (19 текстовых полей).
     *
     * Все поля хранятся в одном JSONB поле user_profiles.self_portrait.
     * На фронте — ProfileSelfPortrait компонент с 19 textarea.
     *
     * Endpoint: PATCH /api/profile/self-portrait
     * Body: { education, musical, film, hero, dish, city, pastime, place,
     *         revelation, worse, quality, mistake, religion, character,
     *         demerit, job, adventure, tv, mat }
     * Все поля — optional, nullable|string|max:500.
     *
     * Автопортрет НЕ индексируется и НЕ ищется — это информационные поля.
     * Никаких GIN-индексов не нужно (в отличие от интересов/языков/спорта).
     */
    public function updateSelfPortrait(UpdateProfileSelfPortraitRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль не найден.',
            ], 404);
        }

        // Сохраняем весь объект в JSONB поле self_portrait.
        // Laravel сам кодирует array → JSON через cast 'array'.
        // Поля со значением null сохраняются как null в JSON.
        $profile->update([
            'self_portrait' => $validated,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Автопортрет обновлён.',
            'self_portrait' => $profile->fresh()->self_portrait,
        ]);
    }

    /**
     * Обновить личную информацию (внешность, отношения, быт, работа, привычки, языки, спорт).
     *
     * Все поля хранятся в отдельных колонках user_profiles (не JSONB).
     * На фронте — ProfilePersonalInfo компонент с select/input/toggle.
     *
     * Endpoint: PATCH /api/profile/personal-info
     * Body: { height, weight, body_type, eye_color, hair_color, body_decorations,
     *         relationship_status, children_status, pets, housing, has_car,
     *         education_level, institution, institution_year, income,
     *         activity, position, smoking, alcohol, languages, sports }
     */
    public function updatePersonalInfo(UpdateProfilePersonalInfoRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль не найден.',
            ], 404);
        }      

        // Сохраняем все поля сразу. Laravel сам разберётся с cast 'array'
        // для body_decorations, languages, sports (JSONB).
        $profile->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Личная информация обновлена.',
        ]);
    }


    public function updateLookingFor(UpdateProfileLookingForRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль не найден.',
            ], 404);
        }

        $profile->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Раздел «Кого я хочу найти» обновлён.',
        ]);
    }

    public function updateBio(UpdateProfileBioRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();
        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Профиль не найден.',
            ], 404);
        }

        $profile->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Раздел «О себе» обновлён.',
        ]);
    }

    /**
     * Обновить секцию "Познакомлюсь" (кого ищу, возраст, цели знакомства).
     *
     * Endpoint: PATCH /api/profile/preferences
     * Body: { preferred_gender, preferred_age_min, preferred_age_max, dating_goals }
     *
     * preferred_gender хранится в user_preferences.preferred_gender
     * preferred_age_min/max хранятся в user_preferences.preferred_age_min/max
     * dating_goals хранятся в user_profiles.dating_goals (JSONB-массив)
     */
    public function updatePreferences(UpdateProfilePreferencesRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();

        // preferred_gender, preferred_age_min, preferred_age_max — в user_preferences.
        $user->preferences()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'preferred_gender'   => $validated['preferred_gender'] ?? null,
                'preferred_age_min'  => $validated['preferred_age_min'] ?? null,
                'preferred_age_max'  => $validated['preferred_age_max'] ?? null,
            ]
        );

        // dating_goals — отдельный JSONB в user_profiles
        if (isset($validated['dating_goals'])) {
            $user->profile?->update(['dating_goals' => $validated['dating_goals']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Параметры поиска обновлены.',
        ]);
    }
    // TODO: будущие методы (добавим по мере готовности фронт-компонентов):
 
    //   updateHeadline(Request $request)     — PATCH /api/profile/headline 
    
 
    //   updateMain(Request $request)          — PATCH /api/profile/main (имя, дата, город)
}
