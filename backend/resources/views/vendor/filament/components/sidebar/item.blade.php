@props([
    'active' => false,
    'activeChildItems' => false,
    'activeIcon' => null,
    'badge' => null,
    'badgeColor' => null,
    'badgeTooltip' => null,
    'childItems' => [],
    'first' => false,
    'grouped' => false,
    'icon' => null,
    'last' => false,
    'shouldOpenUrlInNewTab' => false,
    'sidebarCollapsible' => true,
    'subGrouped' => false,
    'subNavigation' => false,
    'url',
])

@php
    $sidebarCollapsible = $sidebarCollapsible && filament()->isSidebarCollapsibleOnDesktop();
    // Parents with children + no leaf URL use accordion (collapsed by default).
    $hasAccordionChildren = filled($childItems) && (! $subGrouped);
    $accordionKey = $hasAccordionChildren
        ? 'fi_nav_accordion_' . \Illuminate\Support\Str::slug(trim(strip_tags($slot->toHtml())))
        : null;
@endphp

<li
    @if ($hasAccordionChildren)
        x-data="{
            storageKey: @js($accordionKey),
            hasActiveChild: @js((bool) $activeChildItems),
            open: false,
            tooltip: false,
            init() {
                // Default: collapsed. Expand only when a child route is active.
                this.open = this.hasActiveChild;
            },
            toggle() {
                this.open = ! this.open;
                try {
                    localStorage.setItem(this.storageKey, this.open ? '1' : '0');
                } catch (e) {}
            },
        }"
        x-effect="
            @if ($sidebarCollapsible && (! $subNavigation))
                tooltip = $store.sidebar.isOpen
                    ? false
                    : {
                          content: @js($slot->toHtml()),
                          placement: document.dir === 'rtl' ? 'left' : 'right',
                          theme: $store.theme,
                      }
            @endif
        "
    @endif
    {{
        $attributes->class([
            'fi-sidebar-item',
            'fi-sidebar-item-accordion' => $hasAccordionChildren,
            'fi-active' => $active,
            'fi-sidebar-item-has-active-child-items' => $activeChildItems,
            'fi-sidebar-item-has-url' => filled($url) || $hasAccordionChildren,
        ])
    }}
>
    @if ($hasAccordionChildren)
        <button
            type="button"
            x-on:click="toggle()"
            @if ($sidebarCollapsible && (! $subNavigation))
                x-tooltip.html="tooltip"
            @endif
            class="fi-sidebar-item-btn fi-sidebar-item-accordion-btn"
            x-bind:aria-expanded="open.toString()"
        >
            @if (filled($icon))
                {{
                    \Filament\Support\generate_icon_html(
                        ($active && $activeIcon) ? $activeIcon : $icon,
                        attributes: (new \Illuminate\View\ComponentAttributeBag)->class(['fi-sidebar-item-icon']),
                        size: \Filament\Support\Enums\IconSize::Large,
                    )
                }}
            @endif

            <span
                @if ($sidebarCollapsible && (! $subNavigation))
                    x-show="$store.sidebar.isOpen"
                    x-transition:enter="fi-transition-enter"
                    x-transition:enter-start="fi-transition-enter-start"
                    x-transition:enter-end="fi-transition-enter-end"
                @endif
                class="fi-sidebar-item-label"
            >
                {{ $slot }}
            </span>

            @if (filled($badge))
                <span
                    @if ($sidebarCollapsible && (! $subNavigation))
                        x-show="$store.sidebar.isOpen"
                        x-transition:enter="fi-transition-enter"
                        x-transition:enter-start="fi-transition-enter-start"
                        x-transition:enter-end="fi-transition-enter-end"
                    @endif
                    class="fi-sidebar-item-badge-ctn"
                >
                    <x-filament::badge
                        :color="$badgeColor"
                        :tooltip="$badgeTooltip"
                    >
                        {{ $badge }}
                    </x-filament::badge>
                </span>
            @endif

            <span
                @if ($sidebarCollapsible && (! $subNavigation))
                    x-show="$store.sidebar.isOpen"
                @endif
                class="fi-sidebar-item-accordion-chevron"
                aria-hidden="true"
            >
                {{
                    \Filament\Support\generate_icon_html(
                        \Filament\Support\Icons\Heroicon::ChevronRight,
                        attributes: (new \Illuminate\View\ComponentAttributeBag([
                            'x-bind:class' => "{ 'fi-expanded': open }",
                        ]))->class(['fi-sidebar-item-accordion-chevron-icon']),
                        size: \Filament\Support\Enums\IconSize::Small,
                    )
                }}
            </span>
        </button>

        <ul
            x-show="open"
            x-collapse.duration.200ms
            class="fi-sidebar-sub-group-items fi-sidebar-accordion-items"
        >
            @foreach ($childItems as $childItem)
                @php
                    $isChildItemChildItemsActive = $childItem->isChildItemsActive();
                    $isChildActive = (! $isChildItemChildItemsActive) && $childItem->isActive();
                    $childItemActiveIcon = $childItem->getActiveIcon();
                    $childItemBadge = $childItem->getBadge();
                    $childItemBadgeColor = $childItem->getBadgeColor($childItemBadge);
                    $childItemBadgeTooltip = $childItem->getBadgeTooltip($childItemBadge);
                    $childItemIcon = $childItem->getIcon();
                    $shouldChildItemOpenUrlInNewTab = $childItem->shouldOpenUrlInNewTab();
                    $childItemUrl = $childItem->getUrl();
                    $childItemExtraAttributes = $childItem->getExtraAttributeBag();
                @endphp

                <x-filament-panels::sidebar.item
                    :active="$isChildActive"
                    :active-child-items="$isChildItemChildItemsActive"
                    :active-icon="$childItemActiveIcon"
                    :badge="$childItemBadge"
                    :badge-color="$childItemBadgeColor"
                    :badge-tooltip="$childItemBadgeTooltip"
                    :first="$loop->first"
                    grouped
                    :icon="$childItemIcon"
                    :last="$loop->last"
                    :should-open-url-in-new-tab="$shouldChildItemOpenUrlInNewTab"
                    sub-grouped
                    :sub-navigation="$subNavigation"
                    :url="$childItemUrl"
                    :attributes="\Filament\Support\prepare_inherited_attributes($childItemExtraAttributes)"
                >
                    {{ $childItem->getLabel() }}
                </x-filament-panels::sidebar.item>
            @endforeach
        </ul>
    @else
        <a
            {{ \Filament\Support\generate_href_html($url, $shouldOpenUrlInNewTab) }}
            x-on:click="window.matchMedia(`(max-width: 1024px)`).matches && $store.sidebar.close()"
            @if ($sidebarCollapsible && (! $subNavigation))
                x-data="{ tooltip: false }"
                x-effect="
                    tooltip = $store.sidebar.isOpen
                        ? false
                        : {
                              content: @js($slot->toHtml()),
                              placement: document.dir === 'rtl' ? 'left' : 'right',
                              theme: $store.theme,
                          }
                "
                x-tooltip.html="tooltip"
            @endif
            class="fi-sidebar-item-btn"
        >
            @if (filled($icon) && ((! $subGrouped) || ($sidebarCollapsible && (! $subNavigation))))
                {{
                    \Filament\Support\generate_icon_html(($active && $activeIcon) ? $activeIcon : $icon, attributes: (new \Illuminate\View\ComponentAttributeBag([
                        'x-show' => ($subGrouped && $sidebarCollapsible) ? '! $store.sidebar.isOpen' : false,
                    ]))->class(['fi-sidebar-item-icon']), size: \Filament\Support\Enums\IconSize::Large)
                }}
            @endif

            @if ($subGrouped)
                <span
                    @if (filled($icon) && $sidebarCollapsible && (! $subNavigation))
                        x-show="$store.sidebar.isOpen"
                    @endif
                    class="fi-sidebar-item-bullet"
                    aria-hidden="true"
                >•</span>
            @elseif ((blank($icon) && $grouped))
                <div class="fi-sidebar-item-spacer"></div>
            @endif

            <span
                @if ($sidebarCollapsible && (! $subNavigation))
                    x-show="$store.sidebar.isOpen"
                    x-transition:enter="fi-transition-enter"
                    x-transition:enter-start="fi-transition-enter-start"
                    x-transition:enter-end="fi-transition-enter-end"
                @endif
                class="fi-sidebar-item-label"
            >
                {{ $slot }}
            </span>

            @if (filled($badge))
                <span
                    @if ($sidebarCollapsible && (! $subNavigation))
                        x-show="$store.sidebar.isOpen"
                        x-transition:enter="fi-transition-enter"
                        x-transition:enter-start="fi-transition-enter-start"
                        x-transition:enter-end="fi-transition-enter-end"
                    @endif
                    class="fi-sidebar-item-badge-ctn"
                >
                    <x-filament::badge
                        :color="$badgeColor"
                        :tooltip="$badgeTooltip"
                    >
                        {{ $badge }}
                    </x-filament::badge>
                </span>
            @endif
        </a>

        {{-- Only linked parents with an active child keep Filament's non-accordion nested list. Blank-URL parents use accordion above. --}}
        @if ($childItems && filled($url) && ($active || $activeChildItems))
            <ul class="fi-sidebar-sub-group-items">
                @foreach ($childItems as $childItem)
                    @php
                        $isChildItemChildItemsActive = $childItem->isChildItemsActive();
                        $isChildActive = (! $isChildItemChildItemsActive) && $childItem->isActive();
                        $childItemActiveIcon = $childItem->getActiveIcon();
                        $childItemBadge = $childItem->getBadge();
                        $childItemBadgeColor = $childItem->getBadgeColor($childItemBadge);
                        $childItemBadgeTooltip = $childItem->getBadgeTooltip($childItemBadge);
                        $childItemIcon = $childItem->getIcon();
                        $shouldChildItemOpenUrlInNewTab = $childItem->shouldOpenUrlInNewTab();
                        $childItemUrl = $childItem->getUrl();
                        $childItemExtraAttributes = $childItem->getExtraAttributeBag();
                    @endphp

                    <x-filament-panels::sidebar.item
                        :active="$isChildActive"
                        :active-child-items="$isChildItemChildItemsActive"
                        :active-icon="$childItemActiveIcon"
                        :badge="$childItemBadge"
                        :badge-color="$childItemBadgeColor"
                        :badge-tooltip="$childItemBadgeTooltip"
                        :first="$loop->first"
                        grouped
                        :icon="$childItemIcon"
                        :last="$loop->last"
                        :should-open-url-in-new-tab="$shouldChildItemOpenUrlInNewTab"
                        sub-grouped
                        :sub-navigation="$subNavigation"
                        :url="$childItemUrl"
                        :attributes="\Filament\Support\prepare_inherited_attributes($childItemExtraAttributes)"
                    >
                        {{ $childItem->getLabel() }}
                    </x-filament-panels::sidebar.item>
                @endforeach
            </ul>
        @endif
    @endif
</li>
