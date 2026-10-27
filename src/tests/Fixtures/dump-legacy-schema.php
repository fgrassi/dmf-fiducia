<?php

/**
 * Regenera tests/Fixtures/legacy-schema.sql (SQLite) a partir do information_schema do banco legado (tracking).
 * As tabelas legadas não têm migrations; os testes carregam esta fixture (Tests\Concerns\LegacySchema).
 *
 * Uso: docker exec fiducia_app php artisan tinker tests/Fixtures/dump-legacy-schema.php
 */

use Illuminate\Support\Facades\DB;

$schema = DB::getDatabaseName();

$type = fn (string $mysql) => match ($mysql) {
    'int', 'tinyint', 'smallint', 'bigint', 'year' => 'INTEGER',
    'float', 'double' => 'REAL',
    'decimal' => 'NUMERIC',
    'blob', 'longblob' => 'BLOB',
    default => 'TEXT',
};

$default = function (object $c): string {
    if ($c->COLUMN_DEFAULT === null) {
        return '';
    }

    return match (true) {
        str_starts_with(strtoupper($c->COLUMN_DEFAULT), 'CURRENT_TIMESTAMP') => ' DEFAULT CURRENT_TIMESTAMP',
        is_numeric($c->COLUMN_DEFAULT) && $c->DATA_TYPE !== 'varchar' && $c->DATA_TYPE !== 'char' => " DEFAULT {$c->COLUMN_DEFAULT}",
        default => " DEFAULT '".str_replace("'", "''", $c->COLUMN_DEFAULT)."'",
    };
};

$columns = collect(DB::select('select * from information_schema.columns where table_schema = ? order by table_name, ordinal_position', [$schema]))
    ->groupBy('TABLE_NAME');
$uniques = collect(DB::select("select table_name as TABLE_NAME, index_name as INDEX_NAME, column_name as COLUMN_NAME from information_schema.statistics
    where table_schema = ? and non_unique = 0 and index_name <> 'PRIMARY' order by table_name, index_name, seq_in_index", [$schema]))
    ->groupBy(fn ($i) => "{$i->TABLE_NAME}|{$i->INDEX_NAME}");

$sql = "-- Gerado por tests/Fixtures/dump-legacy-schema.php. Não editar à mão.\n";

foreach ($columns as $table => $cols) {
    $pk = $cols->where('COLUMN_KEY', 'PRI')->pluck('COLUMN_NAME');
    $auto = $cols->first(fn ($c) => str_contains($c->EXTRA, 'auto_increment'));
    $lines = $cols->map(function ($c) use ($type, $default, $auto, $pk) {
        if ($auto && $c->COLUMN_NAME === $auto->COLUMN_NAME && $pk->count() === 1) {
            return "\"{$c->COLUMN_NAME}\" INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        return "\"{$c->COLUMN_NAME}\" {$type($c->DATA_TYPE)}".($c->IS_NULLABLE === 'NO' ? ' NOT NULL' : '').$default($c);
    });

    if ($pk->isNotEmpty() && ! ($auto && $pk->count() === 1)) {
        $lines->push('PRIMARY KEY ('.$pk->map(fn ($c) => "\"{$c}\"")->implode(', ').')');
    }

    $sql .= "CREATE TABLE \"{$table}\" (".$lines->implode(', ').");\n";
}

foreach ($uniques as $key => $index) {
    [$table, $name] = explode('|', $key);
    $sql .= "CREATE UNIQUE INDEX \"{$table}_{$name}\" ON \"{$table}\" (".$index->map(fn ($i) => "\"{$i->COLUMN_NAME}\"")->implode(', ').");\n";
}

file_put_contents(base_path('tests/Fixtures/legacy-schema.sql'), $sql);

echo $columns->count()." tabelas\n";
