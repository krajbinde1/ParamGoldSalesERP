<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Requests</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    {{ $this->requestCount }}
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <div class="text-sm text-gray-500 dark:text-gray-400">Total Amount</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">
                    ₹{{ number_format($this->totalAmount, 2) }}
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            <form wire:submit="submit" class="space-y-6">
                {{ $this->form }}

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">Submit All Requests</span>
                        <span wire:loading wire:target="submit">Submitting...</span>
                    </x-filament::button>
                </div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
