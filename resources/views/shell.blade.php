@php
    /** @var \Mercurio\Tables\ResourceTable $table */
    $resource = $table->resource;
    $layout = $resource?->layout() ?? (string) config('tables.shell.layout', 'admin.layouts.app');
    $browserTitle = $resource?->browserTitle();
    $pageTitle = $resource?->pageTitle();
    $subtitle = $resource ? $resource->subtitle($table->paginator->total()) : null;
    $actions = $resource?->headerActions() ?? [];
    $crumbs = $resource?->breadcrumbs() ?? [];
    $flashKeys = $resource?->flashKeys() ?? (array) config('tables.shell.flash_keys', []);
    $pageHeadComponent = (string) config('tables.shell.page_head_component', 'tables.page-head');
    $bulkActionUrl = $bulkActionUrl ?? '';

    $titleSuffix = config('tables.shell.title_suffix');
    $finalBrowserTitle = ($browserTitle !== null && is_string($titleSuffix) && $titleSuffix !== '')
        ? sprintf($titleSuffix, $browserTitle)
        : $browserTitle;
@endphp

@extends($layout)

@if($finalBrowserTitle !== null)
    @section('title', $finalBrowserTitle)
@endif

@if(!empty($crumbs))
    @push('crumbs')
        @foreach($crumbs as $i => $crumb)
            @if($crumb->url !== null)
                <a href="{{ $crumb->url }}">{{ $crumb->label }}</a>
            @else
                <span class="ap-crumbs__current">{{ $crumb->label }}</span>
            @endif
            @if($i < count($crumbs) - 1)
                <span class="ap-crumbs__sep">/</span>
            @endif
        @endforeach
    @endpush
@endif

@section('content')
    @if($pageTitle !== null || !empty($actions))
        <x-dynamic-component :component="$pageHeadComponent" :title="$pageTitle ?? ''" :title-large="false">
            @if(!empty($actions))
                <x-slot:actions>
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
                </x-slot:actions>
            @endif
        </x-dynamic-component>
    @endif

    {{-- Subtitle: внешний контейнер для AJAX-замены, чтобы не зависеть от того, поддерживает ли page-head компонент `data-tables-subtitle`. --}}
    <p class="ap-page-head__sub @if($subtitle === null) d-none @endif" data-tables-subtitle>{{ $subtitle ?? '' }}</p>

    @foreach($flashKeys as $key => $variant)
        @if(session()->has($key))
            <div class="alert alert-{{ $variant }} py-2 small" role="alert">{{ session($key) }}</div>
        @endif
    @endforeach

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <x-tables.page :table="$table" :bulk-action="$bulkActionUrl"/>
@endsection
