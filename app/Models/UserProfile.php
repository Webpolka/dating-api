<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class UserProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'gender',
        'birth_date',
        'dating_goals',
        'city_id',
        'country_id',
        'headline',
        'bio',
        'looking_for',        
        'self_portrait',
        'body_type',
        'eye_color',
        'hair_color',
        'height',
        'weight',
        'relationship_status',
        'children_status',
        'pets',
        'housing',
        'has_car',
        'smoking',
        'alcohol',
        'zodiac_sign',
        'body_decorations',
        'languages',
        'sports',
        'education_level',
        'income',
        'institution',
        'institution_year',
        'activity',
        'position',
        'location',
        'address',        
    ];

    protected $casts = [
        'birth_date' => 'date',        
        'self_portrait' => 'array',
        'body_decorations' => 'array',
        'languages' => 'array',
        'sports' => 'array',
        'dating_goals' => 'array',
        'institution_year' => 'integer',
    ];

    protected $appends = [
        'age', 
        'zodiac_sign',
        'zodiac_name',
        'zodiac_icon'
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * ИНТЕРЕСЫ ЮЗЕРА — только approved.
     *
     * Прямая связь BelongsToMany через pivot user_interests.
     * Для поиска по интересам используй whereHas('user.interests', ...) 
     * или whereHas('interests', ...) на UserProfile.
     */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(
            Interest::class,
            'user_interests',
            'user_id',
            'interest_id',
            'user_id',
            'id'
        )       
        ->withPivot('created_at')
        ->orderByPivot('created_at', 'asc');
    }

    // ============================================
    // СКОПЫ ДЛЯ ПОИСКА
    // ============================================

    /**
     * Scope — только профили, которые участвуют в поиске (видны другим юзерам).
     */
    public function scopeSearchable(Builder $query): Builder
    {
        return $query->whereNull('user_profiles.deleted_at')
            ->whereHas('user', function ($q) {
                $q->whereNull('users.deleted_at')
                  ->where('users.status', User::STATUS_ACTIVE)
                  ->where('users.role', User::ROLE_USER)
                  ->where('users.has_completed_onboarding', true)
                  ->whereHas('preferences', function ($pq) {
                      $pq->where('hide_from_search', false)
                         ->where('is_invisible', false);
                  });
            });
    }

    /**
     * Scope — анкеты по полу.
     */
    public function scopeOfGender(Builder $query, ?string $gender): Builder
    {
        if (!$gender || $gender === 'any') {
            return $query;
        }
        return $query->where('gender', $gender);
    }

    /**
     * Scope — возрастной диапазон.
     */
    public function scopeBetweenAges(Builder $query, ?int $minAge = 18, ?int $maxAge = 99): Builder
    {
        $minAge = $minAge ?? 18;
        $maxAge = $maxAge ?? 99;

        if ($minAge > $maxAge) {
            [$minAge, $maxAge] = [$maxAge, $minAge];
        }

        $maxDate = Carbon::now()->subYears($minAge)->format('Y-m-d');
        $minDate = Carbon::now()->subYears($maxAge)->format('Y-m-d');

        return $query->whereBetween('birth_date', [$minDate, $maxDate]);
    }

    /**
     * Scope — анкеты рядом (geo-фильтр через PostGIS).
     */
    public function scopeNearby(Builder $query, float $lat, float $lng, int $radiusKm = 50): Builder
    {
        return $query->whereNotNull('location')
            ->whereRaw(
                'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$lng, $lat, $radiusKm * 1000]
            );
    }

    /**
     * Scope — сортировка по расстоянию от точки.
     */
    public function scopeOrderByDistance(Builder $query, float $lat, float $lng): Builder
    {
        return $query->selectRaw(
            'user_profiles.*, ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters',
            [$lng, $lat]
        )->orderBy('distance_meters');
    }

    /**
     * Scope — анкеты с интересом по slug (например, 'sport').
     * Через классический pivot (whereHas).
     */
    public function scopeWithInterestSlug(Builder $query, string $slug): Builder
    {
        return $query->whereHas('interests', function ($q) use ($slug) {
            $q->where('interests.slug', $slug);
        });
    }

    /**
     * Scope — анкеты с конкретным значением в JSONB-колонке (languages, sports, dating_goals).
     */
    public function scopeWhereJsonContainsValue(Builder $query, string $column, $value): Builder
    {
        return $query->whereRaw("$column @> ?::jsonb", [json_encode([$value])]);
    }

    /**
     * Scope — добавление latitude/longitude в SELECT.
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        return $query->addSelect([
            'latitude' => DB::raw('ST_Y(location::geometry)'),
            'longitude' => DB::raw('ST_X(location::geometry)'),
        ]);
    }

    // ============================================
    // ГЕОЛОКАЦИЯ (PostGIS)
    // ============================================

    public function setLocation(float $lat, float $lng): void
    {
        $this->newQuery()
            ->where('id', $this->id)
            ->update([
                'location' => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography")
            ]);

        $this->location = DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography");
        $this->latitude = $lat;
        $this->longitude = $lng;
    }

    public function getCoordinates(): ?array
    {
        if (!$this->location) {
            return null;
        }

        $result = DB::table('user_profiles')
            ->where('id', $this->id)
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->first();

        return $result ? ['lat' => $result->lat, 'lng' => $result->lng] : null;
    }

    // ============================================
    // АКСЕССОРЫ — ЗОДИАК
    // ============================================

    public function getAgeAttribute(): ?int
    {
        if (!$this->birth_date) {
            return null;
        }
        return Carbon::parse($this->birth_date)->age;
    }

    public function getZodiacSignAttribute(): ?int
    {
        if (!$this->birth_date) {
            return null;
        }

        $date = Carbon::parse($this->birth_date);
        $day = (int) $date->format('d');
        $month = (int) $date->format('m');

        return match (true) {
            ($month === 3 && $day >= 21) || ($month === 4 && $day <= 20) => 1, // Овен
            ($month === 4 && $day >= 21) || ($month === 5 && $day <= 21) => 2, // Телец
            ($month === 5 && $day >= 22) || ($month === 6 && $day <= 21) => 3, // Близнецы
            ($month === 6 && $day >= 22) || ($month === 7 && $day <= 22) => 4, // Рак
            ($month === 7 && $day >= 23) || ($month === 8 && $day <= 21) => 5, // Лев
            ($month === 8 && $day >= 22) || ($month === 9 && $day <= 23) => 6, // Дева
            ($month === 9 && $day >= 24) || ($month === 10 && $day <= 23) => 7, // Весы
            ($month === 10 && $day >= 24) || ($month === 11 && $day <= 22) => 8, // Скорпион
            ($month === 11 && $day >= 23) || ($month === 12 && $day <= 21) => 9, // Стрелец
            ($month === 12 && $day >= 22) || ($month === 1 && $day <= 20) => 10, // Козерог
            ($month === 1 && $day >= 21) || ($month === 2 && $day <= 19) => 11, // Водолей
            default => 12, // Рыбы
        };
    }

    public function getZodiacNameAttribute(): ?string
    {
        $sign = $this->zodiac_sign;
        if ($sign === null) {
            return null;
        }

        $names = [
            1 => 'Овен', 2 => 'Телец', 3 => 'Близнецы', 4 => 'Рак',
            5 => 'Лев', 6 => 'Дева', 7 => 'Весы', 8 => 'Скорпион',
            9 => 'Стрелец', 10 => 'Козерог', 11 => 'Водолей', 12 => 'Рыбы',
        ];

        return $names[$sign] ?? null;
    }

    public function getZodiacIconAttribute(): ?string
    {
        $sign = $this->zodiac_sign;
        if ($sign === null) {
            return null;
        }

        $icons = [
            1 => '♈', 2 => '♉', 3 => '♊', 4 => '♋', 5 => '♌', 6 => '♍',
            7 => '♎', 8 => '♏', 9 => '♐', 10 => '♑', 11 => '♒', 12 => '♓',
        ];

        $icon = $icons[$sign] ?? null;
        if ($icon === null) {
            return null;
        }

        return $icon . "\u{FE0E}";
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Проверка, достаточно ли заполнен профиль для поиска.
     */
    public function isCompleteEnough(): bool
    {
        return !is_null($this->gender) && !is_null($this->birth_date);
    }
}