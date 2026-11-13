<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Models\Photo;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class OnboardingController extends Controller
{
    /**
     * Получаем существующие фото юзера для отображения в превью
     */
    public function getPhotos(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $photos = $user->photos()
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->get();

        return response()->json(['photos' => $photos]);
    }

    /**
     * Сохраняем новые фото и обновляем флаги существующих
     */
    public function save(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|min:10|max:5120',
            'intimate_flags.*' => 'boolean',
            'existing_intimate_flags.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Ошибка валидации файлов.',
                'errors' => $validator->errors()
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $existingPhotos = $user->photos()->orderByDesc('is_primary')->get();
        
        // 1. Обновляем существующие фото (флаги 18+)
        foreach ($existingPhotos as $existingPhoto) {
            $isIntimate = in_array($existingPhoto->id, $request->input('existing_intimate_flags', []));
            if ($existingPhoto->is_intimate !== $isIntimate) {
                $existingPhoto->update(['is_intimate' => $isIntimate]);
            }
        }

        // 2. Сохраняем новые фото
        if ($request->hasFile('photos')) {
            $hasPrimary = $existingPhotos->contains(fn ($p) => $p->is_primary);
            $isFirstPhoto = !$hasPrimary;
            $defaultAlbum = $user->defaultAlbum ?? Album::getDefaultForUser($user);

            foreach ($request->file('photos') as $index => $photo) {
                $path = $photo->store('photos/pending', 'public');

                Photo::create([
                    'user_id' => $user->id,
                    'album_id' => $defaultAlbum?->id,
                    'type' => Photo::TYPE_PROFILE, 
                    'path_original' => $path, 
                    'is_primary' => $isFirstPhoto && $index === 0,
                    'is_intimate' => in_array($index, $request->input('intimate_flags', [])),
                    'status' => Photo::STATUS_PENDING, 
                ]);
                
                $isFirstPhoto = false;
            }
        }
        
        // Отмечаем онбординг пройденным
        $user->update(['has_completed_onboarding' => true]);

        return response()->json(['success' => true, 'redirect' => '/verify-email']);
    }

    /**
     * Пропуск загрузки фото
     */
    public function skip(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        
        $user->update(['has_completed_onboarding' => true]);

        return response()->json(['success' => true, 'redirect' => '/verify-email']);
    }

    /**
     * Удаление фото (существующего)
     */
    public function deletePhoto(Request $request, Photo $photo): JsonResponse
    {
        // Проверка, что фото принадлежит юзеру
        if ($photo->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Доступ запрещен'], 403);
        }

        // Удаляем файлы с диска
        if ($photo->path_original) Storage::disk('public')->delete($photo->path_original);
        if ($photo->path_thumb) Storage::disk('public')->delete($photo->path_thumb);
        
        $photo->delete();

        return response()->json(['success' => true]);
    }
}