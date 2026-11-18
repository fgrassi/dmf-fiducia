<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dias da semana em que o aviso/rotina roda (0 domingo a 6 sábado); nulo vale o padrão do enum. */
    public function up(): void
    {
        Schema::table('notice_settings', function (Blueprint $table) {
            $table->json('weekdays')->nullable()->after('time');
        });
    }

    public function down(): void
    {
        Schema::table('notice_settings', function (Blueprint $table) {
            $table->dropColumn('weekdays');
        });
    }
};
