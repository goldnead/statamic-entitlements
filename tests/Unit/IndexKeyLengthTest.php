<?php

use Illuminate\Support\Facades\DB;

/**
 * Measures every index this package's migration creates the way MySQL would.
 *
 * The suite runs on in-memory SQLite by default, and SQLite has no InnoDB
 * key-length limit, no per-character byte cost and no fixed column widths — it
 * accepts `varchar(191)` and ignores the 191. Every mechanism that rejects an
 * oversized index is a MySQL mechanism, so a migration MySQL refuses outright
 * passes an SQLite suite without a murmur. A sibling addon shipped exactly that
 * way: a 3212-byte unique that had run hundreds of times locally and died on the
 * production hub with *SQLSTATE 1071*.
 *
 * That matters more here than anywhere else in the family. This package's single
 * most important guarantee — that the same grant cannot be written twice — is
 * enforced by one unique index over six columns. An index MySQL refuses is a
 * migration that fails; an index MySQL silently truncates is worse, because the
 * package would appear to work while two distinguishable grants collided.
 *
 * So this test does not ask a database. It compiles the migration through
 * Laravel's MySQL grammar in pretend mode — no server, no connection, nothing to
 * install in CI — and measures the DDL MySQL would have received, prefix lengths
 * included. It reads the real migration file, so it cannot drift from it.
 */
const INNODB_MAX_KEY_BYTES = 3072;

/**
 * Composite indexes that deliberately do not lead with `brand_id`, with the
 * reason. Every *read* of this table runs under the brand scope, so an index
 * that does not lead with brand_id cannot serve one — which makes each exception
 * a decision rather than an oversight.
 */
const DELIBERATELY_CROSS_BRAND_INDEXES = [
    // The scheduled announcement pass runs per brand but selects candidates by
    // what the clock has done to them, and it runs for every brand in turn. An
    // index leading with brand_id would serve one brand's pass and scan for the
    // next.
    'ent_status_expires_idx' => 'the announcement pass selects by clock state across every brand in turn',
    'ent_status_announced_idx' => 'same pass, second half: what has already been announced',
];

it('keeps every index the migration creates inside the InnoDB key limit', function () {
    $schema = compileEntitlementsMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = entitlementsIndexWidth($index, $schema);

        expect($bytes)->toBeLessThanOrEqual(
            INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

it('proves the prefix lengths are what keeps the unique index inside the limit', function () {
    // Without them the six declared widths come to 2688 bytes — legal today and
    // with no room for a single widened column. The prefixes bring it to 2448.
    // If somebody removes them, this is the test that says so.
    $schema = compileEntitlementsMigrationsForMysql();

    $unique = collect($schema['indexes'])->firstWhere('unique', true);

    expect($unique)->not->toBeNull();

    $withPrefixes = entitlementsIndexWidth($unique, $schema);
    $withoutPrefixes = entitlementsIndexWidth(
        [...$unique, 'prefixes' => []],
        $schema
    );

    expect($withPrefixes)->toBeLessThan($withoutPrefixes)
        ->and($withPrefixes)->toBeLessThanOrEqual(2448)
        ->and($withoutPrefixes)->toBeGreaterThan(2448);
});

it('lets no unique index cover a nullable column', function () {
    // The reason source_ref is NOT NULL with an empty-string default. A SQL
    // unique constrains no NULL on any engine, so a nullable column inside one
    // switches the constraint off for exactly the rows that leave it empty —
    // which here would be every manual grant, the ones a human double-submits.
    $schema = compileEntitlementsMigrationsForMysql();

    $uniques = array_filter($schema['indexes'], fn ($index) => $index['unique']);

    expect($uniques)->not->toBeEmpty();

    foreach ($uniques as $index) {
        foreach ($index['columns'] as $column) {
            expect($schema['columns'][$index['table']][$column]['nullable'] ?? true)->toBeFalse(
                "Unique {$index['name']} covers the nullable column {$column}. Rows leaving it NULL are ".
                'not constrained at all, so the index does not enforce what its name claims.'
            );
        }
    }
});

it('keeps the table addressable per brand', function () {
    $schema = compileEntitlementsMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        if (count($index['columns']) < 2) {
            continue;
        }

        if (array_key_exists($index['name'], DELIBERATELY_CROSS_BRAND_INDEXES)) {
            continue;
        }

        // in_array rather than toContain: Pest reads every extra argument to
        // toContain as another needle, so a failure message passed there would
        // silently become part of what is asserted.
        expect(in_array('brand_id', $index['columns'], true))->toBeTrue(
            "Composite index {$index['name']} contains no brand_id, so the brand scope cannot use it. ".
            'If that is intended, say so in DELIBERATELY_CROSS_BRAND_INDEXES with the reason.'
        );
    }
});

it('keeps every index name inside MySQL\'s 64-character identifier limit', function () {
    // The generated names for these column combinations exceed it, which is why
    // every composite index here is named by hand.
    $schema = compileEntitlementsMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        expect(strlen($index['name']))->toBeLessThanOrEqual(64, "Index name {$index['name']} is too long for MySQL.");
    }
});

/** Worst-case bytes this index occupies under utf8mb4. */
function entitlementsIndexWidth(array $index, array $schema): int
{
    $bytes = 0;

    foreach ($index['columns'] as $column) {
        $definition = $schema['columns'][$index['table']][$column] ?? null;

        expect($definition)->not->toBeNull("Index {$index['name']} covers unknown column {$column}.");

        $prefix = $index['prefixes'][$column] ?? null;

        $bytes += $prefix === null
            ? $definition['bytes']
            : min($definition['bytes'], $prefix * 4);
    }

    return $bytes;
}

/**
 * Replays the migration against a MySQL connection that is never opened and
 * returns the column widths and index definitions MySQL would end up with.
 *
 * @return array{columns: array<string, array<string, array{bytes: int, nullable: bool}>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>, prefixes: array<string, int>}>}
 */
function compileEntitlementsMigrationsForMysql(): array
{
    config()->set('database.connections.key_length_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'key_length_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('key_length_probe');

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL without a server in sight.
        $queries = DB::connection('key_length_probe')->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('key_length_probe');
    }

    $columns = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*?)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (entitlementsSplitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = entitlementsDescribeColumn($column[2]);
                }
            }

            continue;
        }

        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[$match[3]] = entitlementsDescribeIndex(
                $match[1], $match[3], $match[2] === 'unique', $match[4]
            );

            continue;
        }

        // The unique index is issued as a raw statement on MySQL, because
        // Laravel's schema builder cannot express prefix lengths. The probe has
        // to read that form too or it would measure a schema the package never
        // creates.
        if (preg_match('/^create (unique )?index (\w+) on (\w+) \((.+)\)$/i', $sql, $match)) {
            $indexes[$match[2]] = entitlementsDescribeIndex(
                $match[3], $match[2], $match[1] !== '', $match[4]
            );

            continue;
        }
    }

    return ['columns' => $columns, 'indexes' => array_values($indexes)];
}

/**
 * @return array{table: string, name: string, unique: bool, columns: list<string>, prefixes: array<string, int>}
 */
function entitlementsDescribeIndex(string $table, string $name, bool $unique, string $columnList): array
{
    $columns = [];
    $prefixes = [];

    foreach (explode(',', $columnList) as $part) {
        $part = trim($part);

        if (preg_match('/^`?(\w+)`?\((\d+)\)$/', $part, $match)) {
            $columns[] = $match[1];
            $prefixes[$match[1]] = (int) $match[2];

            continue;
        }

        $columns[] = trim($part, ' `');
    }

    return compact('table', 'name', 'unique', 'columns', 'prefixes');
}

/** Splits a column list on commas that are not inside parentheses. */
function entitlementsSplitTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/** @return array{bytes: int, nullable: bool} */
function entitlementsDescribeColumn(string $type): array
{
    return [
        'bytes' => entitlementsIndexBytes($type),
        // The grammar always states one or the other explicitly, so the absence
        // of "not null" is a decision rather than a default.
        'nullable' => ! str_contains($type, ' not null'),
    ];
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function entitlementsIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs, text and json cannot be indexed whole at all. Reported as
        // oversized so an index that reaches for one fails here rather than on
        // MySQL.
        default => INNODB_MAX_KEY_BYTES + 1,
    };
}
