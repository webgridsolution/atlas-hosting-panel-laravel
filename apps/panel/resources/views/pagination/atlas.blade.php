{{--
    Atlas pagination — hand-written, no CSS framework.

    Laravel's stock paginator views are Tailwind markup. There is no Tailwind in
    this project, so using them produced a wall of unstyled links on every
    paginated screen (that was the "pagination error"). This replaces them.

    Accessibility and behaviour that matter for a control panel:
      • a named landmark with a label, not a bare <div>
      • aria-current="true" on the active page
      • rel="prev"/"next" so browsers and crawlers can follow it
      • the range ("21–40 of 137") so an operator knows where they are
      • the first and last page are always one click away, with ellipsis when
        the gap is large — an admin should not have to page through 40 screens
      • nothing is rendered at all when there is one page
--}}
@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();

        // Five clickable pages centred on the current one, then first/last.
        $start = max(1, $current - 2);
        $end = min($last, $start + 4);
        $start = max(1, $end - 4);

        $window = [];
        for ($page = $start; $page <= $end; $page++) {
            $window[] = $page;
        }
    @endphp

    <nav class="pager" aria-label="Pagination">
        <p class="pager-summary">
            Showing
            <strong>{{ number_format($paginator->firstItem() ?? 0) }}–{{ number_format($paginator->lastItem() ?? 0) }}</strong>
            of <strong>{{ number_format($paginator->total()) }}</strong>
        </p>

        <ul class="pager-list">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pager-btn is-disabled" aria-disabled="true">
                        <span class="pager-arrow" aria-hidden="true">‹</span>
                        <span class="pager-word">Previous</span>
                    </span>
                @else
                    <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                        <span class="pager-arrow" aria-hidden="true">‹</span>
                        <span class="pager-word">Previous</span>
                    </a>
                @endif
            </li>

            @if ($start > 1)
                <li>
                    <a class="pager-btn" href="{{ $paginator->url(1) }}" aria-label="Page 1">1</a>
                </li>
                @if ($start > 2)
                    <li><span class="pager-gap" aria-hidden="true">…</span></li>
                @endif
            @endif

            @foreach ($window as $page)
                <li>
                    @if ($page === $current)
                        <span class="pager-btn is-current" aria-current="true" aria-label="Page {{ $page }}, current">{{ $page }}</span>
                    @else
                        <a class="pager-btn" href="{{ $paginator->url($page) }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                    @endif
                </li>
            @endforeach

            @if ($end < $last)
                @if ($end < $last - 1)
                    <li><span class="pager-gap" aria-hidden="true">…</span></li>
                @endif
                <li>
                    <a class="pager-btn" href="{{ $paginator->url($last) }}" aria-label="Page {{ $last }}">{{ $last }}</a>
                </li>
            @endif

            <li>
                @if ($paginator->hasMorePages())
                    <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next">
                        <span class="pager-word">Next</span>
                        <span class="pager-arrow" aria-hidden="true">›</span>
                    </a>
                @else
                    <span class="pager-btn is-disabled" aria-disabled="true">
                        <span class="pager-word">Next</span>
                        <span class="pager-arrow" aria-hidden="true">›</span>
                    </span>
                @endif
            </li>
        </ul>
    </nav>
@endif
