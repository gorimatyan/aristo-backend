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
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('topic_id');
            $table->string('theme_name');
            $table->unsignedBigInteger('positive_user_id')->nullable();
            $table->unsignedBigInteger('negative_user_id')->nullable();
            $table->enum('status', ['waiting', 'matched', 'completed'])->default('waiting');
            $table->timestamps();

            $table->foreign('positive_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('negative_user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->index(['topic_id', 'theme_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
