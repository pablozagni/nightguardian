<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('uuid', 36)->unique();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issue_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('level', 16)->nullable();
            $table->char('reference', 12)->nullable();
            $table->timestamp('occurred_at', 3);
            $table->timestamp('received_at', 3);
            $table->string('environment', 64)->nullable();
            $table->string('release', 128)->nullable();
            $table->string('server')->nullable();
            $table->string('sdk_name', 64)->nullable();
            $table->string('sdk_version', 32)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('message', 1024)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->string('request_route')->nullable();
            $table->string('request_url', 1024)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('user_identifier', 191)->nullable();
            $table->json('payload');

            $table->index(['project_id', 'occurred_at']);
            $table->index(['issue_id', 'occurred_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
