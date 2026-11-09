<?php

namespace KraftInk\Plugins\Forum\Services\Imports;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Redirects as SiteRedirects;
use App\Models\Site\SiteRedirect;

final class Redirects
{
    public function preserve(array $paths, string $target, string $table, int|string $id, ImportContext $context): array
    {
        $redirects = app(SiteRedirects::class);
        $preserved = [];
        if ($paths === []) {
            $context->report('unsupported', [
                'plugin' => 'forum',
                'table' => $table,
                'id' => $id,
                'reason' => 'forum_url_ambiguous',
            ]);
        }
        foreach (array_unique($paths) as $path) {
            $source = $redirects->path($path);
            if ($source === null || in_array($source, ['/forum/search', '/forum/profile', '/forum/following'], true)) {
                $context->report('unsupported', [
                    'plugin' => 'forum',
                    'table' => $table,
                    'id' => $id,
                    'reason' => 'forum_url_ambiguous',
                ]);

                continue;
            }
            $existing = SiteRedirect::on($context->target->getName())->where('source', $source)->first();
            if ($existing !== null) {
                $preserved[$source] =
                    $existing->destination === $target &&
                    (bool) $existing->enabled &&
                    in_array((int) $existing->status, [301, 302], true);
            } elseif (
                SiteRedirect::on($context->target->getName())->where('source', $target)->exists() ||
                SiteRedirect::on($context->target->getName())
                    ->where('automatic', true)
                    ->where('destination', $source)
                    ->exists()
            ) {
                $preserved[$source] = false;
            } else {
                $preserved[$source] = $redirects->preserve($source, $target);
            }
            if (!$preserved[$source]) {
                $context->report('unsupported', [
                    'plugin' => 'forum',
                    'table' => $table,
                    'id' => $id,
                    'reason' => 'forum_url_conflict',
                ]);
            }
        }

        return $preserved;
    }
}
