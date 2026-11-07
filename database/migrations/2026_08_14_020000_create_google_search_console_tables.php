<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_search_console_connections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scope')->nullable();
            $table->string('status')->default('connected');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('site_search_console_properties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('google_search_console_connection_id')
                ->constrained(
                    'google_search_console_connections',
                    'id',
                    'gsc_property_connection_fk'
                )
                ->cascadeOnDelete();

            $table->string('property_url', 2048);
            $table->string('permission_level')->nullable();
            $table->string('sync_status')->default('idle');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique('site_id');
        });

        Schema::create('search_console_query_metrics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('site_search_console_property_id')
                ->constrained(
                    'site_search_console_properties',
                    'id',
                    'sc_metrics_property_fk'
                )
                ->cascadeOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->string('query', 1024);
            $table->string('page', 2048)->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('position', 10, 4)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(
                ['site_id', 'impressions'],
                'sc_metrics_site_impressions_idx'
            );

            $table->index(
                [
                    'site_search_console_property_id',
                    'start_date',
                    'end_date'
                ],
                'sc_metrics_property_dates_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_console_query_metrics');
        Schema::dropIfExists('site_search_console_properties');
        Schema::dropIfExists('google_search_console_connections');
    }
};
