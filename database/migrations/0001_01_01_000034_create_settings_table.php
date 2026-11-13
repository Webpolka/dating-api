<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            
            //  enum (защита от опечаток в коде, битые формы)
            $table->enum('type', ['text', 'textarea', 'boolean', 'integer', 'select', 'json'])->default('text'); 
            
            // ИЗМЕНЕНО: json -> jsonb (для быстрого парсинга в PostgreSQL)
            $table->jsonb('options')->nullable(); 
            
            $table->boolean('is_public')->default(false);
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            // ИЗМЕНЕНО: Объединили в один составной (для API: WHERE is_public = true AND group = 'limits')
            $table->index(['is_public', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

// Как это будет работать в Livewire (представь картину):

// В админке ты делаешь страницу "Настройки". Ты делаешь запрос Setting::where('group', 'limits')->get().
// Livewire проходится циклом по настройкам:

// php

// @foreach($settings as $setting)
//     @if($setting->type == 'boolean')
//         <x-toggle wire:model="settings.{{ $setting->key }}" :label="$setting->label" />
//     @elseif($setting->type == 'text')
//         <x-input wire:model="settings.{{ $setting->key }}" :label="$setting->label" :hint="$setting->description" />
//     @endif
// @endforeach
// И у тебя автоматически строится форма!