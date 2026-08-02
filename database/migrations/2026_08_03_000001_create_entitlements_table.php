<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One table, and the unique index is part of it from the first line.
 *
 * WHY THE CONSTRAINT IS NOT A LATER MIGRATION
 * -------------------------------------------
 * The codebase this was extracted from ran for five months without one. Two
 * concurrent webhook deliveries, or a queue retry, or a customer double-clicking
 * a checkout button, each produced a second row — `firstOrCreate()` in PHP is a
 * SELECT followed by an INSERT and the gap between them is exactly the race.
 * Closing it afterwards cost a de-duplication migration with an audit table, a
 * lossless merge rule, an abort path for groups that mixed revoked and
 * non-revoked rows, and an artisan command to inspect the damage first.
 *
 * WHAT COUNTS AS THE SAME GRANT
 * -----------------------------
 * (subject_type, subject_id, product_slug, source, source_ref, brand_id).
 *
 * Deliberately not narrower. Several rows per (subject, product) are legitimate
 * and must stay possible:
 *   - a repeat purchase arrives with a new provider event id -> new source_ref,
 *   - the same product won by opt-in and later bought -> different source.
 * Constraining (subject, product) alone would collapse those and destroy real
 * customer history — a refund on the second purchase would appear to revoke the
 * first.
 *
 * `brand_id` is in the key. The alternative was considered and rejected: leaving
 * it out means a grant issued in one brand blocks the identical grant in
 * another, which is a cross-tenant failure — one brand silently deciding what
 * another brand may write. The cost of including it is that the same purchase
 * booked against two brands produces two rows without anything complaining;
 * that is a reporting question, and a reporting question is cheaper than a
 * tenancy leak.
 *
 * `source_ref` IS NOT NULLABLE, AND THAT IS THE POINT
 * ---------------------------------------------------
 * NULLs do not collide in a unique index — on MySQL, SQLite and Postgres alike.
 * A nullable `source_ref` therefore leaves the constraint switched off for every
 * grant that has no external reference: manual grants from the Control Panel,
 * opt-ins, anything an admin creates. Those are precisely the rows a human
 * double-submits.
 *
 * So absence is stored as the empty string and the column is NOT NULL. The
 * manager normalises `null` on the way in. This is a deliberate departure from
 * the extraction spec, which listed the column as nullable: a constraint that
 * does not hold for the most hand-driven write path is not idempotency, it is
 * the appearance of it. See tests/Feature/IdempotencyTest.php, which races two
 * inserts with no source_ref at all.
 *
 * KEY LENGTH
 * ----------
 * InnoDB caps a key at 3072 bytes and utf8mb4 charges four per character, so the
 * six columns are prefixed on MySQL. tests/Unit/IndexKeyLengthTest.php does the
 * arithmetic without a server; phpunit.mysql.xml proves the real engine agrees.
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'ent_subject_product_source_ref_unique';

    /**
     * MySQL prefix lengths for the unique key, in characters.
     *
     * subject_type is the only one shortened below its column width: a morph
     * alias is a handful of characters and a fully-qualified class name that
     * needs more than 100 does not exist in practice.
     */
    public const KEY_PREFIXES = [
        'subject_type' => 100,
        'subject_id' => 64,
        'product_slug' => 191,
        'source' => 64,
        'source_ref' => 191,
    ];

    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();

            // Brand scoping from the first migration; this table is never
            // backfilled the way the older addons in the family had to be.
            $table->unsignedBigInteger('brand_id')->index();

            // Polymorphic on purpose. The source system had `foreignId('user_id')`
            // pointing at the host application's users table, which is exactly
            // the App\... binding an extracted addon may not carry — and it makes
            // a grant to a CRM contact, who has no user row, impossible to
            // express. subject_id is a string so a uuid-keyed subject fits.
            $table->string('subject_type', 160);
            $table->string('subject_id', 64);

            // A free string with no foreign key, exactly as in the source. The
            // addon does not need to know what a product is; products live in
            // Statamic entries and the coupling would be the one thing that made
            // this un-extractable.
            $table->string('product_slug', 191);

            // Free strings, not enums: sources differ per project (thrivecart,
            // mollie, newsletter_optin, manual) and an enum here would force
            // every consumer to extend it. config('entitlements.sources') is a
            // display registry, never a whitelist.
            $table->string('source', 64);

            // NOT NULL. See the class docblock — a nullable column here disables
            // the unique index for the write paths that need it most.
            $table->string('source_ref', 191)->default('');

            // Only the four storable statuses ever land here; Scheduled and
            // Expired are derived from the timestamps. See Enums\EntitlementState.
            $table->string('status', 16);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_until')->nullable();

            // Set by revoke() and read by the resolver. In the source system this
            // column existed, was displayed, and was written as NULL by every
            // single write path — there was no revocation at all.
            $table->timestamp('revoked_at')->nullable();

            // Without a reason a revocation is not auditable, and a revocation
            // that cannot be explained six months later gets undone by whoever
            // is on support that day.
            $table->string('revoked_reason', 255)->nullable();

            // The announcement marker, and the reason it exists at all: two of
            // the four events are not caused by a write. A grant becomes Active
            // when its `starts_at` arrives and Expired when its `expires_at`
            // passes — the clock moves, nothing else happens. Without somewhere
            // to record what has already been announced, the scheduled pass
            // would re-fire both events on every run, forever.
            //
            // It holds the last state announced for this row, so one column
            // serves both transitions. The rejected alternative was flipping
            // `status` to `expired`: no extra column, but the stored status then
            // becomes a second source of truth about state, and a row can be
            // "expired" by status while its own dates say Active. One nullable
            // column is cheaper than an ambiguous state machine.
            $table->string('announced_state', 16)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['brand_id', 'subject_type', 'subject_id'], 'ent_brand_subject_idx');
            $table->index(['brand_id', 'product_slug'], 'ent_brand_product_idx');
            $table->index(['status', 'expires_at'], 'ent_status_expires_idx');
            $table->index(['status', 'announced_state'], 'ent_status_announced_idx');
        });

        $this->createUniqueIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }

    private function createUniqueIndex(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('entitlements', function (Blueprint $table): void {
                $table->unique(
                    ['subject_type', 'subject_id', 'product_slug', 'source', 'source_ref', 'brand_id'],
                    self::UNIQUE_INDEX,
                );
            });

            return;
        }

        // Prefix lengths keep the key at 2448 of InnoDB's 3072 bytes. Without
        // them the declared widths alone come to 2688, which works today and
        // leaves no room for a single widened column later.
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON entitlements '
            .'(subject_type(%d), subject_id(%d), product_slug(%d), source(%d), source_ref(%d), brand_id)',
            self::UNIQUE_INDEX,
            self::KEY_PREFIXES['subject_type'],
            self::KEY_PREFIXES['subject_id'],
            self::KEY_PREFIXES['product_slug'],
            self::KEY_PREFIXES['source'],
            self::KEY_PREFIXES['source_ref'],
        ));
    }
};
