<?php

namespace KraftInk\Plugins\Forum\Services\Imports\Azuriom;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use Closure;
use Illuminate\Support\Str;
use KraftInk\Plugins\Forum\Services\Imports\Redirects;
use KraftInk\Plugins\Forum\Services\Imports\Writer;
use RuntimeException;

final class ImportMappings
{
    public function __construct(private readonly Writer $writer)
    {
    }

    public function register(MappingRegistry $registry, array $replace, Closure $guard): void
    {
        $registry->register(
            'azuriom',
            'forum_categories',
            fn ($row, $context) => $this->foreignCategory('forum_categories', $row, $context),
            48,
            'forum',
            $replace,
            guard: $guard,
        );
        $registry->register(
            'azuriom',
            'forum_forums',
            fn ($row, $context) => $this->foreignCategory('forum_forums', $row, $context),
            52,
            'forum',
            $replace,
            guard: $guard,
        );
        $registry->register('azuriom', 'forum_tags', $this->tag(...), 50, 'forum');
        $registry->register(
            'azuriom',
            'forum_discussions',
            $this->topic(...),
            60,
            'forum',
            dependencies: ['users', 'forum_forums'],
        );
        $registry->register(
            'azuriom',
            'forum_posts',
            fn ($row, $context) => $this->post(
                $row,
                $context,
                $context->required('forum_discussions', $row['discussion_id']),
                $row['author_id'],
                $this->body($row['content'], $row['content_format'] ?? 'markdown'),
            ),
            65,
            'forum',
            dependencies: ['users', 'forum_discussions'],
        );
        $registry->register(
            'azuriom',
            'forum_likes',
            fn ($row, $context) => $this->writer->postLike(
                $context,
                $context->required('forum_posts', $row['post_id']),
                $context->required('users', $row['author_id']),
                $row['created_at'] ?? null,
            ),
            70,
            'forum',
            dependencies: ['users', 'forum_posts'],
        );
        $registry->register(
            'azuriom',
            'forum_discussion_tag',
            $this->topicTag(...),
            70,
            'forum',
            dependencies: ['forum_discussions', 'forum_tags'],
        );
        $registry->register('azuriom', 'forum_users', $this->profile(...), 70, 'forum', dependencies: ['users']);
        $registry->register(
            'azuriom',
            'forum_polls',
            $this->poll(...),
            70,
            'forum',
            dependencies: ['forum_discussions'],
        );
        $registry->register(
            'azuriom',
            'forum_poll_options',
            $this->pollOption(...),
            75,
            'forum',
            dependencies: ['forum_polls'],
        );
        $registry->register(
            'azuriom',
            'forum_poll_votes',
            $this->pollVote(...),
            80,
            'forum',
            dependencies: ['users', 'forum_poll_options'],
        );
        $registry->settings('azuriom', $this->settings(...), 'forum');
    }

    private function foreignCategory(string $table, array $row, ImportContext $context, array $seen = []): array
    {
        if ($mapped = $context->mapped($table, $row['id'])) {
            return ['target_table' => 'forum__categories', 'target_id' => $mapped];
        }
        $key = $table.':'.$row['id'];
        if (in_array($key, $seen, true) || count($seen) >= 20) {
            throw new RuntimeException('Invalid imported forum hierarchy.');
        }
        $parent = null;
        $parentId = $row['parent_id'] ?? null;
        $parentTable = $table;
        if (!$parentId && $table === 'forum_forums' && !empty($row['category_id'])) {
            $parentId = $row['category_id'];
            $parentTable = 'forum_categories';
        }
        if ($parentId) {
            $source = $context->indexedSourceRows($parentTable)[(string) $parentId] ?? null;
            if (!$source) {
                throw new RuntimeException('An imported forum parent is missing.');
            }
            $result = $this->foreignCategory($parentTable, $source, $context, [...$seen, $key]);
            $parent = $context->remember($parentTable, $source, $result)['target_id'];
        }
        $read = 'public';
        $write = 'members';
        $roles = [];
        if ($table === 'forum_forums' && ($row['roles'] ?? null) !== null) {
            $roles = $this->roleIds($row['roles'], $context);
            $read = $write = 'roles';
        }
        if (count($seen) >= 4) {
            $context->report('access_review', [
                'plugin' => 'forum',
                'table' => $table,
                'id' => $row['id'],
                'reason' => 'hierarchy_depth',
            ]);
        }
        $id = $context->insert('forum__categories', [
            'name' => Str::limit(strip_tags($row['name']), 120, ''),
            'description' => Str::limit(strip_tags($row['description'] ?? ''), 500, ''),
            'locale' => config('imports.locale'),
            'parent_id' => $parent,
            'read_permission' => 'imports.review',
            'write_permission' => 'imports.review',
            'read_access' => $read,
            'write_access' => $write,
            'locked' => (bool) ($row['is_locked'] ?? false),
            'private_topics' => (bool) ($row['is_private'] ?? false),
            'icon' => preg_match('/^[a-z0-9-]{1,60}$/D', $row['icon'] ?? '') ? $row['icon'] : 'chat-left-text',
            'position' => max(0, (int) ($row['position'] ?? 0)),
        ]);
        foreach (['read', 'write'] as $ability) {
            foreach ($roles as $role) {
                $context->target
                    ->table('forum__category_roles')
                    ->insertOrIgnore(['category_id' => $id, 'role_id' => $role, 'ability' => $ability]);
            }
        }
        if ($table === 'forum_forums') {
            foreach ($this->ids($row['default_tags'] ?? null) as $sourceId) {
                if ($tag = $context->mapped('forum_tags', $sourceId)) {
                    $context->target
                        ->table('forum__category_tags')
                        ->insertOrIgnore(['category_id' => $id, 'tag_id' => $tag]);
                }
            }
        }
        $redirects = [];
        if ($table === 'forum_forums') {
            $slug = $row['slug'] ?? '';
            $paths = is_string($slug) && preg_match('/^[a-z0-9_-]+$/iD', $slug) ? ['/forum/'.$slug] : [];
            $redirects = new Redirects()->preserve($paths, '/forum/categories/'.$id, $table, $row['id'], $context);
        }

        return [
            'target_table' => 'forum__categories',
            'target_id' => $id,
            'metadata' => ['legacy_redirects' => $redirects],
        ];
    }

    private function topic(array $row, ImportContext $context): array
    {
        $title = $row['title'];
        $id = $context->insert('forum__topics', [
            'category_id' => $context->required('forum_forums', $row['forum_id']),
            'user_id' => $context->mapped('users', $row['author_id']),
            'title' => Str::limit(strip_tags($title), 180, ''),
            'pinned' => !empty($row['is_pinned']),
            'locked' => !empty($row['is_locked']),
            'hidden' => true,
            'views' => max(0, (int) ($row['views'] ?? 0)),
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
            'deleted_at' => $row['deleted_at'] ?? null,
        ]);
        $paths = ['/forum/discussions/'.$row['id'], '/forum/discussions/'.$row['id'].'/'.Str::slug($title)];
        $target = '/forum/topics/'.$id.'/'.(Str::slug(strip_tags($title)) ?: 'discussion');

        return [
            'target_table' => 'forum__topics',
            'target_id' => $id,
            'metadata' => [
                'legacy_redirects' => new Redirects()->preserve(
                    $paths,
                    $target,
                    'forum_discussions',
                    $row['id'],
                    $context,
                ),
            ],
        ];
    }

    private function post(array $row, ImportContext $context, int $topic, ?int $author, string $body): array
    {
        return [
            'target_table' => 'forum__posts',
            'target_id' => $context->insert('forum__posts', [
                'topic_id' => $topic,
                'user_id' => $context->mapped('users', $author),
                'body' => ImportedHtml::clean($body),
                'hidden' => true,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
                'deleted_at' => $row['deleted_at'] ?? null,
            ]),
        ];
    }

    private function tag(array $row, ImportContext $context): array
    {
        $rawRoles = $row['roles'] ?? null;
        $restricted = $rawRoles !== null;
        $roles = $restricted ? $this->roleIds($rawRoles, $context) : [];
        if ($restricted && $roles === []) {
            $context->report('access_review', [
                'plugin' => 'forum',
                'table' => 'forum_tags',
                'id' => $row['id'],
                'reason' => 'restricted_tag',
            ]);

            return ['metadata' => ['archive_only' => true]];
        }

        return $this->writer->tag($context, $row['id'], $row['name'], $this->color($row['color'] ?? null), $roles);
    }

    private function topicTag(array $row, ImportContext $context): array
    {
        if (!($tag = $context->mapped('forum_tags', $row['tag_id']))) {
            return ['metadata' => ['archive_only' => true]];
        }
        $data = ['topic_id' => $context->required('forum_discussions', $row['discussion_id']), 'tag_id' => $tag];
        $context->target->table('forum__topic_tags')->insertOrIgnore($data);

        return [
            'target_table' => 'forum__topic_tags',
            'target_id' => $context->target->table('forum__topic_tags')->where($data)->value('id'),
        ];
    }

    private function profile(array $row, ImportContext $context): array
    {
        $result = $this->writer->profile($context, $row['user_id'], [
            'signature' => $row['signature'] ?? null,
            'bio' => $row['about'] ?? null,
            'website' => $row['website'] ?? null,
            'discord' => $row['discord'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
        if (
            !($result['metadata']['preserved_local'] ?? false) &&
            (!empty($row['social']) || !empty($row['location']) || !empty($row['twitter']))
        ) {
            $context->report('unsupported', [
                'plugin' => 'forum',
                'table' => 'forum_users',
                'id' => $row['id'],
                'reason' => 'profile_extra_fields',
            ]);
        }

        return $result;
    }

    private function poll(array $row, ImportContext $context): array
    {
        $options = array_filter(
            $context->indexedSourceRows('forum_poll_options'),
            fn ($option) => (string) $option['poll_id'] === (string) $row['id'],
        );

        return [
            'target_table' => 'forum__polls',
            'target_id' => $context->insert('forum__polls', [
                'topic_id' => $context->required('forum_discussions', $row['discussion_id']),
                'question' => Str::limit(strip_tags($row['question']), 200, ''),
                'max_choices' => empty($row['multiple_choice']) ? 1 : max(1, min(255, count($options))),
                'closes_at' => $row['closes_at'] ?? null,
                'closed' => true,
                'allow_change_votes' => false,
                'allow_withdraw_votes' => (bool) ($row['remove_vote'] ?? false),
                'results_visibility' => empty($row['results_before_vote']) ? 'after_vote' : 'always',
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    private function pollOption(array $row, ImportContext $context): array
    {
        $position = 0;
        foreach ($context->indexedSourceRows('forum_poll_options') as $candidate) {
            if (
                (string) $candidate['poll_id'] === (string) $row['poll_id'] &&
                (int) $candidate['id'] < (int) $row['id']
            ) {
                $position++;
            }
        }

        return [
            'target_table' => 'forum__poll_options',
            'target_id' => $context->insert('forum__poll_options', [
                'poll_id' => $context->required('forum_polls', $row['poll_id']),
                'label' => Str::limit(strip_tags($row['value']), 160, ''),
                'position' => min(255, $position),
            ]),
        ];
    }

    private function pollVote(array $row, ImportContext $context): array
    {
        $option = $context->target
            ->table('forum__poll_options')
            ->find($context->required('forum_poll_options', $row['option_id']));
        $data = ['poll_id' => $option->poll_id, 'user_id' => $context->required('users', $row['user_id'])];
        $context->target
            ->table('forum__poll_ballots')
            ->insertOrIgnore($data + ['created_at' => $row['created_at'] ?? now()]);
        $ballot = $context->target->table('forum__poll_ballots')->where($data)->value('id');
        $vote = ['ballot_id' => $ballot, 'option_id' => $option->id];
        $context->target->table('forum__poll_votes')->insertOrIgnore($vote);

        return [
            'target_table' => 'forum__poll_votes',
            'target_id' => $context->target->table('forum__poll_votes')->where($vote)->value('id'),
        ];
    }

    private function roleIds(mixed $raw, ImportContext $context): array
    {
        $values = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $ids = is_array($values) ? array_values(array_filter($values, fn ($id) => is_int($id) && $id > 0)) : [];
        $roles = [];
        foreach ($ids as $id) {
            if ($mapped = $context->mapped('roles', $id)) {
                $roles[] = $mapped;
            }
        }

        return array_values(array_unique($roles));
    }

    private function ids(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $values = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $values = is_array($values) ? $values : explode(',', (string) $raw);

        return array_values(
            array_unique(
                array_map(
                    'intval',
                    array_filter($values, fn ($id) => is_scalar($id) && preg_match('/^[0-9]+$/D', (string) $id)),
                ),
            ),
        );
    }

    private function color(mixed $value): string
    {
        if (is_string($value) && preg_match('/^#?[a-f0-9]{6}$/iD', $value)) {
            return '#'.ltrim($value, '#');
        }
        if (is_numeric($value) && (int) $value >= 0 && (int) $value <= 16777215) {
            return sprintf('#%06x', (int) $value);
        }
        $value = '#'.ltrim((string) $value, '#');

        return preg_match('/^#[a-f0-9]{6}$/iD', $value) ? $value : '#34594b';
    }

    private function body(string $content, string $format): string
    {
        if ($format === 'markdown') {
            return ImportedHtml::clean(
                Str::markdown($content, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            );
        }
        if ($format !== 'bbcode') {
            return ImportedHtml::clean($content);
        }
        $tokens = [];
        $content = preg_replace_callback(
            '/\[code(?:=[^\]]*)?\](.*?)\[\/code\]/is',
            function ($match) use (&$tokens) {
                $token = 'KRAFTCODE'.count($tokens).'TOKEN';
                $tokens[$token] =
                    '<pre><code>'.htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';

                return $token;
            },
            $content,
        );
        $html = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        for ($i = 0; $i < 10; $i++) {
            $html = preg_replace_callback(
                '/\[(b|i|u|s|quote)(?:=[^\]]*)?\](.*?)\[\/\1\]/is',
                function ($match) {
                    $tag = ['b' => 'strong', 'i' => 'em', 'u' => 'u', 's' => 's', 'quote' => 'blockquote'][
                        strtolower($match[1])
                    ];

                    return '<'.$tag.'>'.$match[2].'</'.$tag.'>';
                },
                $html,
            );
        }
        $html = preg_replace('/\[url\](.*?)\[\/url\]/is', '<a href="$1">$1</a>', $html);
        $html = preg_replace('/\[url=([^\]]+)\](.*?)\[\/url\]/is', '<a href="$1">$2</a>', $html);
        $html = preg_replace('/\[img\](.*?)\[\/img\]/is', '<img src="$1" alt="">', $html);
        $html = preg_replace('/\[\/?(?:color|size|font|center|left|right|justify)(?:=[^\]]*)?\]/i', '', $html);
        $html = preg_replace('/\[list(?:=[^\]]*)?\](.*?)\[\/list\]/is', '<ul>$1</ul>', $html);
        $html = preg_replace('/\[\*\](.*?)(?=\[\*\]|<\/ul>)/is', '<li>$1</li>', $html);

        return ImportedHtml::clean(strtr(nl2br($html, false), $tokens));
    }

    private function settings(array $row, ImportContext $context): void
    {
        if ($row['key'] === 'forum.recent_posts' && is_numeric($row['value'])) {
            $context->setting('forum.recent_count', max(0, min(50, (int) $row['value'])));
        }
        if ($row['key'] === 'forum.home_message') {
            $context->setting('forum.welcome', [
                config('imports.locale') => ImportedHtml::clean((string) $row['value']),
            ]);
        }
    }
}
