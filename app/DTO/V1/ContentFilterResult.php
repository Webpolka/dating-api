<?php

namespace App\DTO\V1;

/**
 * Результат проверки текста через ContentFilterService.
 *
 *Immutable DTO — создаётся внутри ContentFilterService::check(), возвращается
 * вызывающему коду. Вызывающий код читает поля и решает что делать:
 *   - hasReject → НЕ сохранять контент, вернуть 422 юзеру
 *   - alerts[] → сохранить контент, но создать FraudAlert записи
 *   - maskedText → сохранить именно этот (замаскированный) текст
 *   - matched[] → для логирования/аудита
 */
class ContentFilterResult
{
    public function __construct(
        /** Оригинальный текст (как пришёл от юзера) */
        public readonly string $originalText,

        /**
         * Текст после маскирования mask-слов.
         * Если mask-слов не было — равен originalText.
         * Это то, что нужно сохранять в БД.
         */
        public string $maskedText = '',

        /**
         * Все найденные стоп-слова (любого action).
         * Каждый элемент: {id, word, category, action, position}
         */
        public array $matched = [],

        /**
         * True если найдено хотя бы одно reject-слово.
         * Вызывающий код должен ОТКЛОНИТЬ контент (не сохранять).
         */
        public bool $hasReject = false,

        /**
         * Список alert-слов для создания FraudAlert записей.
         * Каждый элемент: {stop_word_id, word, category}
         * Контент сохраняется, но параллельно создаётся алерт.
         */
        public array $alerts = [],
    ) {
        // Если maskedText не передан — по умолчанию = originalText
        if ($this->maskedText === '') {
            $this->maskedText = $originalText;
        }
    }

    /**
     * Были ли найдены вообще какие-то стоп-слова.
     */
    public function hasMatches(): bool
    {
        return !empty($this->matched);
    }

    /**
     * Были ли найдены alert-слова (нужно создать FraudAlert).
     */
    public function hasAlerts(): bool
    {
        return !empty($this->alerts);
    }

    /**
     * True если текст прошёл проверку (нет reject-слов).
     * Контент можно сохранять (с маскированным текстом если есть mask-слова).
     */
    public function isApproved(): bool
    {
        return !$this->hasReject;
    }
}
