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
        Schema::create('widget_sites', function (Blueprint $table) {
            $table->id();
            $table->string('property_id')->index();
            $table->string('origin')->default('');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['property_id', 'origin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_sites');
    }
};
