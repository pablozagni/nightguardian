@if ($hosts->isEmpty())
    <p style="margin:0;font-size:.875rem;opacity:.7">{{ __('nightguardian.issues.hosts_none') }}</p>
@else
    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
        <thead>
            <tr style="text-align:left;opacity:.7">
                <th style="padding:.4rem .5rem;font-weight:600">{{ __('nightguardian.issues.host') }}</th>
                <th style="padding:.4rem .5rem;font-weight:600;text-align:right">{{ __('nightguardian.issues.events') }}</th>
                <th style="padding:.4rem .5rem;font-weight:600;text-align:right">{{ __('nightguardian.issues.last_seen') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($hosts as $row)
                <tr style="border-top:1px solid rgb(128 128 128 / .25)">
                    <td style="padding:.4rem .5rem;font-family:ui-monospace,Menlo,Consolas,monospace">{{ $row->host }}</td>
                    <td style="padding:.4rem .5rem;text-align:right">{{ number_format((int) $row->events) }}</td>
                    <td style="padding:.4rem .5rem;text-align:right" title="{{ \Illuminate\Support\Carbon::parse($row->last_seen_at, 'UTC')->timezone($timezone)->isoFormat('lll') }}">{{ \Illuminate\Support\Carbon::parse($row->last_seen_at, 'UTC')->diffForHumans() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
