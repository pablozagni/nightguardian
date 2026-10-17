<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->text('config');
            $table->string('min_level', 16)->default('error');
            $table->boolean('notify_new')->default(true);
            $table->boolean('notify_regressed')->default(true);
            $table->boolean('notify_threshold')->default(false);
            $table->boolean('include_digest')->default(false);
            $table->boolean('enabled')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('field', 64);
            $table->enum('operator', ['equals', 'contains', 'starts_with', 'regex']);
            $table->string('value', 500);
            $table->enum('action', ['always_notify', 'never_notify', 'set_level_critical']);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issue_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kind', ['new', 'regressed', 'threshold', 'rule', 'digest', 'test']);
            $table->enum('status', ['sent', 'failed', 'suppressed']);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('alert_channels');
    }
};
