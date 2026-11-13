<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================
 * Cross-table триггеры для системы интересов (упрощённая версия).
 * ============================================================
 * Создаётся ПОСЛЕ таблиц: interests, interest_categories, user_interests.
 *
 * Остались только 3 триггера:
 *   1. update_interest_category_count — счётчик интересов в категории
 *   2. update_interest_users_count — счётчик юзеров с интересом
 *   3. sync_user_profile_soft_delete — синхронизация soft-delete users → user_profiles
 *
 * Убраны (нет модерации):
 *   - check_interest_approved / check_interest_pending
 *   - cleanup_interest_on_status_change / cleanup_pending_interests_on_status_change
 *   - update_interest_pending_users_count
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. update_interest_category_count() — на interests
        // ============================================================
        // Считает сколько интересов в каждой категории (interests_count).
        // Без проверки status — все интересы считаются "approved".
        DB::statement("
            CREATE OR REPLACE FUNCTION update_interest_category_count()
            RETURNS TRIGGER AS $$             BEGIN
                IF (TG_OP = 'INSERT') THEN
                    IF NEW.category_id IS NOT NULL THEN
                        UPDATE interest_categories 
                        SET interests_count = interests_count + 1 
                        WHERE id = NEW.category_id;
                    END IF;
                    RETURN NEW;
                ELSIF (TG_OP = 'DELETE') THEN
                    IF OLD.category_id IS NOT NULL THEN
                        UPDATE interest_categories 
                        SET interests_count = interests_count - 1 
                        WHERE id = OLD.category_id;
                    END IF;
                    RETURN OLD;
                ELSIF (TG_OP = 'UPDATE') THEN
                    IF OLD.category_id IS DISTINCT FROM NEW.category_id THEN
                        IF OLD.category_id IS NOT NULL THEN
                            UPDATE interest_categories 
                            SET interests_count = interests_count - 1 
                            WHERE id = OLD.category_id;
                        END IF;
                        IF NEW.category_id IS NOT NULL THEN
                            UPDATE interest_categories 
                            SET interests_count = interests_count + 1 
                            WHERE id = NEW.category_id;
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS interests_update_category_count ON interests');
        DB::statement("
            CREATE TRIGGER interests_update_category_count
            AFTER INSERT OR DELETE OR UPDATE OF category_id ON interests
            FOR EACH ROW
            EXECUTE FUNCTION update_interest_category_count();
        ");

        // ============================================================
        // 2. update_interest_users_count() — на user_interests
        // ============================================================
        // Считает сколько юзеров выбрали интерес (users_count).
        DB::statement("
            CREATE OR REPLACE FUNCTION update_interest_users_count()
            RETURNS TRIGGER AS $$             BEGIN
                IF (TG_OP = 'INSERT') THEN
                    UPDATE interests SET users_count = users_count + 1 WHERE id = NEW.interest_id;
                    RETURN NEW;
                ELSIF (TG_OP = 'DELETE') THEN
                    UPDATE interests SET users_count = users_count - 1 WHERE id = OLD.interest_id;
                    RETURN OLD;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS user_interests_update_count ON user_interests');
        DB::statement("
            CREATE TRIGGER user_interests_update_count
            AFTER INSERT OR DELETE ON user_interests
            FOR EACH ROW
            EXECUTE FUNCTION update_interest_users_count();
        ");

        // ============================================================
        // 3. sync_user_profile_soft_delete() — на users
        // ============================================================
        // Синхронизация soft-delete: users.deleted_at → user_profiles.deleted_at
        DB::statement("
            CREATE OR REPLACE FUNCTION sync_user_profile_soft_delete()
            RETURNS TRIGGER AS $$             BEGIN
                IF NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL THEN
                    UPDATE user_profiles 
                    SET deleted_at = NOW() 
                    WHERE user_id = NEW.id AND deleted_at IS NULL;
                ELSIF NEW.deleted_at IS NULL AND OLD.deleted_at IS NOT NULL THEN
                    UPDATE user_profiles 
                    SET deleted_at = NULL 
                    WHERE user_id = NEW.id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS users_sync_profile_soft_delete ON users');
        DB::statement("
            CREATE TRIGGER users_sync_profile_soft_delete
            AFTER UPDATE OF deleted_at ON users
            FOR EACH ROW
            EXECUTE FUNCTION sync_user_profile_soft_delete();
        ");
    }

    public function down(): void
    {
        // Удаляем триггеры
        DB::statement('DROP TRIGGER IF EXISTS interests_update_category_count ON interests');
        DB::statement('DROP TRIGGER IF EXISTS user_interests_update_count ON user_interests');
        DB::statement('DROP TRIGGER IF EXISTS users_sync_profile_soft_delete ON users');

        // Удаляем функции
        DB::statement('DROP FUNCTION IF EXISTS update_interest_category_count()');
        DB::statement('DROP FUNCTION IF EXISTS update_interest_users_count()');
        DB::statement('DROP FUNCTION IF EXISTS sync_user_profile_soft_delete()');
    }
};