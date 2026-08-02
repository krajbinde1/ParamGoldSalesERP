<x-filament-panels::page>
    <form wire:submit.prevent class="fi-sc space-y-6">
        {{ $this->form }}
    </form>

    <x-filament::section class="mt-6" icon="heroicon-o-clipboard-document-check" icon-color="primary">
        <x-slot name="heading">
            Production Confirmation
        </x-slot>
        <x-slot name="description">
            Review materials, costs, and stock impact before posting. Confirmation deducts materials and posts finished goods.
        </x-slot>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::button
                color="gray"
                tag="a"
                icon="heroicon-o-arrow-left"
                :href="\App\Filament\Resources\ProductionBatches\ProductionBatchResource::getUrl('index')"
            >
                Back
            </x-filament::button>

            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button
                    color="gray"
                    tag="a"
                    :href="\App\Filament\Resources\ProductionBatches\ProductionBatchResource::getUrl('index')"
                >
                    Cancel
                </x-filament::button>

                {{ $this->reviewProductionAction }}
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
