<?php

namespace KraftInk\Plugins\Forum\Providers;

use App\Events\CollectingSeoPages;
use App\Events\PruningData;
use App\Kraft\Features;
use App\Kraft\Settings;
use App\Kraft\Widgets\WidgetRegistry;
use App\Models\Accounts\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use KraftInk\Plugins\Forum\Console\Commands\ProcessNotifications;
use KraftInk\Plugins\Forum\Listeners\ForumDataRetention;
use KraftInk\Plugins\Forum\Listeners\SitemapEntries;
use KraftInk\Plugins\Forum\Services\ForumAccess;
use KraftInk\Plugins\Forum\Services\ForumTrash;
use KraftInk\Plugins\Forum\Services\Imports\ImportMappings;
use KraftInk\Plugins\Forum\Services\Notifications\ForumNotifications;
use App\Events\CollectingSitemapEntries;
use App\Kraft\Events\PluginEvents;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Notifications\NotificationRegistry;
use App\Kraft\Trash\TrashRegistry;

class PluginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('forum.publish', function (Request $request): array {
            $settings = app(Settings::class);
            $user = $request->user()?->getAuthIdentifier();
            $key = $user === null ? 'ip:'.$request->ip() : 'user:'.$user;
            $limit = $request->routeIs('plugins.forum.public.topics.store')
                ? Limit::perMinutes(10, (int) $settings->get('forum.topic_limit', 5))->by('topic:'.$key)
                : Limit::perMinute((int) $settings->get('forum.reply_limit', 10))->by('reply:'.$key);
            $delay = (int) $settings->get('forum.post_interval', 0);

            return $delay > 0 ? [$limit, Limit::perSecond(1, $delay)->by('interval:'.$key)] : [$limit];
        });
        $events = app(PluginEvents::class);
        $events->define('forum.topic.published');
        $events->define('forum.post.published');
        ForumNotifications::register(app(NotificationRegistry::class));
        $this->registerWidgets(app(WidgetRegistry::class));
        app(Features::class)->register(
            'forum',
            fn (?User $user) => app(ForumAccess::class)->readableIds($user, app()->getLocale()) !== [],
        );
        Event::listen(CollectingSitemapEntries::class, [SitemapEntries::class, 'collect']);
        Event::listen(PruningData::class, [ForumDataRetention::class, 'prune']);
        $this->commands([ProcessNotifications::class]);
        $this->app->afterResolving(
            Schedule::class,
            fn ($schedule) => $schedule->command('forum:notifications')->everyMinute()->withoutOverlapping(5),
        );
        app(ImportMappings::class)->register(app(MappingRegistry::class));
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'forum');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'forum');
        app(ForumTrash::class)->register(app(TrashRegistry::class));
        Event::listen(CollectingSeoPages::class, function (CollectingSeoPages $event): void {
            $event->add(
                'plugins.forum.public.home.index',
                __('forum::seo.pages.home'),
                [
                    'categories_count' => __('forum::seo.variables.categories_count'),
                ],
                defaults: ['title' => __('forum::messages.discussions.title'), 'description' => '{description}'],
            );
            $event->add(
                'plugins.forum.public.categories.show',
                __('forum::seo.pages.category'),
                [
                    'category' => __('forum::seo.variables.category'),
                    'category_description' => __('forum::seo.variables.category_description'),
                    'topics_count' => __('forum::seo.variables.topics_count'),
                ],
                defaults: ['title' => '{category}', 'description' => '{description}'],
                excerptVariables: ['category_description'],
            );
            $event->add(
                'plugins.forum.public.topics.show',
                __('forum::seo.pages.topic'),
                [
                    'topic' => __('forum::seo.variables.topic'),
                    'category' => __('forum::seo.variables.category'),
                    'posts_count' => __('forum::seo.variables.posts_count'),
                ],
                defaults: ['title' => '{topic}', 'description' => '{description}'],
            );
            $event->add(
                'plugins.forum.public.profiles.show',
                __('forum::seo.pages.profile'),
                [
                    'member' => __('forum::seo.variables.member'),
                    'biography' => __('forum::seo.variables.biography'),
                    'topics_count' => __('forum::seo.variables.topics_count'),
                    'posts_count' => __('forum::seo.variables.posts_count'),
                ],
                defaults: ['title' => '{member}', 'description' => '{description}'],
                excerptVariables: ['biography'],
            );
        });
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerWidgets(WidgetRegistry $widgets): void
    {
        $widgets->register(
            owner: 'forum',
            id: 'latest-topics',
            label: 'forum::widgets.latest_topics',
            render: fn (array $context, array $config) => view('forum::widgets.latest-topics', [
                'topics' => app(ForumAccess::class)
                    ->topics($context['user'] ?? null, app()->getLocale())
                    ->where('forum__topics.hidden', false)
                    ->orderByDesc('forum__topics.updated_at')
                    ->orderByDesc('forum__topics.id')
                    ->limit($config['limit'])
                    ->get(['forum__topics.id', 'forum__topics.title', 'forum__topics.updated_at']),
            ]),
            fields: ['limit' => ['type' => 'integer', 'label' => 'forum::widgets.count', 'min' => 1, 'max' => 12]],
            permission: 'forum.view',
            zones: ['home'],
            defaults: ['limit' => 5],
        );
    }
}
