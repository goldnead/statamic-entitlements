<?php

namespace Goldnead\Entitlements\Tests\Feature;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Integrations\Insights\Active;
use Goldnead\Entitlements\Integrations\Insights\EntitlementMetric;
use Goldnead\Entitlements\Integrations\Insights\Expired;
use Goldnead\Entitlements\Integrations\Insights\Granted;
use Goldnead\Entitlements\Integrations\Insights\Revoked;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Tests\TestCase;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four numbers this addon offers the analytics addon.
 *
 * **Every expectation below is worked out by hand from one fixture.** Ten
 * grants, small enough to add up on paper, with every awkward case in it: a
 * pending grant that never began, a grant issued before the window, one that
 * expired inside it, one revoked inside it, one revoked *after* it, one whose
 * revocation beat its own expiry, a grant with no source, a revocation with no
 * reason, and a grant belonging to a second brand. The point of the file is
 * that a query which drifts shows up as an arithmetic disagreement rather than
 * as a green suite over a different report.
 *
 * Tested against a stand-in for the contract rather than the real package: the
 * sibling is a `suggest`, and a test that needed it installed would be proving
 * the opposite of what this addon claims. `tests/Fakes/insights-contracts.php`
 * explains why that is a required file and not an autoload entry, and
 * `InsightsContractsMatchTest` is what holds the copy to account.
 *
 * A PHPUnit class rather than a Pest file, for one mechanical reason: the
 * stand-ins have to be loaded **before the application boots** — the contracts
 * before a metric class is touched, the facade before the provider's `booted()`
 * callback asks whether it exists. `beforeEach()` runs after the bed is up, and
 * a callback that has already run cannot be given a second chance.
 *
 * Time is frozen and every fixture date is stated in UTC, which is what this
 * package stores. The application timezone in the bed is America/Chicago on
 * purpose, so a metric that forgot to convert its window would be five hours
 * wrong here instead of right by accident.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected function setUp(): void
    {
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        // The base class lies beside them as a file of its own and carries no
        // guard in its head: it is a byte-for-byte copy of the original, so the
        // guard sits here instead. See InsightsContractsMatchTest.
        if (! class_exists(TableMetric::class, false)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and in URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Ten grants across two brands, all dates UTC.
     *
     * | # | product | source     | began   | ended                       |
     * |---|---------|------------|---------|-----------------------------|
     * | A | kurs-a  | thrivecart | 08-12   | never                       |
     * | B | kurs-a  | manual     | 08-14   | revoked 08-18               |
     * | C | kurs-b  | thrivecart | 08-14   | expires 08-17               |
     * | D | kurs-b  | (none)     | 08-15   | never                       |
     * | E | kurs-a  | lead_magnet| never   | pending, no start at all    |
     * | F | kurs-c  | manual     | 07-01   | never — and brand 2         |
     * | G | kurs-c  | manual     | 07-05   | expires 08-13               |
     * | H | kurs-a  | manual     | 08-12   | revoked 08-19, no reason    |
     * | I | kurs-a  | thrivecart | 08-11   | revoked 08-13, expires 08-16|
     * | J | kurs-c  | thrivecart | 08-12   | revoked 09-01, after the end|
     */
    protected function fixture(): void
    {
        $this->grant('A', product: 'kurs-a', source: 'thrivecart', startsAt: '2026-08-12 09:00:00');
        $this->grant('B', product: 'kurs-a', source: 'manual', startsAt: '2026-08-14 10:00:00', revokedAt: '2026-08-18 10:00:00', reason: 'Rückbuchung');
        $this->grant('C', product: 'kurs-b', source: 'thrivecart', startsAt: '2026-08-14 11:00:00', expiresAt: '2026-08-17 11:00:00');

        // No source at all. The column is NOT NULL, so absence is the empty
        // string — which is exactly the value a split must group rather than
        // drop.
        $this->grant('D', product: 'kurs-b', source: '', startsAt: '2026-08-15 08:00:00');

        // Awaiting a confirmation that may never come: no `starts_at` at all.
        // It must appear in no figure until it is claimed.
        $this->grant('E', product: 'kurs-a', source: 'lead_magnet', startsAt: null, status: EntitlementState::Pending);

        // A second brand. Every figure here is across all of them, deliberately.
        $this->grant('F', product: 'kurs-c', source: 'manual', startsAt: '2026-07-01 09:00:00', brandId: 2);

        $this->grant('G', product: 'kurs-c', source: 'manual', startsAt: '2026-07-05 09:00:00', expiresAt: '2026-08-13 12:00:00');
        $this->grant('H', product: 'kurs-a', source: 'manual', startsAt: '2026-08-12 06:00:00', revokedAt: '2026-08-19 09:00:00', reason: null);

        // Revoked before it would have expired. It counts as a revocation and
        // must not also count as an expiry — one lost access, one figure.
        $this->grant('I', product: 'kurs-a', source: 'thrivecart', startsAt: '2026-08-11 06:00:00', expiresAt: '2026-08-16 06:00:00', revokedAt: '2026-08-13 06:00:00', reason: 'Rückbuchung');

        // Revoked after the window closes: granted inside it, still live at the
        // end of it, and not a revocation of this period.
        $this->grant('J', product: 'kurs-c', source: 'thrivecart', startsAt: '2026-08-12 07:00:00', revokedAt: '2026-09-01 09:00:00', reason: 'Rückbuchung');
    }

    /**
     * One row, written through the model so the UTC cast is the one under test.
     *
     * `forceFill` rather than `create`: `status`, `revoked_at` and `brand_id`
     * are guarded on the model precisely because they decide access, and a
     * fixture that needs to place a revocation in the past has to reach past
     * that guard. Everything else — the timezone conversion, the column names —
     * is the package's own code.
     */
    protected function grant(
        string $ref,
        string $product,
        string $source,
        ?string $startsAt,
        ?string $expiresAt = null,
        ?string $revokedAt = null,
        ?string $reason = null,
        ?EntitlementState $status = null,
        int $brandId = 1,
    ): Entitlement {
        $status ??= $revokedAt === null ? EntitlementState::Active : EntitlementState::Revoked;

        $grant = new Entitlement;

        $grant->forceFill([
            'brand_id' => $brandId,
            'subject_type' => 'user',
            'subject_id' => $ref,
            'product_slug' => $product,
            'source' => $source,
            'source_ref' => $ref,
            'status' => $status->value,
            'starts_at' => $startsAt === null ? null : Carbon::parse($startsAt, 'UTC'),
            'expires_at' => $expiresAt === null ? null : Carbon::parse($expiresAt, 'UTC'),
            'revoked_at' => $revokedAt === null ? null : Carbon::parse($revokedAt, 'UTC'),
            'revoked_reason' => $reason,
        ])->save();

        return $grant;
    }

    /**
     * The ten days the fixture lives in, stated in UTC.
     *
     * The bounds are UTC because the columns are; the timezone conversion has a
     * test of its own below rather than being smuggled into every expectation
     * here.
     */
    protected function frage(string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(
                Carbon::parse('2026-08-11 00:00:00', 'UTC'),
                Carbon::parse('2026-08-20 23:59:59', 'UTC'),
            ),
            $bucket,
        );
    }

    /** @return array<string, int|float> */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- The four numbers ---------------------------------------------------

    /**
     * Every figure at once, against hand-worked totals.
     *
     * One test rather than four, deliberately: they are read side by side on a
     * screen and have to agree with each other. Four separate tests are four
     * chances to fix one of them and leave the rest disagreeing.
     */
    #[Test]
    public function the_four_figures_match_what_the_fixture_says(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(7, (new Granted)->value($frage), 'A B C D H I J began inside the window; E never began, F and G began before it');
        $this->assertSame(3, (new Revoked)->value($frage), 'B H I — J was revoked after the window closed');
        $this->assertSame(2, (new Expired)->value($frage), 'C and G — I was revoked before its own expiry');
        $this->assertSame(4, (new Active)->value($frage), 'A D F J are live at the end; everything else ended or never began');
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Granted::class, 'entitlements.granted'],
            [Revoked::class, 'entitlements.revoked'],
            [Expired::class, 'entitlements.expired'],
            [Active::class, 'entitlements.active'],
        ];

        foreach ($erwartet as [$klasse, $handle]) {
            /** @var EntitlementMetric $metrik */
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame(Unit::COUNT, $metrik->unit());
            $this->assertSame(__('entitlements::insights.metric_group'), $metrik->group());

            // Translated, not hard-coded: a missing key comes back as the key
            // itself, which is what these two assertions catch.
            $this->assertNotSame('', $metrik->label());
            $this->assertStringNotContainsString('insights.metric_', (string) $metrik->label());
            $this->assertNotEmpty($metrik->description());
            $this->assertStringNotContainsString('insights.metric_', (string) $metrik->description());

            $this->assertSame([], $metrik->meta($this->frage()));
        }
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No table, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident 0 on
     * a dashboard for a site that has not installed this addon at all.
     */
    #[Test]
    public function a_metric_cannot_answer_without_the_table(): void
    {
        $this->assertTrue((new Granted)->available());

        // A second, empty database rather than dropping the table in this one.
        // Dropping it would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_zugaenge', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_zugaenge');
        DB::setDefaultConnection('ohne_zugaenge');

        try {
            foreach ([Granted::class, Revoked::class, Expired::class, Active::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' answered without an entitlements table.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' produced a figure without a table.');
                $this->assertSame([], $metrik->series($this->frage()));
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    /** And the same when the bridge is switched off, table or no table. */
    #[Test]
    public function the_bridge_can_be_switched_off_without_uninstalling_anything(): void
    {
        $this->fixture();

        config()->set('entitlements.bridges.insights', false);

        foreach ([Granted::class, Revoked::class, Expired::class, Active::class] as $klasse) {
            $metrik = new $klasse;

            $this->assertFalse($metrik->available(), $klasse.' ignored the switch.');
            $this->assertNull($metrik->value($this->frage()));
            $this->assertSame([], $metrik->series($this->frage()));
        }

        $this->assertSame([], (new Granted)->breakdown($this->frage(), 'product'));
    }

    // -- The splits ---------------------------------------------------------

    /** What was granted, split by what it was a grant to. */
    #[Test]
    public function grants_split_by_product(): void
    {
        $this->fixture();

        $zeilen = (new Granted)->breakdown($this->frage(), 'product');

        $this->assertSame(['kurs-a' => 4, 'kurs-b' => 2, 'kurs-c' => 1], $this->keyed($zeilen));
        $this->assertSame(7, array_sum(array_column($zeilen, 'value')), 'the split must add up to the figure it splits');
    }

    /**
     * A grant with no source is a row keyed null, not a missing row.
     *
     * A report that quietly excludes rows is the hardest kind of wrong to
     * notice: the columns still add up among themselves, and only the total
     * disagrees — which is the number nobody re-adds.
     */
    #[Test]
    public function a_grant_without_a_source_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new Granted)->breakdown($this->frage(), 'source');

        $this->assertSame(['thrivecart' => 4, 'manual' => 2, '' => 1], $this->keyed($zeilen));
        $this->assertSame(7, array_sum(array_column($zeilen, 'value')));

        $ohne = $zeilen[2];

        $this->assertNull($ohne['key'], 'the empty source must arrive as a null key, not as an empty string');
        $this->assertSame(__('entitlements::insights.metric_no_source'), $ohne['label']);
    }

    /** And a revocation nobody explained is a row too, under a name of its own. */
    #[Test]
    public function a_revocation_without_a_reason_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new Revoked)->breakdown($this->frage(), 'reason');

        $this->assertSame(['Rückbuchung' => 2, '' => 1], $this->keyed($zeilen));

        $this->assertNull($zeilen[1]['key']);
        $this->assertSame(__('entitlements::insights.metric_no_reason'), $zeilen[1]['label']);
        $this->assertSame(3, array_sum(array_column($zeilen, 'value')));
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new Granted)->breakdown($this->frage(), 'weather'));
        $this->assertSame([], (new Revoked)->breakdown($this->frage(), 'product'));

        $this->assertSame(['product', 'source'], array_keys((new Granted)->breakdowns()));
        $this->assertSame(['reason'], array_keys((new Revoked)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Granted)->breakdown($this->frage(), 'source', 2);

        $this->assertCount(2, $zeilen);
        $this->assertSame(['thrivecart', 'manual'], array_column($zeilen, 'key'));
    }

    // -- Over time ----------------------------------------------------------

    /**
     * An event series holds only the buckets something happened in.
     *
     * The empty days are Insights' job — it fills the range for every metric at
     * once. A metric that filled its own would be filling them twice, and one
     * that invented a bucket outside the range would draw a column the axis has
     * no place for.
     */
    #[Test]
    public function an_event_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            ['2026-08-11' => 1, '2026-08-12' => 3, '2026-08-14' => 2, '2026-08-15' => 1],
            (new Granted)->series($frage),
        );

        $this->assertSame(
            ['2026-08-13' => 1, '2026-08-18' => 1, '2026-08-19' => 1],
            (new Revoked)->series($frage),
        );

        // The 16th is missing on purpose: I's expiry falls there and is not an
        // expiry, because I was revoked three days earlier.
        $this->assertSame(
            ['2026-08-13' => 1, '2026-08-17' => 1],
            (new Expired)->series($frage),
        );
    }

    /**
     * The stock series is a level and carries across buckets nothing happened in.
     *
     * The running balance below is the fixture read day by day: two grants were
     * already live when the window opened, seven arrived, five left.
     */
    #[Test]
    public function the_stock_series_is_a_running_balance_not_a_count_of_events(): void
    {
        $this->fixture();

        $reihe = (new Active)->series($this->frage());

        $this->assertSame([
            '2026-08-11' => 3,
            '2026-08-12' => 6,
            '2026-08-13' => 4,
            '2026-08-14' => 6,
            '2026-08-15' => 7,
            // Nothing happened on the 16th, and the level is unchanged rather
            // than absent. That is the difference between a stock and an event.
            '2026-08-16' => 7,
            '2026-08-17' => 6,
            '2026-08-18' => 5,
            '2026-08-19' => 4,
            '2026-08-20' => 4,
        ], $reihe);

        // The last bucket and the headline are the same question asked at the
        // same instant, and they have to agree.
        $this->assertSame(4, (new Active)->value($this->frage()));
        $this->assertSame(4, end($reihe));
    }

    /**
     * Somebody who began inside the period and was withdrawn before it ended is
     * not in the closing figure.
     *
     * B is exactly that grant: it appears under "granted" and under "revoked",
     * and nowhere in the stock. A stock metric that counted arrivals would
     * report five instead of four, and the difference is a customer who no
     * longer has access.
     */
    #[Test]
    public function a_grant_revoked_inside_the_period_is_gone_from_the_closing_stock(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(7, (new Granted)->value($frage));
        $this->assertSame(4, (new Active)->value($frage));

        // And the same grant, revoked one day after the window instead of two
        // days before its end, is counted — because at the moment we ask, it is
        // still live.
        Entitlement::query()
            ->where('source_ref', 'B')
            ->update(['revoked_at' => '2026-08-25 10:00:00']);

        $this->assertSame(5, (new Active)->value($frage));
        $this->assertSame(2, (new Revoked)->value($frage), 'and it is no longer a revocation of this period');
    }

    /** A stock of nothing is no series at all, not a row of zeroes. */
    #[Test]
    public function a_period_before_anything_existed_has_no_stock_series(): void
    {
        $this->fixture();

        $leer = new MetricQuery(Period::between(
            Carbon::parse('2025-01-01 00:00:00', 'UTC'),
            Carbon::parse('2025-01-31 23:59:59', 'UTC'),
        ));

        $this->assertSame(0, (new Active)->value($leer));
        $this->assertSame([], (new Active)->series($leer), 'an omitted bucket is filled with zero by Insights, which is the right level here');
        $this->assertSame(0, (new Granted)->value($leer));
        $this->assertSame([], (new Granted)->series($leer));
    }

    /** The grain comes from the question, not from the period. */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $this->assertSame(['2026-08' => 7], (new Granted)->series($this->frage(MetricQuery::BUCKET_MONTH)));
        $this->assertSame(['2026-08' => 4], (new Active)->series($this->frage(MetricQuery::BUCKET_MONTH)));
    }

    /**
     * An open-ended period still has an end, because a stock needs one.
     *
     * "All time" passes no bounds at all, which is the one case where a missing
     * `whereNotNull` stops being invisible: without it the pending grant E — no
     * `starts_at` at all — would be counted as a grant that began, and every
     * grant with no expiry would be counted as one that ran out.
     */
    #[Test]
    public function the_open_ended_period_counts_neither_a_pending_grant_nor_a_lifetime_one(): void
    {
        $this->fixture();

        $alles = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(9, (new Granted)->value($alles), 'nine of the ten have begun; E has not');
        $this->assertSame(3, (new Revoked)->value($alles), 'J is revoked in the future and is not yet a revocation');
        $this->assertSame(2, (new Expired)->value($alles), 'only C and G ran out; the ones with no expiry did not');
        $this->assertSame(4, (new Active)->value($alles), 'asked as of now, which is inside the fixture');
    }

    /**
     * A grant that has not started yet has not been granted yet.
     *
     * "All time" has no end of its own, and this table is full of dates in the
     * future: a pre-sale with a start date, a licence with an expiry, a
     * withdrawal an editor scheduled. Left unbounded, the widest period on the
     * screen would report every one of them as already having happened — which
     * is the one period where nobody would think to check.
     */
    #[Test]
    public function a_period_with_no_end_still_stops_at_today(): void
    {
        $this->fixture();

        // A pre-sale: settled, starting next month.
        $this->grant('vorverkauf', product: 'kurs-d', source: 'thrivecart', startsAt: '2026-09-15 09:00:00');

        // And a licence that runs until next year.
        $this->grant('laufzeit', product: 'kurs-d', source: 'thrivecart', startsAt: '2026-08-13 09:00:00', expiresAt: '2027-08-13 09:00:00');

        $alles = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

        $this->assertSame(10, (new Granted)->value($alles), 'the pre-sale has not begun and is not among them');
        $this->assertSame(2, (new Expired)->value($alles), 'and next year\'s expiry has not happened');
        $this->assertSame(3, (new Revoked)->value($alles), "nor has J's withdrawal, which is dated in September");
    }

    // -- The window is in UTC ------------------------------------------------

    /**
     * Insights asks in the application timezone; this package stores UTC.
     *
     * The bed runs on America/Chicago, five hours behind. A period stated as
     * "the 12th, locally" therefore begins at 05:00 UTC — and grant H, written
     * at 06:00 UTC, is inside it while a naive comparison against the raw local
     * string would have put the boundary at 00:00 and let in the previous
     * evening as well.
     *
     * The failure this catches is not exotic. It is every figure on the screen
     * shifted by the site's offset, which looks entirely plausible until
     * somebody adds up two months and finds a grant in both.
     */
    #[Test]
    public function the_window_is_converted_before_it_touches_the_columns(): void
    {
        $this->assertSame('America/Chicago', config('app.timezone'));

        $this->grant('spaet', product: 'kurs-a', source: 'manual', startsAt: '2026-08-12 02:00:00');
        $this->grant('frueh', product: 'kurs-a', source: 'manual', startsAt: '2026-08-12 07:00:00');

        // Local midnight on the 12th is 05:00 UTC, so only the second one is in.
        $lokal = new MetricQuery(Period::between(
            Carbon::parse('2026-08-12 00:00:00', 'America/Chicago'),
            Carbon::parse('2026-08-12 23:59:59', 'America/Chicago'),
        ));

        $this->assertSame(1, (new Granted)->value($lokal), 'the 02:00 UTC grant belongs to the previous local day');
    }

    // -- A half-written revocation -------------------------------------------

    /**
     * A row that says `revoked` but carries no date is not a live grant.
     *
     * The state resolver answers on either signal and fails closed, on the
     * grounds that a revocation interrupted halfway is still a revocation. The
     * stock figure has to agree with it: a dashboard that counts a grant the
     * access check refuses is a dashboard contradicting the product.
     */
    #[Test]
    public function a_revocation_with_no_date_is_not_counted_as_live(): void
    {
        $this->fixture();

        $this->assertSame(4, (new Active)->value($this->frage()));

        Entitlement::query()
            ->where('source_ref', 'A')
            ->update(['status' => EntitlementState::Revoked->value]);

        $this->assertSame(3, (new Active)->value($this->frage()));

        // And it is not a revocation of the period either — there is no date to
        // file it under, so inventing one would be the second guess.
        $this->assertSame(3, (new Revoked)->value($this->frage()));
    }

    // -- The last fraction of the last second --------------------------------

    /**
     * A grant made at 23:59:59.500 on the closing day is inside the period.
     *
     * The bug this pins is the reason these metrics stopped writing their own
     * window. `to` is 23:59:59.999999 and a binding formats a date as
     * `Y-m-d H:i:s`, so the fraction is cut off: compared with `<=` against a
     * column that keeps milliseconds, every row in the final second of the
     * period fell out. Silently, and only on the engines that keep the
     * fraction — a suite on second-precision data never sees it.
     *
     * {@see TableMetric::inPeriod()} compares
     * `< midnight` instead, and midnight is the same instant at every precision.
     * The row is written past the model's cast on purpose: the cast formats to
     * whole seconds, so a fixture built through it could not state the case at
     * all.
     *
     * The window closes on the 19th rather than on the 20th because the figure
     * is clamped to "now" as well, and now is midday on the 20th.
     */
    #[Test]
    public function a_grant_in_the_last_fraction_of_the_final_second_is_counted(): void
    {
        $this->grant('mittag', product: 'kurs-a', source: 'manual', startsAt: '2026-08-19 12:00:00');
        $this->rawGrant('kurz-vor-zwoelf', startsAt: '2026-08-19 23:59:59.500');

        $tag = new MetricQuery(Period::between(
            Carbon::parse('2026-08-19 00:00:00', 'UTC'),
            Carbon::parse('2026-08-19 23:59:59', 'UTC'),
        ));

        $this->assertSame(2, (new Granted)->value($tag), 'the grant half a second before midnight is inside the day');
        $this->assertSame(['2026-08-19' => 2], (new Granted)->series($tag), 'and it is in the day\'s column, not in the next one');
    }

    /**
     * The stock had the same defect, and it did not arrive by inheritance.
     *
     * The three event figures beside this one go through
     * {@see TableMetric::inPeriod()} and were repaired there. This one cannot:
     * a stock is asked *of an instant*, so it writes its own comparisons — and
     * they were the inclusive kind. A grant that began at 23:59:59.500 on the
     * closing day was therefore counted by "granted" and missing from
     * "active", on the same screen, for the same period.
     *
     * The row is written past the model's cast on purpose: the cast formats to
     * whole seconds, so through it the case cannot be stated at all.
     */
    #[Test]
    public function a_grant_beginning_in_the_last_fraction_of_the_final_second_is_live_at_the_close(): void
    {
        $this->rawGrant('kurz-vor-zwoelf', startsAt: '2026-08-19 23:59:59.500');

        $tag = new MetricQuery(Period::between(
            Carbon::parse('2026-08-19 00:00:00', 'UTC'),
            Carbon::parse('2026-08-19 23:59:59', 'UTC'),
        ));

        $this->assertSame(1, (new Granted)->value($tag), 'the fixture is meant to be inside the day at all');
        $this->assertSame(1, (new Active)->value($tag), 'and a grant that began inside the day is live at its close');
        $this->assertSame(['2026-08-19' => 1], (new Active)->series($tag), 'the running balance has to agree with the headline');
    }

    /**
     * And the same second at the other end: a grant that leaves in it has left.
     *
     * The mirror of the case above, and the one that would have read as an
     * over-count rather than an under-count: compared inclusively, a grant
     * whose `expires_at` or `revoked_at` fell in the final fraction was still
     * live at the close — while the "expired" and "revoked" figures beside it,
     * which inherit their window, already counted it as gone.
     *
     * Two rows rather than one, because the departures are two disjoint
     * queries: `LEAST()` is spelled differently in every dialect, so a row
     * leaving by its own clock and a row withdrawn by hand are asked for
     * separately, and a repair to one is not a repair to the other.
     */
    #[Test]
    public function a_grant_ending_in_the_last_fraction_of_the_final_second_has_left_the_close(): void
    {
        $this->rawGrant('laeuft-ab', startsAt: '2026-08-18 09:00:00', expiresAt: '2026-08-19 23:59:59.500');
        $this->rawGrant('zurueckgezogen', startsAt: '2026-08-18 09:00:00', revokedAt: '2026-08-19 23:59:59.500');

        $fenster = new MetricQuery(Period::between(
            Carbon::parse('2026-08-18 00:00:00', 'UTC'),
            Carbon::parse('2026-08-19 23:59:59', 'UTC'),
        ));

        $this->assertSame(1, (new Expired)->value($fenster), 'the expiry is inside the window for the figure that inherits its window');
        $this->assertSame(1, (new Revoked)->value($fenster), 'and so is the withdrawal');

        $this->assertSame(0, (new Active)->value($fenster), 'so neither grant may still be live at the close');
        $this->assertSame(
            ['2026-08-18' => 2],
            (new Active)->series($fenster),
            'both arrive on the 18th and both leave on the 19th, which is a level of zero and therefore no column',
        );
    }

    /**
     * One row written straight to the table, so a sub-second timestamp survives.
     *
     * {@see grant()} goes through the model, which is the right way to build a
     * fixture and the wrong way to state this case: the UTC cast formats to
     * whole seconds and the fraction would be gone before it reached the
     * database.
     */
    protected function rawGrant(
        string $ref,
        string $startsAt,
        ?string $expiresAt = null,
        ?string $revokedAt = null,
        int $brandId = 1,
    ): void {
        DB::table('entitlements')->insert([
            'brand_id' => $brandId,
            'subject_type' => 'user',
            'subject_id' => $ref,
            'product_slug' => 'kurs-a',
            'source' => 'manual',
            'source_ref' => $ref,
            'status' => ($revokedAt === null ? EntitlementState::Active : EntitlementState::Revoked)->value,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
            'created_at' => $startsAt,
            'updated_at' => $startsAt,
        ]);
    }

    // -- Across brands --------------------------------------------------------

    /**
     * The figures stop at the brand boundary.
     *
     * They did not, once, and the reason given was that Insights asks its
     * question per installation. That was the wrong answer: these tiles sit on
     * a screen beside tiles that do narrow, and a figure that quietly counts
     * another customer's grants is a leak rather than a wider view.
     *
     * F is the second brand's single grant and is live at the end of the window.
     * Asked as brand one, the closing stock is three rather than four; asked as
     * brand two it is that one grant and nothing else.
     */
    #[Test]
    public function the_figures_stop_at_a_brand_boundary(): void
    {
        $this->fixture();

        $this->assertSame(
            1,
            (int) DB::table('entitlements')->where('brand_id', 2)->count(),
            'the fixture is meant to hold exactly one grant in the second brand',
        );

        // Single-brand mode first: nothing is narrowed at all, which is what
        // every ordinary install runs and what the rest of this file measures.
        $this->assertSame(4, (new Active)->value($this->frage()));

        // The default brand the sibling's migration writes is id 1, which is
        // the id the fixture stamps on nine of its ten rows; the second one
        // created here is id 2, which is F's.
        $eins = app('brand-context')->default();
        $zwei = $this->makeBrand('zwei');
        $this->enableMultiBrand();

        $this->assertSame(1, $eins->id);
        $this->assertSame(2, $zwei->id, 'the fixture writes brand ids 1 and 2 by hand');

        app('brand-context')->runFor($eins, function () {
            $this->assertSame(3, (new Active)->value($this->frage()), "F belongs to the other brand and is not in this brand's stock");
            $this->assertSame(7, (new Granted)->value($this->frage()), 'F began before the window in any case');
        });

        app('brand-context')->runFor($zwei, function () {
            $this->assertSame(1, (new Active)->value($this->frage()), 'and the second brand sees its own grant and nothing else');
            $this->assertSame(0, (new Granted)->value($this->frage()));
            $this->assertSame(0, (new Revoked)->value($this->frage()));
        });
    }

    /**
     * With multi-brand on and no brand resolved, the figures are empty rather
     * than everything.
     *
     * The same choice `BrandScope` makes, for the same reason: a report that
     * falls back to "all brands" when it cannot tell which one it is looking at
     * is the failure mode that leaks. A tile reading zero can be understood; a
     * tile quietly showing four customers cannot.
     */
    #[Test]
    public function an_unresolved_brand_counts_nothing(): void
    {
        $this->fixture();

        $this->makeBrand('eins');
        $this->enableMultiBrand();
        app('brand-context')->forget();

        $this->assertSame(0, (new Granted)->value($this->frage()));
        $this->assertSame(0, (new Active)->value($this->frage()));
        $this->assertSame([], (new Granted)->series($this->frage()));
    }

    // -- The wiring ----------------------------------------------------------

    /**
     * The provider hands all four to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * four metric objects on a request that renders none of them.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'entitlements.granted' => Granted::class,
            'entitlements.revoked' => Revoked::class,
            'entitlements.expired' => Expired::class,
            'entitlements.active' => Active::class,
        ], $this->insights->registered);
    }
}
