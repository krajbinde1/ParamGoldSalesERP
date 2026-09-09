{{--
    Shared Admin summary cards. Pass $cards as a list of:
    label, value, tone|color, meta?, url?, wireClick?, active?
--}}
<div class="paramgold-summary-grid">
    @foreach ($cards as $card)
        @php
            $tone = $card['tone'] ?? $card['color'] ?? 'primary';
            $active = (bool) ($card['active'] ?? false);
            $href = $card['url'] ?? null;
            $wireClick = $card['wireClick'] ?? null;
            $clickable = filled($href) || filled($wireClick);
            $tag = filled($href) ? 'a' : (filled($wireClick) ? 'button' : 'div');
        @endphp
        <{{ $tag }}
            @if ($tag === 'a') href="{{ $href }}" @endif
            @if ($tag === 'button') type="button" wire:click="{!! $wireClick !!}" aria-pressed="{{ $active ? 'true' : 'false' }}" @endif
            @class([
                'paramgold-summary-card',
                'paramgold-summary-card--'.$tone,
                'paramgold-summary-card--clickable' => $clickable,
                'paramgold-summary-card--active' => $active,
            ])
        >
            <p class="paramgold-summary-card__label">{{ $card['label'] }}</p>
            <p class="paramgold-summary-card__value">{{ $card['value'] }}</p>
            @if (filled($card['meta'] ?? null))
                <p class="paramgold-summary-card__meta">{{ $card['meta'] }}</p>
            @endif
        </{{ $tag }}>
    @endforeach
</div>
