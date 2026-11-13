<?php

namespace App\Http\Controllers\Admin\V1\System;

use App\Http\Controllers\AdminController;
use App\Models\AdminLog;
use App\Models\Chat;
use App\Models\Photo;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\PhotoComment;
use App\Models\DiaryComment;
use App\Models\Diary;
use App\Models\Swipe;
use App\Models\UserMatch;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends AdminController
{
    public function stats(): JsonResponse
    {
        $role = request()->user()->role;
        $data = [];

        // 1. БАЗОВЫЕ ЖИВЫЕ ДАННЫЕ (Для всех ролей) - Кэшируем на 1 минуту
        $liveData = Cache::remember('admin_dashboard_live_v2', 60, function () {
            return [
                'onlineUsers' => User::excludeStaff()->where('last_seen', '>=', now()->subMinutes(5))->count(),
                'newUsersToday' => User::excludeStaff()->whereDate('created_at', today())->count(),
                'newUsersWeek' => User::excludeStaff()->where('created_at', '>=', now()->subDays(7))->count(),
                'newUsersMonth' => User::excludeStaff()->where('created_at', '>=', now()->subDays(30))->count(),

                'pendingPhotos' => Photo::where('status', 'pending')->count(),
                'pendingPhotoComments' => PhotoComment::where('status', 'pending')->count(),
                'pendingDiaries' => Diary::where('status', 'pending')->count(),
                'pendingDiaryComments' => DiaryComment::where('status', 'pending')->count(),
                'pendingReports' => Report::where('status', 'pending')->count(),

                'unreadTickets' => Chat::where('type', 'support')
                    ->whereHas('participants', fn($q) => $q->where('unread_count', '>', 0))
                    ->count(),
            ];
        });

        $data = array_merge($data, $liveData);

        // ФИКС: Вынесли данные о регистрациях для ВСЕХ ролей (админ, модератор, саппорт)
        $data['registrationData'] = Cache::remember('admin_dashboard_registrations', 600, function () {
            return $this->getRegistrationData(30);
        });
        $data['registrationCategories'] = $this->getDateCategories(30);
        $data['registrations30DaysTotal'] = array_sum($data['registrationData']);

        // Лимит 16 юзеров для ленты
        $data['recentUsers'] = User::excludeStaff()
            ->select('id', 'name', 'slug', 'created_at', 'last_seen')
            ->with(['photos' => fn($q) => $q->select('id', 'user_id', 'path_thumb', 'is_primary', 'status')->where('status', 'approved')->orderByDesc('is_primary')->limit(1)])
            ->latest()
            ->limit(16) // ← ФИКС: Было 8, стало 16
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'slug' => $u->slug,
                'created_at' => $u->created_at->diffForHumans(),
                'avatar_url' => $u->avatar_url,
                'is_online' => $u->is_online,
            ]);

        if (in_array($role, ['admin', 'support'])) {
            $data['recentLogs'] = AdminLog::with(['admin' => fn($q) => $q->select('id', 'name', 'last_seen')->with(['photos' => fn($sq) => $sq->select('id', 'user_id', 'path_thumb', 'is_primary', 'status')->orderByDesc('is_primary')->limit(1)])])
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn($l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'created_at' => $l->created_at->diffForHumans(),
                    'admin' => $l->admin ? ['name' => $l->admin->name, 'avatar_url' => $l->admin->avatar_url] : null,
                ]);
        }

        if (in_array($role, ['admin', 'moderator'])) {
            $data['activityData'] = Cache::remember('admin_dashboard_activity_v1', 600, function () {
                return $this->getActivityData();
            });
        }

        if ($role === 'admin') {
            $metrics = Cache::remember('admin_dashboard_metrics_v8', 600, function () {
                $revenueData = $this->getRevenueData(30);
                $registrationData = $this->getRegistrationData(30);

                return [
                    'revenueToday'  => Transaction::where('status', 'success')->whereDate('created_at', today())->sum('amount'),
                    'revenueMonth'  => Transaction::where('status', 'success')->where('created_at', '>=', now()->subDays(30))->sum('amount'),

                    'revenue30DaysTotal' => array_sum($revenueData),

                    'revenueData' => $revenueData,
                    'revenueCategories' => $this->getDateCategories(30),
                    'genderStats' => $this->getGenderStats(),
                    'ageStats' => $this->getAgeStats(),
                    'topCities' => $this->getTopCities(),
                ];
            });
            $data = array_merge($data, $metrics);
        }

        return response()->json($data);
    }

    private function getDateCategories(int $days): array
    {
        $categories = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $categories[] = now()->subDays($i)->format('d.m');
        }
        return $categories;
    }

    private function getRegistrationData(int $days): array
    {
        $stats = User::excludeStaff()
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as date, count(*) as total")
            ->where('created_at', '>=', now()->subDays($days))
            ->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->orderByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->pluck('total', 'date')->toArray();

        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $data[] = $stats[$date] ?? 0;
        }
        return $data;
    }

    private function getRevenueData(int $days): array
    {
        $stats = Transaction::where('status', 'success')
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as date, SUM(amount) as total")
            ->where('created_at', '>=', now()->subDays($days))
            ->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->orderByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->pluck('total', 'date')->toArray();

        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $data[] = round($stats[$date] ?? 0, 2);
        }
        return $data;
    }

    private function getActivityData(): array
    {
        $period = now()->subDays(7);

        $swipes = Swipe::selectRaw("to_char(created_at, 'YYYY-MM-DD') as date, count(*) as total")
            ->where('created_at', '>=', $period)
            ->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->orderByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->pluck('total', 'date')->toArray();

        $matches = UserMatch::selectRaw("to_char(created_at, 'YYYY-MM-DD') as date, count(*) as total")
            ->where('created_at', '>=', $period)
            ->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->orderByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->pluck('total', 'date')->toArray();

        $messages = Message::selectRaw("to_char(created_at, 'YYYY-MM-DD') as date, count(*) as total")
            ->where('created_at', '>=', $period)
            ->groupByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->orderByRaw("to_char(created_at, 'YYYY-MM-DD')")
            ->pluck('total', 'date')->toArray();

        $swipesData = [];
        $matchesData = [];
        $messagesData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $swipesData[] = $swipes[$date] ?? 0;
            $matchesData[] = $matches[$date] ?? 0;
            $messagesData[] = $messages[$date] ?? 0;
        }

        return [
            'swipes' => $swipesData,
            'matches' => $matchesData,
            'messages' => $messagesData,
            'categories' => $this->getDateCategories(7)
        ];
    }

    private function getGenderStats(): array
    {
        return UserProfile::whereNotNull('gender')->select('gender', DB::raw('count(*) as total'))->groupBy('gender')->pluck('total', 'gender')->toArray();
    }

    private function getAgeStats(): array
    {
        $stats = UserProfile::whereNotNull('birth_date')
            ->selectRaw("
                CASE 
                    WHEN extract(year from age(birth_date)) BETWEEN 18 AND 24 THEN '18-24'
                    WHEN extract(year from age(birth_date)) BETWEEN 25 AND 34 THEN '25-34'
                    WHEN extract(year from age(birth_date)) BETWEEN 35 AND 44 THEN '35-44'
                    WHEN extract(year from age(birth_date)) BETWEEN 45 AND 54 THEN '45-54'
                    WHEN extract(year from age(birth_date)) >= 55 THEN '55+'
                END as age_group, COUNT(*) as total
            ")->groupBy('age_group')->pluck('total', 'age_group')->toArray();

        return [
            '18-24' => $stats['18-24'] ?? 0,
            '25-34' => $stats['25-34'] ?? 0,
            '35-44' => $stats['35-44'] ?? 0,
            '45-54' => $stats['45-54'] ?? 0,
            '55+' => $stats['55+'] ?? 0,
        ];
    }

    private function getTopCities(): array
    {
        return UserProfile::whereNotNull('user_profiles.city_id')
            ->join('cities', 'user_profiles.city_id', '=', 'cities.id')
            ->join('countries', 'cities.country_id', '=', 'countries.id')
            ->where('countries.iso2', 'RU')
            ->select('cities.name', DB::raw('count(*) as total'))
            ->groupBy('cities.id', 'cities.name')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->pluck('total', 'cities.name')
            ->toArray();
    }
}
