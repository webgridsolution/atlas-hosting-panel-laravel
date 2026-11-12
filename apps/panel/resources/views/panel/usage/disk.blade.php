@extends('layouts.app')

@section('title', 'Disk usage')

@section('content')
    @php
        $fmtMb = fn (int $mb): string => $mb >= 1024 ? number_format($mb / 1024, 2).' GB' : number_format($mb).' MB';
        $largest = collect($children)->max('size_mb') ?: 1;
    @endphp

    <h1>Disk usage</h1>
    <p class="small muted">Measured by the agent walking your home directory. It reflects the real filesystem, not an estimate.</p>

    <div class="grid three" style="margin-bottom:16px">
        <div class="stat">
            <div class="label">Used</div>
            <div class="value">{{ $fmtMb($metric['disk_used_mb']) }}</div>
            <div class="meter {{ $metric['disk_pct'] > 90 ? 'danger' : ($metric['disk_pct'] > 75 ? 'warn' : '') }}" style="margin:8px 0">
                <span style="width: {{ min(100, $metric['disk_pct']) }}%"></span>
            </div>
            <div class="sub">{{ number_format($metric['disk_pct']) }}% of {{ $fmtMb($metric['disk_limit_mb']) }}</div>
        </div>
        <div class="stat">
            <div class="label">Inodes</div>
            <div class="value">{{ number_format($metric['inodes_used']) }}</div>
            <div class="sub">of {{ number_format($metric['inodes_limit']) }} files allowed</div>
        </div>
        <div class="stat">
            <div class="label">Home</div>
            <div class="value mono" style="font-size:15px">{{ $account->home() }}</div>
            <div class="sub">measured path</div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Largest items</h2>

        @if (empty($children))
            <div class="empty"><h3>Nothing measured</h3><p>The agent could not read the directory.</p></div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Type</th><th style="width:45%">Size</th></tr></thead>
                    <tbody>
                        @foreach ($children as $child)
                            <tr>
                                <td class="small mono">{{ $child['name'] }}</td>
                                <td><span class="badge">{{ $child['type'] }}</span></td>
                                <td>
                                    <div class="row" style="gap:10px">
                                        <div class="meter" style="flex:1">
                                            <span style="width: {{ max(1, round(($child['size_mb'] ?? 0) / $largest * 100)) }}%"></span>
                                        </div>
                                        <span class="small faint" style="min-width:80px; text-align:right">
                                            {{ $fmtMb((int) ($child['size_mb'] ?? 0)) }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
