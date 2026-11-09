<section class="card h-100">
    <div class="card-body">
        <h2 class="h5 card-title mb-3">{{ __('forum::widgets.latest_topics') }}</h2>
        <ul class="list-unstyled mb-0">
            @forelse ($topics as $topic)
                <li class="mb-2">
                    <a href="{{ route('plugins.forum.public.topics.show', ['id' => $topic->id]) }}">{{ $topic->title }}</a>
                </li>
            @empty
                <li class="text-body-secondary">{{ __('forum::widgets.empty') }}</li>
            @endforelse
        </ul>
    </div>
</section>
