@php
    /** @var \App\Models\Event $event */
    $presenter = app(\App\Support\EventPresenter::class);
    $exceptions = $presenter->exceptions($event);
    $request = $presenter->request($event);
    $user = $presenter->user($event);
    $tags = $presenter->tags($event);
    $context = $presenter->context($event);
    $payload = $presenter->payload($event);
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
@endphp

<div class="ng-event">
    @include('filament.partials.event-styles')

    @if ($exceptions !== [])
        @foreach ($exceptions as $index => $exception)
            <section class="ng-block">
                <h3 class="ng-heading">
                    {{ $index === 0 ? __('nightguardian.event.exception') : __('nightguardian.event.caused_by') }}
                </h3>
                <p class="ng-exception">
                    <strong>{{ $exception['class'] }}</strong>@if ($exception['code'] !== null && $exception['code'] !== '') <span class="ng-muted">({{ $exception['code'] }})</span>@endif
                </p>
                <p class="ng-message">{{ $exception['message'] }}</p>

                @foreach ($exception['runs'] as $run)
                    @if ($run['in_app'])
                        @foreach ($run['frames'] as $frame)
                            @include('filament.partials.event-frame', ['frame' => $frame])
                        @endforeach
                    @else
                        <details class="ng-vendor">
                            <summary>{{ trans_choice('nightguardian.event.vendor_frames', count($run['frames']), ['count' => count($run['frames'])]) }}</summary>
                            @foreach ($run['frames'] as $frame)
                                @include('filament.partials.event-frame', ['frame' => $frame])
                            @endforeach
                        </details>
                    @endif
                @endforeach
            </section>
        @endforeach
    @elseif ($event->message !== null && $event->message !== '')
        <section class="ng-block">
            <h3 class="ng-heading">{{ __('nightguardian.event.message') }}</h3>
            <p class="ng-message">{{ $event->message }}</p>
        </section>
    @endif

    @if ($exceptions === [] && $payload !== [])
        <section class="ng-block">
            <h3 class="ng-heading">{{ __('nightguardian.event.details') }}</h3>
            <pre class="ng-json">{{ $presenter->json($payload) }}</pre>
        </section>
    @endif

    @if ($request !== [])
        <section class="ng-block">
            <h3 class="ng-heading">{{ __('nightguardian.event.request') }}</h3>
            <dl class="ng-kv">
                @foreach (['method', 'url', 'route', 'status_code', 'duration_ms', 'ip'] as $key)
                    @if (isset($request[$key]) && is_scalar($request[$key]) && $request[$key] !== '')
                        <dt>{{ $key }}</dt><dd>{{ $request[$key] }}</dd>
                    @endif
                @endforeach
                @foreach ($headers as $name => $value)
                    <dt>{{ $name }}</dt><dd>{{ is_scalar($value) ? $value : $presenter->json($value) }}</dd>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($user !== [])
        <section class="ng-block">
            <h3 class="ng-heading">{{ __('nightguardian.event.user') }}</h3>
            <dl class="ng-kv">
                @foreach ($user as $key => $value)
                    <dt>{{ $key }}</dt><dd>{{ is_scalar($value) ? $value : $presenter->json($value) }}</dd>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($tags !== [])
        <section class="ng-block">
            <h3 class="ng-heading">{{ __('nightguardian.event.tags') }}</h3>
            <p>
                @foreach ($tags as $key => $value)
                    <span class="ng-tag">{{ $key }}: {{ is_scalar($value) ? $value : $presenter->json($value) }}</span>
                @endforeach
            </p>
        </section>
    @endif

    @if ($context !== [])
        <section class="ng-block">
            <h3 class="ng-heading">{{ __('nightguardian.event.context') }}</h3>
            <pre class="ng-json">{{ $presenter->json($context) }}</pre>
        </section>
    @endif
</div>
