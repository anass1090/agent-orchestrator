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
        Schema::create('run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_step_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phase');
            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->string('tool')->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('payload_path')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->unique(['run_id', 'sequence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('run_events');
    }
};
