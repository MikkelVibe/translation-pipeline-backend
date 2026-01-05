<?php

use App\Enums\JobItemStatus;
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
        Schema::create('job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('translation_jobs')->cascadeOnDelete();
            $table->string('external_id')->nullable()->comment('e.g. product ID in Shopware');
            $table->string('status')->default(JobItemStatus::Queued->value);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_items');
    }
};
