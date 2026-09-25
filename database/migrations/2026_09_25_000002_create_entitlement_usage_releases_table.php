<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per receipt that has been given back.
 *
 * The unique index on `receipt_id` is what makes a release happen once: a
 * queue that retries the failed job's cleanup presents the same receipt again,
 * and the second INSERT is ignored instead of lowering the counter twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlement_usage_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->string('receipt_id', 64)->unique('ent_usage_releases_receipt_unique');
            $table->unsignedBigInteger('usage_id')->index();
            $table->unsignedBigInteger('amount');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usage_releases');
    }
};
