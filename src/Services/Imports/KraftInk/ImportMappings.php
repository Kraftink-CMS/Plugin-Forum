<?php

namespace KraftInk\Plugins\Forum\Services\Imports\KraftInk;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\NavigationTargets;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

final class ImportMappings
{
    private array $categories = [];

    private const array FIELDS = [
        'forum__tags' => ['name', 'color'],
        'forum__topics' => ['title', 'pinned', 'locked', 'views', 'deleted_at', 'created_at', 'updated_at'],
        'forum__posts' => ['body', 'deleted_at', 'created_at', 'updated_at'],
        'forum__post_revisions' => ['body', 'anonymized', 'created_at'],
        'forum__reports' => ['reason', 'resolved', 'created_at'],
        'forum__topic_tags' => [],
        'forum__post_likes' => ['created_at'],
        'forum__polls' => [
            'question',
            'max_choices',
            'closes_at',
            'allow_change_votes',
            'allow_withdraw_votes',
            'results_visibility',
            'created_at',
            'updated_at',
        ],
        'forum__poll_options' => ['label', 'position'],
        'forum__poll_ballots' => ['created_at'],
        'forum__poll_votes' => [],
        'forum__subscriptions' => ['created_at', 'updated_at'],
    ];

    public function register(MappingRegistry $registry, array $replacements, Closure $guard): void
    {
        $registry->register(
            'kraftink',
            'forum__categories',
            $this->category(...),
            50,
            'forum',
            $replacements,
            guard: $guard,
        );
        $definitions = [
            'forum__tags' => [50, []],
            'forum__topics' => [60, ['category_id' => 'forum__categories', 'user_id' => 'users']],
            'forum__posts' => [65, ['topic_id' => 'forum__topics', 'user_id' => 'users']],
            'forum__post_revisions' => [70, ['post_id' => 'forum__posts', 'user_id' => 'users']],
            'forum__reports' => [70, ['post_id' => 'forum__posts', 'user_id' => 'users']],
            'forum__topic_tags' => [65, ['topic_id' => 'forum__topics', 'tag_id' => 'forum__tags']],
            'forum__post_likes' => [70, ['post_id' => 'forum__posts', 'user_id' => 'users']],
            'forum__polls' => [65, ['topic_id' => 'forum__topics']],
            'forum__poll_options' => [70, ['poll_id' => 'forum__polls']],
            'forum__poll_ballots' => [70, ['poll_id' => 'forum__polls', 'user_id' => 'users']],
            'forum__poll_votes' => [75, ['ballot_id' => 'forum__poll_ballots', 'option_id' => 'forum__poll_options']],
            'forum__subscriptions' => [75, ['topic_id' => 'forum__topics', 'user_id' => 'users']],
        ];
        foreach ($definitions as $table => [$priority, $relations]) {
            $replace =
                $table === 'forum__tags'
                    ? [
                        'forum__topic_tags' => [],
                        'forum__category_tags' => [],
                        'forum__tag_roles' => [],
                        'forum__tags' => [],
                    ]
                    : [];
            $dependencies = array_values(array_unique($relations));
            if ($table === 'forum__subscriptions') {
                $dependencies[] = 'forum__posts';
            }
            $registry->register(
                'kraftink',
                $table,
                fn ($row, $context) => $this->row($table, $row, $context, $relations),
                $priority,
                'forum',
                $replace,
                dependencies: $dependencies,
            );
        }
        foreach (
            [
                'forum__category_roles' => ['category_id' => 'forum__categories', 'role_id' => 'roles'],
                'forum__tag_roles' => ['tag_id' => 'forum__tags', 'role_id' => 'roles'],
                'forum__category_tags' => ['category_id' => 'forum__categories', 'tag_id' => 'forum__tags'],
            ] as $table => $relations
        ) {
            $registry->register(
                'kraftink',
                $table,
                fn ($row, $context) => $this->pivot($table, $row, $context, $relations),
                55,
                'forum',
                dependencies: array_values($relations),
            );
        }
        $registry->register(
            'kraftink',
            'forum__profiles',
            $this->profile(...),
            55,
            'forum',
            identity: 'user_id',
            dependencies: ['users'],
        );
    }

    private function locale(array $row): string
    {
        return array_key_exists($row['locale'] ?? '', config('kraft.locales'))
            ? $row['locale']
            : config('imports.locale');
    }

    private function category(array $row, ImportContext $context, array $seen = []): array
    {
        if ($mapped = $context->mapped('forum__categories', $row['id'])) {
            return ['target_table' => 'forum__categories', 'target_id' => $mapped];
        }
        if (count($seen) >= 4 || in_array((string) $row['id'], $seen, true)) {
            throw new RuntimeException('Invalid imported forum hierarchy.');
        }
        if (!isset($this->categories[$context->runId])) {
            $this->categories[$context->runId] = [];
            foreach ($context->sourceRows('forum__categories') ?? [] as $item) {
                $this->categories[$context->runId][(string) $item['id']] = $item;
            }
        }
        $parent = null;
        if (!empty($row['parent_id'])) {
            $source = $this->categories[$context->runId][(string) $row['parent_id']] ?? null;
            if (!$source || $this->locale($source) !== $this->locale($row)) {
                throw new RuntimeException('Invalid imported forum parent.');
            }
            $result = $this->category($source, $context, [...$seen, (string) $row['id']]);
            $parent = $context->remember('forum__categories', $source, $result)['target_id'];
        }
        $data = [
            'name' => strip_tags($row['name']),
            'description' => strip_tags($row['description'] ?? ''),
            'locale' => $this->locale($row),
            'parent_id' => $parent,
            'position' => max(0, (int) ($row['position'] ?? 0)),
            'read_permission' => 'imports.review',
            'write_permission' => 'imports.review',
            'read_access' => in_array($row['read_access'] ?? '', ['public', 'members', 'roles'], true)
                ? $row['read_access']
                : 'roles',
            'write_access' => in_array($row['write_access'] ?? '', ['members', 'roles'], true)
                ? $row['write_access']
                : 'roles',
            'locked' => (bool) ($row['locked'] ?? false),
            'private_topics' => (bool) ($row['private_topics'] ?? false),
            'icon' => preg_match('/^[a-z0-9-]{1,60}$/D', $row['icon'] ?? '') ? $row['icon'] : 'chat-left-text',
        ];

        return ['target_table' => 'forum__categories', 'target_id' => $context->insert('forum__categories', $data)];
    }

    private function pivot(string $table, array $row, ImportContext $context, array $relations): array
    {
        $data = [];
        foreach ($relations as $column => $source) {
            $data[$column] = $context->required($source, $row[$column]);
        }
        if ($table === 'forum__category_roles') {
            if (!in_array($row['ability'] ?? '', ['read', 'write'], true)) {
                throw new RuntimeException('Invalid imported forum permission.');
            }
            $data['ability'] = $row['ability'];
        }
        $context->target->table($table)->insert($data);

        return ['target_table' => $table, 'metadata' => $data];
    }

    private function row(string $table, array $row, ImportContext $context, array $relations): array
    {
        if (
            $table === 'forum__subscriptions' &&
            ($context->metadata('users', $row['user_id'])['preserved_local'] ?? false)
        ) {
            return ['metadata' => ['preserved_local' => true, 'subscription' => 'archive_only']];
        }
        $data = array_intersect_key($row, array_flip(self::FIELDS[$table]));
        foreach ($relations as $column => $source) {
            $data[$column] = isset($row[$column]) ? $context->required($source, $row[$column]) : null;
        }
        foreach (['created_at', 'updated_at'] as $column) {
            if (in_array($column, self::FIELDS[$table], true)) {
                $data[$column] ??= now();
            }
        }
        foreach (['title', 'question', 'label', 'name', 'reason'] as $column) {
            if (isset($data[$column])) {
                $data[$column] = strip_tags($data[$column]);
            }
        }
        if (isset($data['body'])) {
            $data['body'] = ImportedHtml::clean($data['body']);
        }
        if (in_array($table, ['forum__topics', 'forum__posts'], true)) {
            $data['hidden'] = true;
        }
        if ($table === 'forum__tags') {
            $data['slug'] =
                substr(Str::slug($row['slug'] ?? $row['name']) ?: 'tag', 0, 35).
                '-import-'.
                $row['id'].
                '-'.
                substr($context->runId, 0, 8);
            $data['color'] = preg_match('/^#[a-f0-9]{6}$/iD', $row['color'] ?? '') ? $row['color'] : '#34594b';
        }
        if ($table === 'forum__polls') {
            $data['closed'] = true;
            $data['max_choices'] = max(1, min(10, (int) ($row['max_choices'] ?? 1)));
            $data['results_visibility'] = in_array(
                $row['results_visibility'] ?? '',
                ['always', 'after_vote', 'closed'],
                true,
            )
                ? $row['results_visibility']
                : 'closed';
        }
        if ($table === 'forum__poll_votes') {
            $ballot = $context->target->table('forum__poll_ballots')->find($data['ballot_id']);
            $option = $context->target->table('forum__poll_options')->find($data['option_id']);
            if (!$ballot || !$option || $ballot->poll_id !== $option->poll_id) {
                throw new RuntimeException('An imported ballot references another poll.');
            }
        }
        if ($table === 'forum__subscriptions') {
            foreach (['last_read_post_id', 'last_notified_post_id'] as $column) {
                $post = empty($row[$column]) ? null : $context->mapped('forum__posts', $row[$column]);
                $data[$column] =
                    $post &&
                    $context->target
                        ->table('forum__posts')
                        ->where('id', $post)
                        ->where('topic_id', $data['topic_id'])
                        ->exists()
                        ? $post
                        : 0;
            }
            $data['pending_post_id'] = null;
        }

        return ['target_table' => $table, 'target_id' => $context->insert($table, $data)];
    }

    private function profile(array $row, ImportContext $context): array
    {
        $user = $context->required('users', $row['user_id']);
        if (
            ($context->metadata('users', $row['user_id'])['preserved_local'] ?? false) ||
            $context->target->table('forum__profiles')->where('user_id', $user)->exists()
        ) {
            return [
                'target_table' => 'forum__profiles',
                'target_id' => $user,
                'metadata' => ['preserved_local' => true],
            ];
        }
        $website = $row['website'] ?? null;
        $data = [
            'user_id' => $user,
            'signature' => ImportedHtml::clean($row['signature'] ?? ''),
            'bio' => strip_tags($row['bio'] ?? ''),
            'website' => is_string($website) &&
                in_array(strtolower(parse_url($website, PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) &&
                NavigationTargets::validUrl($website)
                    ? $website
                    : null,
            'discord' => strip_tags($row['discord'] ?? ''),
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ];
        $context->target->table('forum__profiles')->insert($data);

        return ['target_table' => 'forum__profiles', 'target_id' => $user];
    }
}
