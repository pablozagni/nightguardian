<?php

use App\Ingest\HostBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('host')->nullable()->after('server');
            $table->index('host');
            // "Which installations does this issue affect?"
            $table->index(['issue_id', 'host']);
        });

        HostBackfill::run();
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['issue_id', 'host']);
            $table->dropIndex(['host']);
            $table->dropColumn('host');
        });
    }
};
