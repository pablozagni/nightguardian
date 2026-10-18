<x-filament-widgets::widget>
    @php($cards = $this->getCards())

    <style>
        .ng-cards { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr)); }
        .ng-card { display: block; padding: 1.25rem; border-radius: .75rem; background: #fff; box-shadow: 0 0 0 1px rgb(3 7 18 / .06), 0 1px 2px rgb(3 7 18 / .05); color: inherit; text-decoration: none; transition: box-shadow .15s; }
        .ng-card:hover { box-shadow: 0 0 0 2px rgb(99 102 241 / .6); }
        .dark .ng-card { background: rgb(255 255 255 / .05); box-shadow: 0 0 0 1px rgb(255 255 255 / .1); }
        .ng-card-name { margin: 0 0 1rem; font-size: 1.05rem; font-weight: 600; }
        .ng-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-bottom: 1rem; }
        .ng-stat-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
        .ng-stat-value.is-alert { color: rgb(220 38 38); }
        .dark .ng-stat-value.is-alert { color: rgb(248 113 113); }
        .ng-stat-label { font-size: .75rem; color: rgb(107 114 128); }
        .ng-bars { display: flex; align-items: flex-end; gap: .35rem; height: 3.5rem; }
        .ng-bar-col { flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: stretch; }
        .ng-bar { border-radius: .2rem .2rem 0 0; background: rgb(99 102 241 / .75); }
        .ng-bar.is-empty { background: rgb(156 163 175 / .35); }
        .ng-bar-labels { display: flex; gap: .35rem; margin-top: .25rem; font-size: .65rem; color: rgb(107 114 128); }
        .ng-bar-labels span { flex: 1; text-align: center; }
        .ng-empty { padding: 2rem; text-align: center; color: rgb(107 114 128); }
    </style>

    @if ($cards === [])
        <div class="ng-empty">{{ __('nightguardian.dashboard.empty') }}</div>
    @else
        <div class="ng-cards">
            @foreach ($cards as $card)
                <a class="ng-card" href="{{ $card['url'] }}" wire:key="card-{{ $card['id'] }}">
                    <h3 class="ng-card-name">{{ $card['name'] }}</h3>
                    <div class="ng-stats">
                        <div>
                            <div class="ng-stat-value {{ $card['open'] > 0 ? 'is-alert' : '' }}">{{ $card['open'] }}</div>
                            <div class="ng-stat-label">{{ __('nightguardian.dashboard.open_issues') }}</div>
                        </div>
                        <div>
                            <div class="ng-stat-value">{{ $card['new_today'] }}</div>
                            <div class="ng-stat-label">{{ __('nightguardian.dashboard.new_today') }}</div>
                        </div>
                        <div>
                            <div class="ng-stat-value">{{ $card['events_24h'] }}</div>
                            <div class="ng-stat-label">{{ __('nightguardian.dashboard.events_24h') }}</div>
                        </div>
                    </div>
                    <div class="ng-bars" role="img" aria-label="{{ __('nightguardian.dashboard.chart') }}">
                        @foreach ($card['days'] as $day)
                            <div class="ng-bar-col" title="{{ $day['label'] }}: {{ $day['count'] }}">
                                <div class="ng-bar {{ $day['count'] === 0 ? 'is-empty' : '' }}" style="height: {{ $day['height'] }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="ng-bar-labels">
                        @foreach ($card['days'] as $day)
                            <span>{{ $day['label'] }}</span>
                        @endforeach
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
