<?php

namespace KraftInk\Plugins\Forum\Services\Imports;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\NavigationTargets;
use Illuminate\Support\Str;

final class Writer
{
    public function tag(ImportContext $context, int|string $sourceId, string $name, string $color, array $roles): array
    {
        $id = $context->insert('forum__tags', [
            'name' => Str::limit(strip_tags($name), 60, ''),
            'slug' => substr(Str::slug($name) ?: 'tag', 0, 45).'-'.
                substr(hash('sha256', $context->runId.':'.$sourceId), 0, 16),
            'color' => $color,
        ]);
        foreach ($roles as $role) {
            $context->target->table('forum__tag_roles')->insertOrIgnore(['tag_id' => $id, 'role_id' => $role]);
        }

        return ['target_table' => 'forum__tags', 'target_id' => $id];
    }

    public function postLike(ImportContext $context, int $post, int $user, mixed $createdAt): array
    {
        $likes = $context->target->table('forum__post_likes');
        $data = ['post_id' => $post, 'user_id' => $user];
        $likes->insertOrIgnore($data + ['created_at' => $createdAt ?? now()]);

        return ['target_table' => 'forum__post_likes', 'target_id' => $likes->where($data)->value('id')];
    }

    public function profile(ImportContext $context, int|string|null $sourceUser, array $profile): array
    {
        $user = $context->required('users', $sourceUser);
        $result = ['target_table' => 'forum__profiles', 'target_id' => $user];
        if (
            ($context->metadata('users', $sourceUser)['preserved_local'] ?? false) ||
            $context->target->table('forum__profiles')->where('user_id', $user)->exists()
        ) {
            return $result + ['metadata' => ['preserved_local' => true]];
        }

        $website = $profile['website'] ?? null;
        $context->target->table('forum__profiles')->insert([
            'user_id' => $user,
            'signature' => ImportedHtml::clean(
                Str::markdown($profile['signature'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            ),
            'bio' => Str::limit(strip_tags($profile['bio'] ?? ''), 1000, ''),
            'website' => is_string($website) && preg_match('~^https?://~i', $website) && NavigationTargets::validUrl($website)
                ? $website
                : null,
            'discord' => Str::limit(strip_tags($profile['discord'] ?? ''), 80, ''),
            'created_at' => $profile['created_at'] ?? now(),
            'updated_at' => $profile['updated_at'] ?? now(),
        ]);

        return $result;
    }
}
