<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issue_id')->nullable()->constrained()->nullOnDelete();
            $table->char('event_uuid', 36)->nullable()->index();
            $table->char('reference', 12)->nullable()->index();
            $table->text('comment');
            $table->string('email')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('deploys', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 64);
            $table->string('release', 128);
            $table->string('commit')->nullable();
            $table->string('branch')->nullable();
            $table->string('deployer')->nullable();
            $table->string('url', 1024)->nullable();
            $table->timestamp('deployed_at');

            $table->index(['project_id', 'deployed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploys');
        Schema::dropIfExists('feedback');
    }
};
