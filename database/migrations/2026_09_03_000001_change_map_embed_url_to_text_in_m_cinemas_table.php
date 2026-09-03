<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `map_embed_url`（VARCHAR(255)）を `TEXT` に拡張する（A-03の作成・編集バリデーション実装時に発覚）。
 * Google マップの「地図を埋め込む」から取得する `<iframe>` の `src` は400〜700文字に達することがあり、
 * VARCHAR(255) では保存時にMariaDBのstrictモードで `Data too long` となる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_cinemas', function (Blueprint $table) {
            $table->text('map_embed_url')->change();
        });
    }

    public function down(): void
    {
        Schema::table('m_cinemas', function (Blueprint $table) {
            $table->string('map_embed_url')->change();
        });
    }
};
