<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sevof talks to WordPress directly; the relay flag columns are no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter(
            ['uses_relay', 'relay_note'],
            fn (string $column) => Schema::hasColumn('site_connections', $column)
        ));

        if ($columns !== []) {
            Schema::table('site_connections', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    public function down(): void
    {
        Schema::table('site_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('site_connections', 'uses_relay')) {
                $table->boolean('uses_relay')->default(false);
            }
            if (! Schema::hasColumn('site_connections', 'relay_note')) {
                $table->string('relay_note')->nullable();
            }
        });
    }
};
