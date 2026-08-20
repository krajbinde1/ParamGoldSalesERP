<x-filament-panels::page>
    <div class="grid gap-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Total Farmers</div>
                <div class="mt-1 text-2xl font-semibold">{{ \App\Models\Farmer::query()->count() }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">New Today</div>
                <div class="mt-1 text-2xl font-semibold">{{ \App\Models\Farmer::query()->whereDate('created_at', now('Asia/Kolkata')->toDateString())->count() }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">New This Month</div>
                <div class="mt-1 text-2xl font-semibold">{{ \App\Models\Farmer::query()->whereDate('created_at', '>=', now('Asia/Kolkata')->startOfMonth()->toDateString())->count() }}</div>
            </div>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500">Field Activities</div>
                <div class="mt-1 text-2xl font-semibold">{{ \App\Models\FieldActivity::query()->count() }}</div>
            </div>
        </div>

        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold">District-wise Farmers</h2>
            <p class="mt-1 text-sm text-gray-500">Click a district to see taluka breakup and open the farmer list.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2">District</th>
                            <th class="py-2">Farmers</th>
                            <th class="py-2">Field Activities</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->districtRows() as $row)
                            <tr
                                wire:click="selectDistrict({{ $row->id }})"
                                class="cursor-pointer border-t border-gray-100 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5 {{ $this->districtId === $row->id ? 'bg-primary-50 dark:bg-primary-500/10' : '' }}"
                            >
                                <td class="py-2 font-medium">{{ $row->name }}</td>
                                <td class="py-2">
                                    <a href="{{ $this->farmersIndexUrl($row->id) }}" class="text-primary-600 hover:underline" wire:click.stop>
                                        {{ $row->farmers }}
                                    </a>
                                </td>
                                <td class="py-2">{{ $row->activities }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-4 text-gray-500">No district farmer data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($this->districtId)
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-base font-semibold">Taluka-wise — {{ $this->selectedDistrictName() }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2">Taluka</th>
                                <th class="py-2">Farmers</th>
                                <th class="py-2">Field Activities</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->talukaRows() as $row)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    <td class="py-2 font-medium">{{ $row->name }}</td>
                                    <td class="py-2">
                                        <a href="{{ $this->farmersIndexUrl($this->districtId, $row->id) }}" class="text-primary-600 hover:underline">
                                            {{ $row->farmers }}
                                        </a>
                                    </td>
                                    <td class="py-2">{{ $row->activities }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-4 text-gray-500">No taluka data for this district yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-base font-semibold">Crop-wise</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2">Crop</th>
                                <th class="py-2">Farmers</th>
                                <th class="py-2">Activities</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->cropRows() as $row)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    <td class="py-2">{{ $row->name }}</td>
                                    <td class="py-2">{{ $row->farmers }}</td>
                                    <td class="py-2">{{ $row->activities }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-4 text-gray-500">No crop recommendations yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-base font-semibold">Product Recommendations</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2">Product</th>
                                <th class="py-2">Recommendations</th>
                                <th class="py-2">Farmers</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->productRows() as $row)
                                <tr class="border-t border-gray-100 dark:border-white/10">
                                    <td class="py-2">{{ $row->name }}</td>
                                    <td class="py-2">{{ $row->recommendations }}</td>
                                    <td class="py-2">{{ $row->farmers }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-4 text-gray-500">No product recommendations yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
