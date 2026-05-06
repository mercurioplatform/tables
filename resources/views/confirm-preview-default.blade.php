@php /** @var array<string, mixed> $data */ @endphp
<dl class="ap-confirm-preview__data row mb-0 small">
    @foreach ($data as $key => $value)
        <dt class="col-sm-4 text-muted">{{ is_string($key) ? $key : $loop->index + 1 }}</dt>
        <dd class="col-sm-8 mb-2">
            @if (is_scalar($value) || $value === null)
                {{ $value === null ? '—' : $value }}
            @else
                <code class="small">{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</code>
            @endif
        </dd>
    @endforeach
</dl>
