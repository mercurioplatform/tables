@if ($paginator->isCursor())
    @if ($paginator->hasPages())
        <nav aria-label="{{ __('tables::shell.pagination.aria') }}">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() ?? '#' }}" aria-label="{{ __('tables::shell.pagination.prev_aria') }}">
                        <i class="bi bi-chevron-left"></i>
                        <span>{{ __('tables::shell.pagination.previous') }}</span>
                    </a>
                </li>
                <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() ?? '#' }}" aria-label="{{ __('tables::shell.pagination.next_aria') }}">
                        <span>{{ __('tables::shell.pagination.next') }}</span>
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    @endif
@else
    @if ($paginator->hasPages())
        <nav aria-label="{{ __('tables::shell.pagination.aria') }}">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() ?? '#' }}" aria-label="{{ __('tables::shell.pagination.prev_aria') }}">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li class="page-item disabled"><span class="page-link u-mono">{{ $element }}</span></li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <li class="page-item {{ $page == $paginator->currentPage() ? 'active' : '' }}">
                                <a class="page-link u-mono" href="{{ $url }}">{{ $page }}</a>
                            </li>
                        @endforeach
                    @endif
                @endforeach

                <li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">
                    <a class="page-link" href="{{ $paginator->nextPageUrl() ?? '#' }}" aria-label="{{ __('tables::shell.pagination.next_aria') }}">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    @endif
@endif
