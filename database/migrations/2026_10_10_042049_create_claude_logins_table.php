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
        Schema::create('claude_logins', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('pid')->nullable();
            $table->string('fifo_path')->nullable();
            $table->string('log_path')->nullable();
            $table->text('authorize_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claude_logins');
    }
};
