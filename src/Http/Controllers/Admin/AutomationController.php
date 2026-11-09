<?php

namespace KraftInk\Plugins\Forum\Http\Controllers\Admin;

use App\Kraft\System\TableQuery;
use App\Kraft\Audit;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Kraft\System\WebhookClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Forum\Models\Posts\Announcement;
use KraftInk\Plugins\Forum\Services\Notifications\ForumAnnouncements;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AutomationController
{
    public function index(Request $request): View
    {
        if (!$request->user()->hasPermission('forum.manage')) {
            throw new HttpException(403);
        }

        return view('forum::admin.automation.index', [
            'deliveries' => TableQuery::paginate(
                Announcement::query()->latest('created_at'),
                ['post_id' => 'post_id', 'event' => 'event', 'status' => 'status', 'created_at' => 'created_at'],
                pageName: 'page',
                search: [],
            ),
            'configured' => app(Secrets::class)->get('forum.discord.webhook') !== '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!$request->user()->hasPermission('forum.manage')) {
            throw new HttpException(403);
        }
        $data = $request->validate([
            'enabled' => 'boolean',
            'events' => 'nullable|array|max:2',
            'events.*' => ['required', 'distinct', Rule::in(['topic', 'reply'])],
            'webhook_url' => [
                'nullable',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if ($value && !WebhookClient::validDiscordUrl($value)) {
                        $fail(__('forum::admin.announcements.invalid_url'));
                    }
                },
            ],
            'remove_webhook' => 'boolean',
        ]);
        $settings = app(Settings::class);
        $previousEnabled = (bool) $settings->get('forum.discord.enabled', false);
        $previousEvents = $settings->get('forum.discord.events', ['topic']);
        $events = $data['events'] ?? [];
        if ($request->boolean('enabled') && !$events) {
            throw ValidationException::withMessages([
                'events' => __('forum::admin.announcements.choose_events'),
            ]);
        }
        $target = $request->boolean('remove_webhook')
            ? ''
            : $data['webhook_url'] ?? app(Secrets::class)->get('forum.discord.webhook');
        if ($request->boolean('enabled') && !WebhookClient::validDiscordUrl($target)) {
            throw ValidationException::withMessages([
                'webhook_url' => __('forum::admin.announcements.invalid_url'),
            ]);
        }
        if ($target !== '' && $target !== app(Secrets::class)->get('forum.discord.webhook')) {
            app(SensitiveAction::class)->confirm($request);
        }
        DB::transaction(function () use ($request, $data, $settings, $previousEnabled, $previousEvents, $events) {
            if ($request->filled('webhook_url') || $request->boolean('remove_webhook')) {
                app(Secrets::class)->set(
                    'forum.discord.webhook',
                    $request->boolean('remove_webhook') ? '' : $data['webhook_url'],
                );
            }
            $settings->set('forum.discord.enabled', $request->boolean('enabled'));
            $settings->set('forum.discord.events', $events);
            if (
                $request->filled('webhook_url') ||
                $request->boolean('remove_webhook') ||
                $previousEnabled !== $request->boolean('enabled') ||
                $previousEvents !== $events
            ) {
                $settings->set('forum.discord.configuration_id', (string) Str::uuid());
            }
            Audit::record('forum.announcements_configured');
        });

        return back()->with('status', __('forum::messages.common.saved'));
    }

    public function retry(Request $request, string $id): RedirectResponse
    {
        if (!$request->user()->hasPermission('forum.manage')) {
            throw new HttpException(403);
        }
        $request->validate(['confirmed' => 'accepted']);
        $settings = app(Settings::class);
        if (
            !(
                $settings->get('forum.discord.enabled', false) &&
                WebhookClient::validDiscordUrl(app(Secrets::class)->get('forum.discord.webhook'))
            )
        ) {
            throw new HttpException(422, __('forum::admin.announcements.not_configured'));
        }
        DB::transaction(function () use ($id, $settings) {
            $row = Announcement::query()->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                abort(404);
            }
            if (!in_array($row->status, ['failed', 'uncertain', 'cancelled'], true)) {
                throw new HttpException(409);
            }
            if (!app(ForumAnnouncements::class)->eligible($row->post_id)) {
                throw new HttpException(422, __('forum::admin.announcements.not_public'));
            }
            Announcement::query()
                ->where('id', $id)
                ->update([
                    'status' => 'pending',
                    'configuration_id' => $settings->get('forum.discord.configuration_id'),
                    'available_at' => now(),
                    'updated_at' => now(),
                ]);
            Audit::record('forum.announcement_retried', ['id' => $id]);
        }, 3);

        return back()->with('status', __('forum::messages.common.saved'));
    }
}
