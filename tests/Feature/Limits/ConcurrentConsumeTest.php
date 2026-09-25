<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\DB;

/**
 * Zwei Prozesse, ein letzter Platz.
 *
 * Kein nachgestelltes Rennen, sondern ein echtes: zwei Kindprozesse mit je
 * eigener Datenbankverbindung warten an einer Schranke und buchen dann
 * gleichzeitig. Nur gegen MySQL und Postgres — eine In-Memory-SQLite kann
 * zwischen Prozessen nicht geteilt werden, und dort gäbe es ohnehin nichts zu
 * sperren ([[feedback-lockforupdate-ist-auf-sqlite-nichts]]).
 *
 * Zwei Runden, weil zwei Stellen gewinnen müssen:
 *  1. der Zähler existiert schon: beide treffen das bedingte UPDATE,
 *  2. der Zähler existiert noch nicht: beide legen ihn zugleich an
 *     (`insertOrIgnore` gegen den Unique-Index) und buchen dann.
 */
beforeEach(function () {
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
        $this->markTestSkipped('Needs a server engine; run with phpunit.mysql.xml or phpunit.pgsql.xml.');
    }

    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs pcntl and posix.');
    }
});

/**
 * Run `$work` in `$count` child processes at the same moment and return what
 * each returned. The children never close the inherited connection (that
 * would send the parent's socket a QUIT); they open their own and end with
 * SIGKILL so no destructor runs.
 *
 * @return list<bool>
 */
function raceInChildren(int $count, Closure $work): array
{
    $dir = sys_get_temp_dir().'/ent-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $go = $dir.'/go';
    $pids = [];

    for ($i = 0; $i < $count; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }

        if ($pid === 0) {
            $result = 'error';

            try {
                config()->set('database.connections.race', config('database.connections.testing'));
                DB::setDefaultConnection('race');
                DB::connection('race')->select('select 1');

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(200);
                }

                $result = $work() ? '1' : '0';
            } catch (Throwable $e) {
                $result = 'error: '.$e->getMessage();
            }

            file_put_contents($dir.'/'.$i, $result);
            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    usleep(300_000);
    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];

    for ($i = 0; $i < $count; $i++) {
        $raw = (string) @file_get_contents($dir.'/'.$i);
        @unlink($dir.'/'.$i);

        if (! in_array($raw, ['0', '1'], true)) {
            throw new RuntimeException('child '.$i.' failed: '.$raw);
        }

        $results[] = $raw === '1';
    }

    @unlink($go);
    @rmdir($dir);

    return $results;
}

it('lets exactly one of two simultaneous bookings take the last slot', function () {
    // The children open their own connections and must see the setup, so it
    // is committed rather than left in RefreshDatabase's transaction, and
    // removed again at the end.
    while (DB::transactionLevel() > 0) {
        // On MySQL the bed's own `migrate` in setUp() already ended the
        // transaction implicitly (DDL commits), while Laravel still counts it
        // as open. Reopen it at the PDO so the commit has something to end.
        if (! DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->beginTransaction();
        }

        DB::commit();
    }

    $anna = new SubjectReference('user', 'race-1');

    try {
        Entitlements::setLimits('race-plan', [
            'analyses' => ['value' => 1, 'period' => 'year'],
            'exports' => ['value' => 3, 'period' => 'month'],
        ]);
        Entitlements::grant($anna, 'race-plan', 'manual');

        // Round 1: the counter exists already, both race the UPDATE.
        expect(Entitlements::consume($anna, 'exports', 2))->not->toBeNull();

        $results = raceInChildren(2, fn () => Entitlements::consume($anna, 'exports'));

        expect(array_sum(array_map('intval', $results)))->toBe(1)
            ->and(Entitlements::quota($anna, 'exports')->used)->toBe(3);

        // Round 2: no counter yet, both create it and race the booking.
        $results = raceInChildren(2, fn () => Entitlements::consume($anna, 'analyses'));

        expect(array_sum(array_map('intval', $results)))->toBe(1)
            ->and(Entitlements::quota($anna, 'analyses')->used)->toBe(1)
            ->and(DB::table('entitlement_usages')->where('limit_key', 'analyses')->count())->toBe(1);
    } finally {
        DB::table('entitlement_usages')->where('subject_id', 'race-1')->delete();
        DB::table('entitlement_limits')->where('product_slug', 'race-plan')->delete();
        DB::table('entitlements')->where('subject_id', 'race-1')->delete();
    }
});

it('survives a booking inside the caller\'s own transaction on Postgres', function () {
    // The reason the counter is created with insertOrIgnore and not with a
    // caught INSERT: on Postgres the caught violation would leave the caller's
    // transaction aborted, and its next statement would fail.
    $anna = new SubjectReference('user', 'tx-1');
    Entitlements::setLimits('tx-plan', ['analyses' => ['value' => 5, 'period' => 'year']]);
    Entitlements::grant($anna, 'tx-plan', 'manual');

    DB::transaction(function () use ($anna) {
        expect(Entitlements::consume($anna, 'analyses'))->not->toBeNull();
        // Second booking hits the existing counter; insertOrIgnore yields.
        expect(Entitlements::consume($anna, 'analyses'))->not->toBeNull();
        expect(DB::table('entitlements')->where('subject_id', 'tx-1')->count())->toBe(1);
    });

    expect(Entitlements::quota($anna, 'analyses')->used)->toBe(2);
});
