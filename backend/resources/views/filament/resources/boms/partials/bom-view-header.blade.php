@php
    use App\Enums\BomOutputType;
    use App\Enums\BomStatus;
    use App\Models\Bom;
    use Filament\Support\Enums\Alignment;

    /** @var Bom $record */
    $stage = $record->output_type instanceof BomOutputType
        ? $record->output_type
        : BomOutputType::tryFrom((string) $record->output_type);
    $status = $record->status instanceof BomStatus
        ? $record->status
        : BomStatus::tryFrom((string) $record->status);

    $stageStyles = $stage === BomOutputType::SemiFinished
        ? 'background:#F0FDFA;color:#0F766E;border:1px solid #99F6E4;'
        : 'background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;';
    $statusStyles = $status === BomStatus::Active
        ? 'background:#ECFDF5;color:#047857;border:1px solid #A7F3D0;'
        : 'background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA;';

    $effectiveDate = $record->effective_date
        ? $record->effective_date->format('d M Y')
        : '—';
    $actionsAlignment = $actionsAlignment ?? Alignment::Start;
@endphp

@include('filament.resources.boms.partials.bom-view-styles')

<header class="fi-header pg-bom-view-header">
    <div class="pg-bom-view-header__main">
        @if (! empty($breadcrumbs))
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        @endif

        <h1 class="pg-bom-view-header__title">{{ $record->bom_number ?: 'Bill of Material' }}</h1>
        <p class="pg-bom-view-header__product">{{ $record->outputName() }}</p>

        <div class="pg-bom-view-header__meta">
            <span class="pg-bom-view-header__badge" style="{{ $stageStyles }}">
                {{ $stage?->label() ?? '—' }}
            </span>
            <span class="pg-bom-view-header__badge" style="{{ $statusStyles }}">
                {{ $status?->label() ?? '—' }}
            </span>
            <p class="pg-bom-view-header__date">
                <span>Effective Date:</span>
                {{ $effectiveDate }}
            </p>
        </div>
    </div>

    @if (! empty($actions))
        <div class="fi-header-actions-ctn">
            <x-filament::actions
                :actions="$actions"
                :alignment="$actionsAlignment"
            />
        </div>
    @endif
</header>
