@php
    $position = (string) config('tables.bulk_progress.tray_position', 'bottom-right');
    $pollIntervalMs = (int) config('tables.bulk_progress.poll_interval_ms', 1500);
    $pollMaxDurationMs = (int) config('tables.bulk_progress.poll_max_duration_ms', 600000);
    $toastAnchor = $position === 'top-right' ? 'top-0 end-0' : 'bottom-0 end-0';
@endphp

<div
    id="tables-progress-tray"
    class="tables-progress-tray tables-progress-tray--{{ $position }}"
    data-tables-progress-tray
    data-poll-interval-ms="{{ $pollIntervalMs }}"
    data-poll-max-duration-ms="{{ $pollMaxDurationMs }}"
    aria-live="polite"
    aria-atomic="false"
></div>

<div
    class="toast-container position-fixed {{ $toastAnchor }} p-3"
    id="tables-progress-toasts"
    data-tables-progress-toasts
    style="z-index: 1090;"
></div>
