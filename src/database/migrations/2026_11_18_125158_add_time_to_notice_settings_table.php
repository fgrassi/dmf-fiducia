<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Horário (H:i) em que o aviso roda; nulo vale o padrão do enum App\Enums\Notice. */
    public function up(): void
    {
        Schema::table('notice_settings', function (Blueprint $table) {
            $table->string('time', 5)->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('notice_settings', function (Blueprint $table) {
            $table->dropColumn('time');
        });
    }
};
