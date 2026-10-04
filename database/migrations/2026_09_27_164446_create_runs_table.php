<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('branch')->nullable();
            $table->string('status')->index();
            $table->string('failed_check')->nullable();
            $table->unsignedInteger('fix_attempts')->default(0);
            $table->string('stop_reason')->nullable();
            $table->text('error')->nullable();
            $table->string('model')->nullable();
            $table->string('worktree_path')->nullable();
            $table->string('base_commit')->nullable();
            $table->string('result_commit')->nullable();
            $table->boolean('pushed')->default(false);
            $table->string('plan_session_id')->nullable();
            $table->string('build_session_id')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
