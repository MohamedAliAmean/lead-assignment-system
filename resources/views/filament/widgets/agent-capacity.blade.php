{{-- Inline styles on purpose: they use Filament's color variables, so no custom theme build is needed. --}}
<x-filament-widgets::widget>
    <x-filament::section heading="Capacity today" description="Leads received vs. daily capacity">
        @forelse ($agents as $agent)
            @php
                $percent = $agent['capacity'] > 0 ? min(100, (int) round($agent['used'] / $agent['capacity'] * 100)) : 100;
                $color = $percent >= 100 ? 'var(--danger-500)' : ($percent >= 75 ? 'var(--warning-500)' : 'var(--primary-500)');
            @endphp
            <div style="padding: 0.5rem 0; {{ $loop->first ? '' : 'border-top: 1px solid color-mix(in oklab, var(--gray-500) 15%, transparent);' }}">
                <div style="display: flex; justify-content: space-between; font-size: 0.875rem; line-height: 1.25rem;">
                    <span style="font-weight: 500;">{{ $agent['name'] }}</span>
                    <span style="font-variant-numeric: tabular-nums; opacity: 0.7;">
                        {{ $agent['used'] }} / {{ $agent['capacity'] }}
                        @if ($agent['remaining'] === 0) · full @endif
                    </span>
                </div>
                <div role="progressbar" aria-label="{{ $agent['name'] }} capacity used" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
                     style="margin-top: 0.375rem; height: 0.5rem; width: 100%; overflow: hidden; border-radius: 9999px; background: color-mix(in oklab, var(--gray-500) 18%, transparent);">
                    <div style="height: 100%; width: {{ $percent }}%; border-radius: 9999px; background: {{ $color }};"></div>
                </div>
            </div>
        @empty
            <p style="font-size: 0.875rem; opacity: 0.7;">No active agents to show.</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
