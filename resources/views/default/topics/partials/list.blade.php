@use('App\Kraft\SiteDates')
@use('KraftInk\Plugins\Forum\Services\TopicUrl')
<div class="card site-panel mb-4">
    @if ($topics instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <x-tables.controls :paginator="$topics" container-class="card-body" />
    @endif
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="text-nowrap">
                <tr>
                    <th scope="col" data-table-sort="title">{{ __('forum::messages.discussions.subject') }}</th>
                    <th scope="col" data-table-sort="views">{{ __('forum::admin.moderation.views') }}</th>
                    <th scope="col" data-table-sort="updated_at">{{ __('forum::messages.search.latest') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($topics as $topic)
                    <tr>
                        <td class="py-3"><a class="fw-medium text-decoration-none"
                                href="{{ $topic->matching_post_id ? TopicUrl::forPost($topic->id, (int) $topic->matching_post_id) : TopicUrl::for($topic) }}">{{ $topic->title }}</a>
                            @if ($topic->pinned)
                                <span class="badge text-bg-light border ms-2">{{ __('forum::messages.discussions.pinned') }}</span>
                            @endif
                            @if ($topic->locked)
                                <span class="badge text-bg-secondary ms-2">{{ __('forum::messages.discussions.locked') }}</span>
                            @endif
                            @if (isset($topicTags))
                                <div class="mt-1">
                                    @foreach ($topicTags[$topic->id] ?? [] as $tag)
                                        <span class="badge border text-bg-light"
                                            style="color:{{ $tag->getAttribute('color') }}">{{ $tag->getAttribute('name') }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="text-body-secondary small">{{ $topic->views }}</td>
                        <td class="text-body-secondary small text-nowrap"><time
                                datetime="{{ app(SiteDates::class)->iso($topic->updated_at) }}">{{ app(SiteDates::class)->format($topic->updated_at) }}</time>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-body-secondary py-5">
                            {{ __('forum::admin.moderation.no_topics') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($topics instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="card-footer">
            {{ $topics->links() }}
        </div>
    @endif
</div>
