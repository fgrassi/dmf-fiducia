<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\ArchiveCovers;
use App\Actions\AccountingCovers\CreateCoverFolder;
use App\Models\CapaProcesso;
use App\Models\CapaProcessoArquivo;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CoverFolderActionsTest extends TestCase
{
    use LegacySchema;

    public function test_new_folder_opens_next_month_and_closes_the_current_one(): void
    {
        $current = $this->folder('SETEMBRO/2026', 9, 2026);

        $new = app(CreateCoverFolder::class)();

        $this->assertSame(['OUTUBRO/2026', 10, 2026, 1], [$new->nome, $new->mes, $new->ano, $new->status]);
        $this->assertSame(0, (int) $current->fresh()->status);
    }

    public function test_new_folder_rolls_over_the_year(): void
    {
        $this->folder('DEZEMBRO/2026', 12, 2026);

        $new = app(CreateCoverFolder::class)();

        $this->assertSame(['JANEIRO/2027', 1, 2027], [$new->nome, $new->mes, $new->ano]);
    }

    public function test_new_folder_keeps_the_accent_of_march(): void
    {
        $this->folder('FEVEREIRO/2026', 2, 2026);

        $this->assertSame('MARÇO/2026', app(CreateCoverFolder::class)()->nome);
    }

    public function test_new_folder_is_refused_while_a_cover_is_unarchived(): void
    {
        $this->folder('SETEMBRO/2026', 9, 2026);
        CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 2]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Existem capas não arquivadas');

        app(CreateCoverFolder::class)();
    }

    public function test_new_folder_is_refused_while_a_manual_cover_is_unarchived(): void
    {
        $this->folder('SETEMBRO/2026', 9, 2026);
        DB::table('capa_processo_manual')->insert(['moeda_id' => 1, 'ref_currency_br' => 1, 'ref_currency_sr' => 1]);

        $this->expectException(DomainException::class);

        app(CreateCoverFolder::class)();
    }

    public function test_new_folder_needs_an_open_folder(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não há caixa aberta');

        app(CreateCoverFolder::class)();
    }

    public function test_archive_moves_only_the_given_covers(): void
    {
        $folder = $this->folder('SETEMBRO/2026', 9, 2026);
        $first = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 2]);
        $second = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 2]);

        $count = app(ArchiveCovers::class)([$first->getKey()], $folder);

        $this->assertSame(1, $count);
        $this->assertSame($folder->getKey(), $first->fresh()->capa_processo_arquivo_id);
        $this->assertNull($second->fresh()->capa_processo_arquivo_id);
    }

    private function folder(string $name, int $month, int $year, int $status = 1): CapaProcessoArquivo
    {
        return CapaProcessoArquivo::create(['nome' => $name, 'mes' => $month, 'ano' => $year, 'status' => $status]);
    }
}
