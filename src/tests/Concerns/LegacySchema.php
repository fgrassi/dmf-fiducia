<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/** Cria as tabelas do banco legado (tracking) no SQLite em memória do teste (setUpTraits chama setUpLegacySchema). */
trait LegacySchema
{
    protected function setUpLegacySchema(): void
    {
        DB::unprepared(file_get_contents(base_path('tests/Fixtures/legacy-schema.sql')));
    }
}
