<div class="list-group forum-list mb-4">
    @foreach ($items as $category)
        <div class="list-group-item p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div class="d-flex align-items-start gap-3">
                    <span
                        class="forum-category-icon d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary p-3">
                        <x-content-icon :name="$category->icon" class="fs-5" />
                    </span>
                    <div>
                        <h2 class="h5 mb-1"><a class="text-decoration-none"
                                href="{{ route('plugins.forum.public.categories.show', $category->id) }}">{{ $category->name }}</a>
                        </h2>
                        @if ($category->description)
                            <p class="small text-body-secondary mb-0">{{ $category->description }}</p>
                        @endif
                    </div>
                </div>
                <span
                    class="badge bg-body-tertiary text-body-secondary border align-self-center">{{ $topicCounts[$category->id] ?? 0 }}</span>
            </div>
            @if ($categories->where('parent_id', $category->id)->isNotEmpty())
                <div class="ms-md-5 mt-3">
                    @include('forum::default.categories.partials.tree', [
                        'items' => $categories->where('parent_id', $category->id),
                    ])
                </div>
            @endif
        </div>
    @endforeach
</div>
