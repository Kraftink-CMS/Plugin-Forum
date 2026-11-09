<?php

namespace KraftInk\Plugins\Forum\Console\Commands;

use Illuminate\Console\Command;
use KraftInk\Plugins\Forum\Services\Notifications\ForumAnnouncements;
use KraftInk\Plugins\Forum\Services\Notifications\ForumMentions;
use KraftInk\Plugins\Forum\Services\Notifications\ForumNotifications;
use App\Kraft\System\OperationLock;

class ProcessNotifications extends Command
{
    protected $signature = 'forum:notifications {--limit=100}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('forum::admin.automation.process_description'));
    }

    public function handle(
        ForumNotifications $notifications,
        ForumMentions $mentions,
        ForumAnnouncements $announcements,
    ): int {
        return app(OperationLock::class)->shared(fn () => $this->process($notifications, $mentions, $announcements));
    }

    private function process(
        ForumNotifications $notifications,
        ForumMentions $mentions,
        ForumAnnouncements $announcements,
    ): int {
        $this->line(
            (string) ($mentions->process((int) $this->option('limit')) +
                $notifications->process((int) $this->option('limit'))),
        );
        $announcements->run((int) $this->option('limit'));

        return self::SUCCESS;
    }
}
