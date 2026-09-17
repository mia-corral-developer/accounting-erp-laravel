<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite tenant+date indexes for the hot list pages.
 *
 * Note: single-column indexes on invoices.customer_id, invoices.team_id,
 * transactions.team_id and journal_entries.entry_date already existed (FK
 * indexes). These composites target the Filament list pattern
 * (WHERE team_id = ? ORDER BY <date>) which the single indexes don't cover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['team_id', 'transaction_date'], 'idx_transactions_team_date');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['team_id', 'invoice_date'], 'idx_invoices_team_invoice_date');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['team_id', 'entry_date'], 'idx_journal_entries_team_date');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_team_date');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_team_invoice_date');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('idx_journal_entries_team_date');
        });
    }
};