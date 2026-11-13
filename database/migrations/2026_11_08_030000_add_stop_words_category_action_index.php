<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет partial index (category, action) WHERE is_active = true.
 *
 * Старый индекс (только category) покрывал фильтрацию по category.
 * Новый — по category AND action, что соответствует запросу в index():
 *   WHERE is_active = true AND category = ? AND action = ?
 *
 * Старый индекс stop_words_active_category_index НЕ удаляем — он полезен
 * для ContentFilterService который грузит все active words по category.
 * Новый индекс — для админки (фильтрация в list view).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX stop_words_active_category_action_idx ' .
                'ON stop_words (category, action) ' .
                "WHERE is_active = true"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stop_words_active_category_action_idx');
    }
};
