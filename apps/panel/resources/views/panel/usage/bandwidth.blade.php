@extends('layouts.app')

@section('title', 'Bandwidth')

@section('content')
    @php
        $fmtMb = fn (int $mb): string => $mb >= 1024 ? number_format($mb / 1024, 2).' GB' : number_format($mb).' MB';
    @endphp

    <h1>Bandwidth</h1>
    <p class="small muted">Daily traffic for this account, summed from the access, mail and FTP logs by the agent.</p>

    <div class="grid four" style="margin-bottom:16px">
        <div class="stat">
            <div class="label">This month</div>
            <div class="value">{{ $fmtMb($total) }}</div>
            <div class="sub">of {{ $fmtMb($limit) }} allowed</div>
        </div>
        <div class="stat">
            <div class="label">Peak day</div>
            <div class="value">{{ $fmtMb($peak) }}</div>
            <div class="sub">highest single day</div>
        </div>
        <div class="stat">
            <div class="label">Days recorded</div>
            <div class="value">{{ $days->count() }}</div>
            <div class="sub">history retained</div>
        </div>
        <div class="stat">
            <div class="label">Average</div>
            <div class="value">{{ $fmtMb($days->count() ? (int) round($total / $days->count()) : 0) }}</div>
            <div class="sub">per recorded day</div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">Daily total</h2>

        @if ($days->isEmpty())
            <div class="empty"><h3>No traffic yet</h3><p>This chart fills in once the site serves requests.</p></div>
        @else
            <div class="chart" style="height:190px" role="img" aria-label="Daily bandwidth chart">
                @foreach ($days as $day)
                    @php $mb = $day->totalMb(); @endphp
                    <div class="bar" style="height: {{ max(2, round($mb / $peak * 100)) }}%"
                         data-tip="{{ $day->day->format('j M') }}: {{ $fmtMb($mb) }}"></div>
                @endforeach
            </div>
            <div class="chart-axis">
                <span>{{ $days->first()->day->format('j M Y') }}</span>
                <span>{{ $days->last()->day->format('j M Y') }}</span>
            </div>
        @endif
    </div>

    <div class="card">
        <h2 style="margin-top:0">Breakdown</h2>

        @if ($days->isNotEmpty())
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Day</th><th>HTTP</th><th>Mail</th><th>FTP</th><th>Total</th></tr></thead>
                    <tbody>
                        @foreach ($days->reverse() as $day)
                            <tr>
                                <td class="small">{{ $day->day->format('D j M Y') }}</td>
                                <td class="small faint">{{ $fmtMb($day->http_mb) }}</td>
                                <td class="small faint">{{ $fmtMb($day->mail_mb) }}</td>
                                <td class="small faint">{{ $fmtMb($day->ftp_mb) }}</td>
                                <td class="small"><strong>{{ $fmtMb($day->totalMb()) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
