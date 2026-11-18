<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Assunto e corpo (com {{variáveis}}) editados na tela; nulos valem o texto padrão de App\Actions\Notices\NoticeTexts. */
    public function up(): void
    {
        Schema::table('notice_settings', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('time');
            $table->longText('body')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('notice_settings', function (Blueprint $table) {
            $table->dropColumn(['subject', 'body']);
        });
    }
};
