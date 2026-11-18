<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ajuste por aviso automático (App\Enums\Notice): liga/desliga e troca os destinatários; sem linha vale o padrão do enum. */
    public function up(): void
    {
        Schema::create('notice_settings', function (Blueprint $table) {
            $table->id();
            $table->string('notice')->unique();
            $table->boolean('enabled')->default(true);
            $table->json('recipients')->nullable();
            $table->json('cc')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_settings');
    }
};
