@php /** @var array<string, mixed> $frame */ @endphp
<div class="ng-frame {{ $frame['in_app'] ? 'ng-frame-app' : 'ng-frame-vendor' }}">
    <div class="ng-frame-head">
        <span class="ng-file">{{ $frame['file'].($frame['line'] !== null ? ':'.$frame['line'] : '') }}</span>
        @if ($frame['call'] !== '')
            <span class="ng-call">{{ $frame['call'] }}</span>
        @endif
    </div>
    @if ($frame['code'] !== [])
        <pre class="ng-code">@foreach ($frame['code'] as $line)<span class="ng-line {{ $line['current'] ? 'ng-line-current' : '' }}"><span class="ng-no">{{ $line['number'] }}</span>{{ $line['text'] }}</span>@endforeach</pre>
    @endif
</div>
