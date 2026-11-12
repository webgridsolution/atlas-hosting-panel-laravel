{{-- Atlas simple paginator (used by cursor / simplePaginate lists). --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="Pagination">
        <ul class="pager-list">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pager-btn is-disabled" aria-disabled="true"><span class="pager-arrow" aria-hidden="true">‹</span> <span class="pager-word">Previous</span></span>
                @else
                    <a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev"><span class="pager-arrow" aria-hidden="true">‹</span> <span class="pager-word">Previous</span></a>
                @endif
            </li>
            <li>
                @if ($paginator->hasMorePages())
                    <a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next"><span class="pager-word">Next</span> <span class="pager-arrow" aria-hidden="true">›</span></a>
                @else
                    <span class="pager-btn is-disabled" aria-disabled="true"><span class="pager-word">Next</span> <span class="pager-arrow" aria-hidden="true">›</span></span>
                @endif
            </li>
        </ul>
    </nav>
@endif
