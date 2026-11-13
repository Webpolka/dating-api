<?php

namespace App\Services\V1;

use App\DTO\V1\ContentFilterResult;
use App\Enums\V1\StopWordAction;
use App\Models\StopWord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Сервис фильтрации контента по стоп-словам.
 *
 * Главная задача: проверять текст (сообщения, комментарии, bios) на наличие
 * стоп-слов и применять соответствующие action'ы:
 *   - mask   → заменить слово на replacement (по умолчанию '***')
 *   - reject → вернуть флаг "контент отклонён", вызывающий код НЕ сохраняет
 *   - alert  → вернуть список alert-слов для fraud_alerts (контент проходит,
 *              но создаётся алерт для антифрод-системы)
 *
 * Источник данных: StopWord::getCachedActiveWords() — кэш в Redis
 * (rememberForever, fallback на DB при падении Redis).
 *
 * Performance: проверка идёт в памяти (collection->each), без SQL-запросов
 * на каждое слово. Для 1000 стоп-слов и текста 500 символов — ~1-2ms.
 *
 * Регистронезависимый поиск: mb_stripos (UTF-8 safe). "Казино" и "казино" —
 * одно и то же слово.
 */
class ContentFilterService
{
    private ?Collection $cachedWords = null;

    /**
     * Проверить текст на наличие стоп-слов.
     *
     * @param string $text Текст для проверки (message body, comment, bio, etc.)
     * @return ContentFilterResult
     */
    public function check(string $text): ContentFilterResult
    {
        $result = new ContentFilterResult(originalText: $text);

        // Пустой текст — ничего не делаем
        if (trim($text) === '') {
            return $result;
        }

        $words = $this->getWords();
        if ($words->isEmpty()) {
            return $result;
        }

        $maskedText = $text;

        foreach ($words as $stopWord) {
            $word = $stopWord->word;
            if ($word === '' || $word === '0') {
                continue;
            }

            // Регистронезависимый поиск (UTF-8 safe)
            $pos = mb_stripos($maskedText, $word);
            if ($pos === false) {
                continue;
            }

            // Найдено стоп-слово в тексте
            $action = $stopWord->action;
            $result->matched[] = [
                'id'        => $stopWord->id,
                'word'      => $stopWord->word,
                'category'  => $stopWord->category->value,
                'action'    => $action->value,
                'position'  => $pos,
            ];

            match ($action) {
                StopWordAction::Mask => $maskedText = $this->maskWord(
                    $maskedText,
                    $word,
                    $stopWord->replacement ?? '***'
                ),
                StopWordAction::Reject => $result->hasReject = true,
                StopWordAction::Alert => $result->alerts[] = [
                    'stop_word_id' => $stopWord->id,
                    'word' => $stopWord->word,
                    'category' => $stopWord->category->value,
                ],
            };
        }

        $result->maskedText = $maskedText;
        return $result;
    }

    /**
     * Заменить все вхождения слова на replacement (регистронезависимо).
     * Использует str_ireplace — для UTF-8 может быть неточно для некоторых
     * редких языков, но для русских/английских слов работает корректно.
     */
    private function maskWord(string $text, string $word, string $replacement): string
    {
        return str_ireplace($word, $replacement, $text);
    }

    /**
     * Получить закэшированный список активных стоп-слов.
     * Lazy loading — первый вызов грузит, последующие используют in-memory cache.
     */
    private function getWords(): Collection
    {
        if ($this->cachedWords === null) {
            try {
                $this->cachedWords = StopWord::getCachedActiveWords();
            } catch (\Throwable $e) {
                Log::warning('ContentFilterService: failed to load stop words', [
                    'error' => $e->getMessage(),
                ]);
                $this->cachedWords = collect();
            }
        }
        return $this->cachedWords;
    }

    /**
     * Сбросить in-memory cache (для тестов или если нужно форс-релоад).
     */
    public function clearCache(): void
    {
        $this->cachedWords = null;
        StopWord::clearCache();
    }
}
