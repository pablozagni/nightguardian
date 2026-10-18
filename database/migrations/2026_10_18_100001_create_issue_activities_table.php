<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('issue_id')->constrained()->cascadeOnDelete();
            // null = the system (e.g. an automatic regression).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_activities');
    }
};
