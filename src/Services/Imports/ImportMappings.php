<?php

namespace KraftInk\Plugins\Forum\Services\Imports;

use App\Kraft\Migration\MappingRegistry;
use KraftInk\Plugins\Forum\Services\Imports\Azuriom\ImportMappings as AzuriomImportMappings;
use KraftInk\Plugins\Forum\Services\Imports\KraftInk\ImportMappings as KraftInkImportMappings;
use KraftInk\Plugins\Forum\Services\Imports\MineWeb\ImportMappings as MineWebImportMappings;
use RuntimeException;

final class ImportMappings
{
    private const array TABLES = [
        'notification_deliveries' => ['owner' => ['forum']],
        'forum__announcements' => [],
        'forum__drafts' => [],
        'forum__attachments' => [],
        'forum__mentions' => [],
        'forum__poll_revisions' => [],
        'forum__poll_votes' => [],
        'forum__poll_ballots' => [],
        'forum__poll_options' => [],
        'forum__polls' => [],
        'forum__post_likes' => [],
        'forum__topic_tags' => [],
        'forum__reports' => [],
        'forum__post_revisions' => [],
        'forum__posts' => [],
        'forum__subscriptions' => [],
        'forum__topics' => [],
        'forum__category_tags' => [],
        'forum__category_roles' => [],
        'forum__categories' => [],
    ];

    public function register(MappingRegistry $registry): void
    {
        $writer = new Writer();
        new AzuriomImportMappings($writer)->register($registry, self::TABLES, self::guard(...));
        new MineWebImportMappings($writer)->register($registry, self::TABLES, self::guard(...));
        new KraftInkImportMappings()->register($registry, self::TABLES, self::guard(...));
    }

    private static function guard($target, array $options): void
    {
        if (
            ($options['mode'] ?? 'merge') === 'replace' &&
            $target->getSchemaBuilder()->hasTable('notification_deliveries') &&
            $target
                ->table('notification_deliveries')
                ->where('owner', 'forum')
                ->where('status', 'sending')
                ->where('started_at', '>=', now()->subMinutes(10))
                ->exists()
        ) {
            throw new RuntimeException(__('forum::admin.automation.import_busy'));
        }
        if (
            ($options['mode'] ?? 'merge') === 'replace' &&
            $target->getSchemaBuilder()->hasTable('forum__announcements') &&
            $target
                ->table('forum__announcements')
                ->where('status', 'sending')
                ->where('started_at', '>=', now()->subMinutes(10))
                ->exists()
        ) {
            throw new RuntimeException(__('forum::admin.automation.import_busy'));
        }
    }
}
