<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every booking, stored where it happened, so a release can be checked
 * against it instead of against what the caller hands back.
 *
 * WHY THE SERVER KEEPS THE RECEIPT
 * --------------------------------
 * The first version (25.09.2026, never released) trusted the array a caller
 * presented: holder, period, amount and brand came from it. A forged holder
 * lowered somebody else's counter, a fresh id released the same booking again,
 * an inflated amount emptied a counter and a negative one raised it. Now the
 * receipt the caller keeps is only a handle (its `id`); everything that decides
 * is read from this row.
 *
 * `released` counts what has been given back; the claim is a conditional
 * UPDATE (`released + n <= amount`), so two retries of the same cleanup cannot
 * give back more than was booked.
 *
 * Replaces `entitlement_usage_releases` from the unreleased migration
 * 2026_09_25_000002, which is dropped here where an install ran it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('entitlement_usage_releases');

        Schema::create('entitlement_usage_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('usage_id');
            $table->string('subject_type', 160);
            $table->string('subject_id', 64);
            $table->string('limit_key', 64);
            $table->string('period_key', 32);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('released')->default(0);
            $table->dateTime('released_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['brand_id', 'usage_id'], 'ent_receipts_brand_usage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usage_receipts');
    }
};
