@php
    /** @var \Filament\Actions\ActionGroup $group */
@endphp
<div {{ $group->getExtraAttributeBag()->class(['pg-dealer-ledger-mapping-actions']) }}>
    @foreach ($group->getActions() as $action)
        @if ($action->isVisible())
            {{ $action }}
        @endif
    @endforeach
</div>
