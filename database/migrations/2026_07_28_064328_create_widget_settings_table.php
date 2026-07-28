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
        Schema::create('widget_settings', function (Blueprint $table) {
            $table->id();
            $table->string('property_id')->unique();
            $table->string('primary_color')->default('#2563eb');
            $table->string('position')->default('bottom-right');
            $table->string('brand_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('welcome_message')->nullable();
            $table->boolean('require_name')->default(false);
            $table->boolean('collect_email')->default(false);
            $table->boolean('require_email')->default(false);
            $table->boolean('collect_topic')->default(false);
            $table->string('timezone')->default('Asia/Jakarta');
            $table->boolean('business_hours_enabled')->default(false);
            $table->json('business_hours')->nullable();
            $table->text('offline_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_settings');
    }
};
