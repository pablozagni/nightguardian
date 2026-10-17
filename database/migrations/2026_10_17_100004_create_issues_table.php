<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->string('culprit')->nullable();
            $table->string('exception_class')->nullable();
            $table->string('level', 16);
            $table->enum('status', ['new', 'regressed', 'resolved', 'ignored'])->default('new');
            $table->unsignedBigInteger('events_count')->default(0);
            $table->unsignedInteger('users_count')->default(0);
            $table->json('environments');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('first_release')->nullable();
            $table->string('last_release')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolved_in_release')->nullable();
            $table->timestamp('ignored_until')->nullable();
            $table->timestamp('last_alerted_at')->nullable();
            $table->char('merged_into_id', 26)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status', 'last_seen_at']);
            $table->index(['project_id', 'last_seen_at']);
            $table->index('first_seen_at');
            $table->index('last_seen_at');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('issues')->nullOnDelete();
        });

        Schema::create('issue_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 40);
            $table->foreignUlid('issue_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_fingerprints');
        Schema::dropIfExists('issues');
    }
};
