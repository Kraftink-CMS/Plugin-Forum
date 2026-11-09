<?php

namespace KraftInk\Plugins\Forum\Services\Imports\MineWeb;

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
    private array $threads = [];

    public function __construct(private readonly Writer $writer)
    {
    }

    public function register(MappingRegistry $registry, array $replace, Closure $guard): void
    {
        $registry->register('mineweb', 'forum__groups', $this->group(...), 45, 'forum');
        $registry->register(
            'mineweb',
            'forum__groups_users',
            $this->membership(...),
            46,
            'forum',
            dependencies: ['users', 'forum__groups'],
        );
        $registry->register('mineweb', 'forum__forums', $this->category(...), 52, 'forum', $replace, guard: $guard);
        $registry->register('mineweb', 'forum__tags', $this->tag(...), 50, 'forum');
        $registry->register(
            'mineweb',
            'forum__topics',
            $this->legacyPost(...),
            65,
            'forum',
            dependencies: ['users', 'forum__forums'],
        );
        $registry->register(
            'mineweb',
            'forum__notes',
            $this->legacyLike(...),
            70,
            'forum',
            dependencies: ['users', 'forum__topics'],
        );
        $registry->register(
            'mineweb',
            'forum__msg_reports',
            $this->legacyReport(...),
            70,
            'forum',
            dependencies: ['users', 'forum__topics'],
        );
        $registry->register('mineweb', 'forum__profiles', $this->profile(...), 70, 'forum', dependencies: ['users']);
        $registry->register(
            'mineweb',
            'forum__viewws',
            $this->legacyView(...),
            75,
            'forum',
            dependencies: ['forum__topics'],
        );
        $registry->register('mineweb', 'forum__internals', $this->legacySettings(...), 80, 'forum');
    }

    private function category(array $row, ImportContext $context, array $seen = []): array
    {
        $table = 'forum__forums';
        if ($mapped = $context->mapped($table, $row['id'])) {
            return ['target_table' => 'forum__categories', 'target_id' => $mapped];
        }
        $key = $table.':'.$row['id'];
        if (in_array($key, $seen, true) || count($seen) >= 20) {
            throw new RuntimeException('Invalid imported forum hierarchy.');
        }
        $parent = null;
        $parentId = $row['id_parent'] ?? null;
        $parentTable = $table;
        if ($parentId) {
            $source = $context->indexedSourceRows($parentTable)[(string) $parentId] ?? null;
            if (!$source) {
                throw new RuntimeException('An imported forum parent is missing.');
            }
            $result = $this->category($source, $context, [...$seen, $key]);
            $parent = $context->remember($parentTable, $source, $result)['target_id'];
        }
        $read = 'public';
        $write = 'members';
        if (!empty($row['visible']) || !empty($row['permission'])) {
            $read = $write = 'roles';
            $context->report('access_review', [
                'plugin' => 'forum',
                'table' => $table,
                'id' => $row['id'],
                'reason' => 'legacy_group_permissions',
            ]);
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
            'name' => Str::limit(strip_tags($row['forum_name']), 120, ''),
            'description' => Str::limit(strip_tags($row['forum_description'] ?? ''), 500, ''),
            'locale' => config('imports.locale'),
            'parent_id' => $parent,
            'read_permission' => 'imports.review',
            'write_permission' => 'imports.review',
            'read_access' => $read,
            'write_access' => $write,
            'locked' => (bool) ($row['lock'] ?? false),
            'private_topics' => (bool) ($row['is_private'] ?? false),
            'icon' => preg_match('/^[a-z0-9-]{1,60}$/D', $row['icon'] ?? '') ? $row['icon'] : 'chat-left-text',
            'position' => max(0, (int) ($row['position'] ?? 0)),
        ]);
        if (!empty($row['forum_image'])) {
            $context->report('media', [
                'plugin' => 'forum',
                'table' => $table,
                'id' => $row['id'],
                'field' => 'forum_image',
                'path' => $row['forum_image'],
            ]);
        }

        $redirects = new Redirects()->preserve(
            $this->legacyPaths('forum', $row['forum_name'], $row['id']),
            '/forum/categories/'.$id,
            $table,
            $row['id'],
            $context,
        );

        return [
            'target_table' => 'forum__categories',
            'target_id' => $id,
            'metadata' => ['legacy_redirects' => $redirects],
        ];
    }

    private function topic(array $row, ImportContext $context): array
    {
        $title = $row['name'];
        $id = $context->insert('forum__topics', [
            'category_id' => $context->required('forum__forums', $row['id_parent']),
            'user_id' => $context->mapped('users', $row['id_user']),
            'title' => Str::limit(strip_tags($title), 180, ''),
            'pinned' => !empty($row['stick']),
            'locked' => !empty($row['lock']),
            'hidden' => true,
            'views' => max(0, (int) ($row['views'] ?? 0)),
            'created_at' => $row['date'] ?? now(),
            'updated_at' => $row['last_edit'] ?? ($row['date'] ?? now()),
            'deleted_at' => $row['deleted_at'] ?? null,
        ]);
        $paths = $this->legacyPaths('topic', $title, $row['id_topic']);
        $target = '/forum/topics/'.$id.'/'.(Str::slug(strip_tags($title)) ?: 'discussion');

        return [
            'target_table' => 'forum__topics',
            'target_id' => $id,
            'metadata' => [
                'legacy_redirects' => new Redirects()->preserve($paths, $target, 'forum__topics', $row['id'], $context),
            ],
        ];
    }

    private function legacyPaths(string $type, string $name, int|string $id): array
    {
        $slug = str_replace(' ', '-', htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $path = '/'.$type.'/'.$slug.'.'.$id.'/';

        return str_contains($slug, '/') ? [] : [$path, rtrim($path, '/')];
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
                'created_at' => $row['date'] ?? now(),
                'updated_at' => $row['last_edit'] ?? ($row['date'] ?? now()),
                'deleted_at' => $row['deleted_at'] ?? null,
            ]),
        ];
    }

    private function legacyPost(array $row, ImportContext $context): array
    {
        $topic = $this->legacyTopic($row['id_topic'], $context);
        $first = $this->threads[$context->runId][(string) $row['id_topic']];
        if (empty($row['first']) && !$context->mapped('forum__topics', $first['id'])) {
            $result = $this->post($first, $context, $topic, $first['id_user'], $first['content']);
            $context->remember('forum__topics', $first, $result);
        }

        return $this->post($row, $context, $topic, $row['id_user'], $row['content']);
    }

    private function legacyTopic(int|string $id, ImportContext $context): int
    {
        if (!isset($this->threads[$context->runId])) {
            $this->threads[$context->runId] = [];
            foreach ($context->sourceRows('forum__topics') ?? [] as $row) {
                if (!empty($row['first'])) {
                    if (isset($this->threads[$context->runId][(string) $row['id_topic']])) {
                        throw new RuntimeException('An imported forum thread has multiple initial posts.');
                    }
                    $this->threads[$context->runId][(string) $row['id_topic']] = $row;
                }
            }
        }
        if ($mapped = $context->mapped('forum__threads', $id)) {
            return $mapped;
        }
        $first = $this->threads[$context->runId][(string) $id] ?? null;
        if ($first === null) {
            throw new RuntimeException('An imported forum thread has no initial post.');
        }
        $result = $this->topic($first, $context);
        $topic = $context->remember('forum__threads', ['id' => $id], $result)['target_id'];
        if (!empty($first['visible']) || !empty($first['permission'])) {
            $context->report('access_review', [
                'plugin' => 'forum',
                'table' => 'forum__topics',
                'id' => $first['id'],
                'reason' => 'topic_group_permissions',
            ]);
        }
        foreach ($this->ids($first['tags'] ?? null) as $sourceId) {
            if ($tag = $context->mapped('forum__tags', $sourceId)) {
                $context->target->table('forum__topic_tags')->insertOrIgnore(['topic_id' => $topic, 'tag_id' => $tag]);
            }
        }

        return $topic;
    }

    private function tag(array $row, ImportContext $context): array
    {
        $rawRoles = $row['used'] ?? null;
        $restricted = $rawRoles !== null && $rawRoles !== '';
        $roles = $restricted ? $this->roleIds($rawRoles, $context) : [];
        if ($restricted && $roles === []) {
            $context->report('access_review', [
                'plugin' => 'forum',
                'table' => 'forum__tags',
                'id' => $row['id'],
                'reason' => 'restricted_tag',
            ]);

            return ['metadata' => ['archive_only' => true]];
        }

        return $this->writer->tag($context, $row['id'], $row['name'], $this->color($row['color'] ?? null), $roles);
    }

    private function legacyLike(array $row, ImportContext $context): array
    {
        if ((int) $row['type'] !== 1) {
            $context->report('unsupported', [
                'plugin' => 'forum',
                'table' => 'forum__notes',
                'id' => $row['id'],
                'reason' => 'negative_reaction',
            ]);

            return ['metadata' => ['archive_only' => true]];
        }

        return $this->writer->postLike(
            $context,
            $context->required('forum__topics', $row['id_message']),
            $context->required('users', $row['id_user']),
            $row['created_at'] ?? null,
        );
    }

    private function legacyReport(array $row, ImportContext $context): array
    {
        $data = [
            'post_id' => $context->required('forum__topics', $row['id_msg']),
            'user_id' => $context->required('users', $row['id_user']),
        ];
        $context->target->table('forum__reports')->insertOrIgnore(
            $data + [
                'reason' => strip_tags($row['reason']),
                'resolved' => false,
                'created_at' => $row['date'] ?? now(),
            ],
        );

        return [
            'target_table' => 'forum__reports',
            'target_id' => $context->target->table('forum__reports')->where($data)->value('id'),
        ];
    }

    private function legacyView(array $row, ImportContext $context): array
    {
        $topic = $this->legacyTopic($row['id_topic'], $context);
        $context->target->table('forum__topics')->where('id', $topic)->increment('views');

        return ['target_table' => 'forum__topics', 'target_id' => $topic];
    }

    private function profile(array $row, ImportContext $context): array
    {
        $result = $this->writer->profile($context, $row['id_user'], [
            'signature' => $row['signature'] ?? null,
            'bio' => $row['description'] ?? null,
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
                'table' => 'forum__profiles',
                'id' => $row['id'],
                'reason' => 'profile_extra_fields',
            ]);
        }

        return $result;
    }

    private function group(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'roles',
            'target_id' => $context->insert('roles', [
                'name' => Str::limit(strip_tags($row['group_name']), 100, ''),
                'slug' => 'forum-import-'.substr(hash('sha256', $context->runId.':'.$row['id']), 0, 20),
                'permissions' => '[]',
                'permission_profile' => 'none',
                'position' => max(0, min(998, (int) ($row['position'] ?? 0))),
                'color' => $this->color($row['color'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        ];
    }

    private function membership(array $row, ImportContext $context): array
    {
        if ($context->metadata('users', $row['id_user'])['preserved_local'] ?? false) {
            return ['metadata' => ['preserved_local' => true]];
        }
        $data = [
            'user_id' => $context->required('users', $row['id_user']),
            'role_id' => $context->required('forum__groups', $row['id_group']),
        ];
        $context->target->table('role_user')->insertOrIgnore($data);

        return ['target_table' => 'role_user', 'metadata' => $data];
    }

    private function roleIds(mixed $raw, ImportContext $context): array
    {
        $values =
            is_string($raw) && str_starts_with($raw, 'a:')
                ? @unserialize($raw, ['allowed_classes' => false, 'max_depth' => 4])
                : null;
        $ids = is_array($values)
            ? array_keys(array_filter($values, fn ($value) => in_array($value, ['on', '1', 1, true], true)))
            : [];
        $roles = [];
        foreach ($ids as $id) {
            if ($mapped = $context->mapped('forum__groups', $id)) {
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
        $value = '#'.ltrim((string) $value, '#');

        return preg_match('/^#[a-f0-9]{6}$/iD', $value) ? $value : '#34594b';
    }

    private function legacySettings(array $row, ImportContext $context): array
    {
        if ($row['internal_name'] === 'description') {
            $context->setting('forum.welcome', [
                config('imports.locale') => ImportedHtml::clean((string) $row['internal_value']),
            ]);

            return ['metadata' => ['setting' => 'forum.welcome']];
        }

        return ['metadata' => ['archive_only' => true]];
    }
}
