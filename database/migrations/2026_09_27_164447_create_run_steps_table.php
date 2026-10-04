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
        Schema::create('run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->string('phase');
            $table->unsignedInteger('attempt')->default(0);
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('session_id')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->string('output_path')->nullable();
            $table->string('fingerprint', 40)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'phase', 'attempt']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('run_steps');
    }
};
