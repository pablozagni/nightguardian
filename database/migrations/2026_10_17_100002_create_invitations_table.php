<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            // null project_id = instance-level invitation created by a super admin.
            $table->foreignUlid('project_id')->nullable()->constrained()->cascadeOnDelete();
            // Project role; null for instance-level invitations.
            $table->enum('role', ['admin', 'member', 'viewer'])->nullable();
            // Instance-level invitations only: whether the invited user becomes a super admin.
            $table->boolean('is_super_admin')->default(false);
            $table->string('email')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
