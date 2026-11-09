<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <form class="vstack gap-2 flex-grow-1" method="get" action="{{ route('plugins.forum.public.search.index') }}">
        <div class="input-group">
            <input class="form-control" type="search" name="q" value="{{ request('q') }}" required minlength="2"
                maxlength="100" placeholder="{{ __('forum::messages.search.search_help') }}"
                aria-label="{{ __('forum::messages.search.search') }}">
            <button class="btn btn-primary" type="submit" aria-label="{{ __('forum::messages.search.search') }}"><i
                    class="bi bi-search" aria-hidden="true"></i></button>
        </div>
        @if ($showSearchFilters ?? false)
            <div class="row g-2">
                <div class="col-sm">
                    <select class="form-select" name="category" aria-label="{{ __('forum::admin.moderation.category') }}">
                        <option value="">{{ __('forum::admin.moderation.all_categories') }}</option>
                        @foreach ($categories as $searchCategory)
                            <option value="{{ $searchCategory->id }}" @selected(request('category') == $searchCategory->id)>{{ $searchCategory->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($tags->isNotEmpty())
                    <div class="col-sm">
                        <select class="form-select" name="tag" aria-label="{{ __('forum::admin.tags.tags') }}">
                            <option value="">{{ __('forum::messages.search.all_tags') }}</option>
                            @foreach ($tags as $tag)
                                <option value="{{ $tag->id }}" @selected(request('tag') == $tag->id)>{{ $tag->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        @endif
    </form>
    @auth
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('plugins.forum.public.topics.following') }}"><i
                    class="bi bi-bookmark me-1" aria-hidden="true"></i>{{ __('forum::messages.following.following') }}</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('plugins.forum.public.profiles.edit') }}"><i
                    class="bi bi-person me-1" aria-hidden="true"></i>{{ __('forum::messages.profile.edit_profile') }}</a>
        </div>
    @endauth
</div>
