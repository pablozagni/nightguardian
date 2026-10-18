@if ($event === null)
    <p>{{ __('nightguardian.issues.no_events') }}</p>
@else
    <p style="margin:0 0 1rem;font-size:.875rem;color:rgb(107 114 128)">
        {{ $event->occurred_at->timezone(\App\Filament\Resources\Issues\IssueResource::timezone())->isoFormat('lll') }}
        @if ($event->reference) · <span style="font-family:ui-monospace,Menlo,Consolas,monospace">{{ $event->reference }}</span> @endif
        · <a href="{{ \App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $event]) }}" style="color:rgb(99 102 241)">{{ __('nightguardian.events.open') }}</a>
    </p>
    @include('filament.event-detail', ['event' => $event])
@endif
