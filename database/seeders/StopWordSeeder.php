<?php

namespace Database\Seeders;

use App\Enums\V1\StopWordAction;
use App\Enums\V1\StopWordCategory;
use App\Models\StopWord;
use Illuminate\Database\Seeder;

class StopWordSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🛑 Заполняем базу стоп-слов и правил антифрода...');
 
        $words = [
            // ============================================
            // 1. МАТ И ОСКОРБЛЕНИЯ (Маскируем звездочками)
            // ============================================
            ['word' => 'хуй', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'пизда', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'ебан', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'бляд', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'гандон', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'мудак', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'уебок', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'пидор', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'],
            ['word' => 'черт', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***'], // Мягкий мат, тоже маскируем

            // ============================================
            // 2. КОНТАКТЫ И ССЫЛКИ (H2H - Honeypot / Западня)
            // Мы НЕ блокируем их (action: alert). Скаммер думает, что сообщение ушло, 
            // а мы кидаем алерт в fraud_alerts для теневого бана.
            // ============================================
            ['word' => 'телеграм', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'telegram', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => ' тг ', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value], // С пробелами, чтобы не резать слово "тг" внутри других слов
            ['word' => 'ватсап', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'whatsapp', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'вайбер', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'viber', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'напиши мне в', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'пишите в', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value],
            ['word' => '@', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value], // Собачка перед никами
            ['word' => 't.me/', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value], // Ссылка на ТГ

            // ============================================
            // 3. МОШЕННИЧЕСТВО И СПАМ (Тоже Honeypot)
            // ============================================
            ['word' => 'криптовалюта', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'биткоин', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'инвестици', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'заработок', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'казино', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'ставк', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'перевод', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],
            ['word' => 'кинь деньги', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value],

            // ============================================
            // 4. ПРОСТИТУЦИЯ (Жесткий Reject - даже не сохраняем)
            // ============================================
            ['word' => 'досуг', 'category' => StopWordCategory::Prostitution->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'индивидуалка', 'category' => StopWordCategory::Prostitution->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'проститутк', 'category' => StopWordCategory::Prostitution->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'пута', 'category' => StopWordCategory::Prostitution->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'массаж с продолжением', 'category' => StopWordCategory::Prostitution->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'интим за деньги', 'category' => StopWordCategory::Prostitution->value, 'action' => StopWordAction::Reject->value],

            // ============================================
            // 5. НАРКОТИКИ (Жесткий Reject)
            // ============================================
            ['word' => 'наркотик', 'category' => StopWordCategory::Drugs->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'кокаин', 'category' => StopWordCategory::Drugs->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'мефедрон', 'category' => StopWordCategory::Drugs->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'скорость', 'category' => StopWordCategory::Drugs->value, 'action' => StopWordAction::Reject->value], // В контексте сленга
            ['word' => 'гашиш', 'category' => StopWordCategory::Drugs->value, 'action' => StopWordAction::Reject->value],
            ['word' => 'травк', 'category' => StopWordCategory::Drugs->value, 'action' => StopWordAction::Reject->value],

            // ============================================
            // 6. НЕЦЕНЗУРНАЯ ЛЕКСИКА (18+ термины, маскируем в публичных местах)
            // ============================================
            ['word' => 'хуйня', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***ня'],
            ['word' => 'пиздец', 'category' => StopWordCategory::Mat->value, 'action' => StopWordAction::Mask->value, 'replacement' => '***ец'],
            
            // ============================================
            // 7. РЕГУЛЯРНЫЕ ВЫРАЖЕНИЯ (Для телефонов и карт)
            // В Laravel мы будем preg_match, поэтому можно хранить паттерн
            // ============================================
            ['word' => '/\+7\s?\(?\d{3}\)?\s?\d{3}-?\d{2}-?\d{2}/', 'category' => StopWordCategory::Contacts->value, 'action' => StopWordAction::Alert->value], // Телефоны РФ
            ['word' => '/\d{16}/', 'category' => StopWordCategory::Scam->value, 'action' => StopWordAction::Alert->value], // Номер карты (16 цифр подряд)
        ];

        $created = 0;
        $updated = 0;

        $bar = $this->command->getOutput()->createProgressBar(count($words));

        foreach ($words as $wordData) {
            // Дефолтные значения, если не заданы
            $wordData['replacement'] = $wordData['replacement'] ?? '***';
            $wordData['is_active'] = $wordData['is_active'] ?? true;

            $model = StopWord::updateOrCreate(
                ['word' => $wordData['word']], // Уникальный ключ — само слово илиregex
                $wordData
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $stats = [
            'total' => StopWord::count(),
            'active' => StopWord::where('is_active', true)->count(),
            'mat' => StopWord::where('category', StopWordCategory::Mat->value)->count(),
            'contacts' => StopWord::where('category', StopWordCategory::Contacts->value)->count(),
            'scam' => StopWord::where('category', StopWordCategory::Scam->value)->count(),
            'prostitution' => StopWord::where('category', StopWordCategory::Prostitution->value)->count(),
            'drugs' => StopWord::where('category', StopWordCategory::Drugs->value)->count(),
            'mask' => StopWord::where('action', StopWordAction::Mask->value)->count(),
            'reject' => StopWord::where('action', StopWordAction::Reject->value)->count(),
            'alert' => StopWord::where('action', StopWordAction::Alert->value)->count(),
        ];

        $this->command->info('✅ Стоп-слова созданы/обновлены:');
        $this->command->info("   - Создано: {$created}");
        $this->command->info("   - Обновлено: {$updated}");
        $this->command->info('');
        $this->command->info('📊 Статистика каталога:');
        $this->command->info("   ┌─────────────────────────┬──────────┐");
        $this->command->info("   │ Показатель              │ Кол-во   │");
        $this->command->info("   ├─────────────────────────┼──────────┤");
        $this->command->info("   │ Всего слов              │ {$stats['total']}        │");
        $this->command->info("   │ Активных                │ {$stats['active']}        │");
        $this->command->info("   ├─────────────────────────┼──────────┤");
        $this->command->info("   │ Мат (Маскировка)        │ {$stats['mat']}        │");
        $this->command->info("   │ Контакты (Западня)      │ {$stats['contacts']}        │");
        $this->command->info("   │ Мошенничество (Западня) │ {$stats['scam']}        │");
        $this->command->info("   │ Проституция (Блок)      │ {$stats['prostitution']}        │");
        $this->command->info("   │ Наркотики (Блок)        │ {$stats['drugs']}        │");
        $this->command->info("   ├─────────────────────────┼──────────┤");
        $this->command->info("   │ Действие: Mask          │ {$stats['mask']}        │");
        $this->command->info("   │ Действие: Reject        │ {$stats['reject']}        │");
        $this->command->info("   │ Действие: Alert 🔥      │ {$stats['alert']}        │");
        $this->command->info("   └─────────────────────────┴──────────┘");
        
        $this->command->warn('💡 Подсказка: Слова с action=alert пропускают текст, но кидают алерт в fraud_alerts!');
    }
}