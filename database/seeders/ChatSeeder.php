<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\User;
use App\Models\ChatParticipant;
use App\Models\Message;
use App\Models\UserMatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChatSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('💬 Генерация чатов на основе матчей (массовая вставка)...');

        $matches = UserMatch::whereHas('user1', fn($q) => $q->where('role', 'user'))
                     ->whereHas('user2', fn($q) => $q->where('role', 'user'))
                     ->get();

        if ($matches->isEmpty()) {
            $this->command->warn('⚠️ Нет матчей в базе!');
            return;
        }

        // Очистка
        Message::query()->delete();
        ChatParticipant::query()->delete();
        Chat::query()->delete();

        // Предзагрузка премиум-юзеров для O(1) проверки
        $premiumUserIds = User::where('premium_expires_at', '>', now())->pluck('id')->toArray();
        
        $chatsData = [];
        $participantsData = [];
        $hashes = [];
        $now = now();

        // 1. ФОРМИРУЕМ ЧАТЫ И УЧАСТНИКОВ В ПАМЯТИ
        foreach ($matches as $match) {
            $hash = md5(min($match->user1_id, $match->user2_id) . '-' . max($match->user1_id, $match->user2_id));
            $hashes[] = $hash;
            
            $chatsData[] = [
                'type' => Chat::TYPE_PRIVATE,
                'participants_hash' => $hash,
                'last_message_at' => null, // Обновим позже
                'is_locked' => false,
                'created_at' => $now->copy()->subDays(rand(1, 10)),
                'updated_at' => $now,
            ];
        }

        Chat::insert($chatsData);
        
        // Получаем ID созданных чатов по хэшу
        $chatIds = Chat::whereIn('participants_hash', $hashes)->pluck('id', 'participants_hash')->toArray();

        // Создаем участников
        foreach ($matches as $match) {
            $hash = md5(min($match->user1_id, $match->user2_id) . '-' . max($match->user1_id, $match->user2_id));
            $chatId = $chatIds[$hash];
            
            $participantsData[] = [
                'chat_id' => $chatId, 'user_id' => $match->user1_id,
                'unread_count' => 0, 'last_read_at' => null,
                'is_hidden' => false, 'is_muted' => false, 'is_blocked' => false,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $participantsData[] = [
                'chat_id' => $chatId, 'user_id' => $match->user2_id,
                'unread_count' => 0, 'last_read_at' => null,
                'is_hidden' => false, 'is_muted' => false, 'is_blocked' => false,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        ChatParticipant::insert($participantsData);

        // Мапа ID участников для обновления unread_count
        $participants = ChatParticipant::whereIn('chat_id', array_values($chatIds))->get();
        $participantIdMap = [];
        foreach ($participants as $p) {
            $participantIdMap[$p->chat_id][$p->user_id] = $p->id;
        }

        // 2. ГЕНЕРАЦИЯ СООБЩЕНИЙ В ПАМЯТИ
        $messagesData = [];
        $chatUpdates = [];
        $participantUpdates = [];
        $chatSenderCounts = []; // Для подсчета непрочитанных

        $phrases = ['Привет! Как дела?', 'Отлично, а у тебя?', 'Чем занимаешься?', 'Смотрю сериал, а ты?', 'Какие планы на выходные?', 'Может, сходим куда-нибудь?', 'У тебя красивое фото!', 'Какой твой любимый фильм?'];
        $replies = ['Неплохо, спасибо)', 'Хорошо, работаю', 'Отдыхаю, а ты?', 'Планирую поездку', 'Почему бы и нет!', 'Спасибо!', 'Люблю читать и рисовать', 'Мой любимый - Интерстеллар'];

        $bar = $this->command->getOutput()->createProgressBar($matches->count());

        foreach ($matches as $match) {
            $hash = md5(min($match->user1_id, $match->user2_id) . '-' . max($match->user1_id, $match->user2_id));
            $chatId = $chatIds[$hash];
            $u1 = $match->user1_id;
            $u2 = $match->user2_id;

            $isPremium = in_array($u1, $premiumUserIds) || in_array($u2, $premiumUserIds);
            $lastMessageTime = null;
            $chatSenderCounts[$chatId] = [$u1 => 0, $u2 => 0];

            $senderId = rand(0, 1) ? $u1 : $u2;
            $recipientId = $senderId == $u1 ? $u2 : $u1;
            $currentTime = $now->copy()->subDays(rand(1, 10));

            if ($isPremium) {
                $msgCount = rand(5, 12);
                for ($i = 0; $i < $msgCount; $i++) {
                    $currentSenderId = ($i % 2 === 0) ? $senderId : $recipientId;
                    $phrase = ($i % 2 === 0) ? $phrases[array_rand($phrases)] : $replies[array_rand($replies)];
                    
                    $messagesData[] = [
                        'chat_id' => $chatId, 'sender_id' => $currentSenderId,
                        'type' => Message::TYPE_TEXT, 'body' => $phrase, 'status' => 'approved',
                        'created_at' => $currentTime->copy(), 'updated_at' => $currentTime->copy(),
                    ];
                    $chatSenderCounts[$chatId][$currentSenderId]++;
                    $lastMessageTime = $currentTime->copy();
                    $currentTime->addMinutes(rand(1, 10));
                }
            } else {
                $scenario = rand(0, 3);
                if ($scenario > 0) {
                    for ($i = 0; $i < $scenario; $i++) {
                        $phrase = ($i % 2 === 0) ? $phrases[array_rand($phrases)] : $replies[array_rand($replies)];
                        $messagesData[] = [
                            'chat_id' => $chatId, 'sender_id' => $senderId,
                            'type' => Message::TYPE_TEXT, 'body' => $phrase, 'status' => 'approved',
                            'created_at' => $currentTime->copy(), 'updated_at' => $currentTime->copy(),
                        ];
                        $chatSenderCounts[$chatId][$senderId]++;
                        $lastMessageTime = $currentTime->copy();
                        $currentTime->addMinutes(2);

                        if (rand(0, 1) && $i < $scenario - 1) {
                            $messagesData[] = [
                                'chat_id' => $chatId, 'sender_id' => $recipientId,
                                'type' => Message::TYPE_TEXT, 'body' => $replies[array_rand($replies)], 'status' => 'approved',
                                'created_at' => $currentTime->copy(), 'updated_at' => $currentTime->copy(),
                            ];
                            $chatSenderCounts[$chatId][$recipientId]++;
                            $lastMessageTime = $currentTime->copy();
                            $currentTime->addMinutes(2);
                        }
                    }

                    if ($scenario === 3) {
                        $messagesData[] = [
                            'chat_id' => $chatId, 'sender_id' => null,
                            'type' => Message::TYPE_SYSTEM, 'body' => '⚠️ Вы исчерпали лимит бесплатных сообщений. Для продолжения переписки необходима подписка Premium.', 'status' => 'approved',
                            'created_at' => $currentTime->copy(), 'updated_at' => $currentTime->copy(),
                        ];
                        $lastMessageTime = $currentTime->copy();
                    }
                }
            }

            // Обновляем чат и участников в памяти
            if ($lastMessageTime) {
                $chatUpdates[] = ['id' => $chatId, 'last_message_at' => $lastMessageTime];
                
                // Unread: u1 непрочитал сообщения от u2, и наоборот
                $u1Unread = $chatSenderCounts[$chatId][$u2];
                $u2Unread = $chatSenderCounts[$chatId][$u1];
                
                $participantUpdates[] = ['id' => $participantIdMap[$chatId][$u1], 'unread_count' => $u1Unread];
                $participantUpdates[] = ['id' => $participantIdMap[$chatId][$u2], 'unread_count' => $u2Unread];
            }
            $bar->advance();
        }

        // 3. ВСТАВЛЯЕМ СООБЩЕНИЯ И ОБНОВЛЯЕМ СВЯЗИ
        if (!empty($messagesData)) {
            // Разбиваем на чанки, если сообщений слишком много (Postgres limit)
            foreach (array_chunk($messagesData, 500) as $chunk) {
                Message::insert($chunk);
            }
        }

        if (!empty($chatUpdates)) {
            Chat::upsert($chatUpdates, ['id'], ['last_message_at']);
        }
        foreach ($participantUpdates as $update) {
            ChatParticipant::where('id', $update['id'])->update(['unread_count' => $update['unread_count']]);
        }

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('✅ Чаты и сообщения сгенерированы!');
        $this->command->info("   • Чатов: " . count($chatsData));
        $this->command->info("   • Сообщений: " . count($messagesData));
    }
}