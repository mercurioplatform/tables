@props([
    'title',
    'titleLarge' => false,
    /** @var array<int, \Mercurio\Tables\Page\HeaderAction> */
    'actions' => [],
])

<div class="ap-page-head">
    <div class="ap-page-head__body">
        <h1 class="ap-page-head__title @if($titleLarge) ap-page-head__title--large @endif">{{ $title }}</h1>
    </div>
    @if(!empty($actions))
        <div class="ap-page-head__actions">
            @foreach($actions as $action)
                <a
                    href="{{ $action->url }}"
                    class="btn btn-{{ $action->variant }} btn-{{ $action->size }}"
                    @if($action->target) target="{{ $action->target }}" @endif
                    @foreach($action->attrs as $k => $v) {{ $k }}="{{ $v }}" @endforeach
                >
                    @if($action->icon)<i class="bi {{ $action->icon }}"></i> @endif{{ $action->label }}
                </a>
            @endforeach
        </div>
    @endif
</div>
