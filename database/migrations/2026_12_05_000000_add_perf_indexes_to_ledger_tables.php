<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite tenant+date indexes for the ledger list pages.
 *
 * NOTE ON ORDERING: team_id is added to these ledger tables by
 * 2026_11_26_064418_add_team_to_resources, so this migration MUST be dated
 * after it — otherwise on a fresh install the column does not exist yet and
 * index creation fails. (An earlier revision was dated 2026_09_16 and thus
 * only worked against an already-populated database; it broke clean
 * `migrate` runs on MySQL/PostgreSQL/SQLite.)
 *
 * Each step is guarded so the migration is idempotent and driver-agnostic:
 * it is a no-op if the table/column is missing, or if the index already
 * exists (e.g. created manually on a database that predates this revision).
 *
 * Single-column indexes on the FK columns already existed; these composites
 * target the Filament list pattern (WHERE team_id = ? ORDER BY <date>).
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, date: string, index: string}> */
    private array $targets = [
        ['table' => 'transactions', 'date' => 'transaction_date', 'index' => 'idx_transactions_team_date'],
        ['table' => 'invoices', 'date' => 'invoice_date', 'index' => 'idx_invoices_team_invoice_date'],
        ['table' => 'journal_entries', 'date' => 'entry_date', 'index' => 'idx_journal_entries_team_date'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $target) {
            if (! Schema::hasTable($target['table'])
                || ! Schema::hasColumn($target['table'], 'team_id')
                || ! Schema::hasColumn($target['table'], $target['date'])
                || $this->indexExists($target['table'], $target['index'])) {
                continue;
            }

            Schema::table($target['table'], function (Blueprint $blueprint) use ($target): void {
                $blueprint->index(['team_id', $target['date']], $target['index']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $target) {
            if (! Schema::hasTable($target['table']) || ! $this->indexExists($target['table'], $target['index'])) {
                continue;
            }

            Schema::table($target['table'], function (Blueprint $blueprint) use ($target): void {
                $blueprint->dropIndex($target['index']);
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};