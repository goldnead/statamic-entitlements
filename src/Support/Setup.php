<?php

namespace Goldnead\Entitlements\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * An addon can be installed long before its migrations run — composer put the
 * package there, the nav entry appeared, and nobody has touched artisan yet.
 * `/cp/entitlements` then answered HTTP 500, because the listing reaches for
 * `entitlements` while the page is being built. That is an operator's
 * unfinished setup, not a bug, and it owes the reader a sentence rather than a
 * stack trace.
 *
 * The reason does not disappear with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an empty
 * state and says nothing anywhere would be worse than the crash it replaced —
 * the site would look installed and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-entitlements: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('entitlements::SetupRequired', [
            'title' => $title,
            'heading' => __('entitlements::cp.setup_required_heading'),
            'description' => __('entitlements::cp.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
