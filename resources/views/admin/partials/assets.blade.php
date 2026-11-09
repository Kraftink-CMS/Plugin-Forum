@once
    @push('scripts')
        <script type="module"
            src="{{ route('site.extensions.assets.show', ['id' => 'forum', 'asset' => 'js/forum.js']) }}">
        </script>
    @endpush
@endonce
