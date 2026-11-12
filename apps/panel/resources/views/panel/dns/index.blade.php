@extends('layouts.app')

@section('title', 'DNS')

@section('content')
    <h1>DNS</h1>
    <p class="small muted">
        Zones are served by PowerDNS. Point your registrar at
        @if ($nameservers)
            {{ implode(' and ', $nameservers) }}
        @else
            this server's nameservers
        @endif
        for these records to take effect.
    </p>

    @if ($zones->isEmpty())
        <div class="card">
            <div class="empty"><h3>No zones yet</h3><p>A zone is created with each website you add.</p></div>
        </div>
    @endif

    @foreach ($zones as $zone)
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>{{ $zone->domain }}</h2>
                    <p>{{ $zone->records->count() }} record(s) · status {{ $zone->status }}</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Type</th><th>Content</th><th>TTL</th><th>Priority</th><th class="right"></th></tr></thead>
                    <tbody>
                        @foreach ($zone->records as $record)
                            <tr>
                                <td class="mono small">{{ $record->name }}</td>
                                <td><span class="badge">{{ $record->type }}</span></td>
                                <td class="mono small" style="word-break:break-all">{{ $record->content }}</td>
                                <td class="small faint">{{ $record->ttl }}</td>
                                <td class="small faint">{{ $record->priority ?? '—' }}</td>
                                <td class="right">
                                    <form method="POST" action="{{ route('panel.dns.destroy', $record) }}" class="inline-form"
                                          onsubmit="return confirm('Delete this record?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn sm danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('panel.dns.store') }}" style="margin-top:16px">
                @csrf
                <input type="hidden" name="zone_id" value="{{ $zone->id }}">

                <div class="form-row" style="grid-template-columns: 1fr 110px 2fr 100px 100px auto; align-items:end">
                    <div>
                        <label>Name</label>
                        <input name="name" type="text" placeholder="@ or www" required>
                    </div>
                    <div>
                        <label>Type</label>
                        <select name="type">
                            @foreach (\App\Models\DnsRecord::TYPES as $type)
                                <option value="{{ $type }}">{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Content</label>
                        <input name="content" type="text" placeholder="203.0.113.10" required>
                    </div>
                    <div>
                        <label>TTL</label>
                        <input name="ttl" type="number" min="60" value="3600" required>
                    </div>
                    <div>
                        <label>Priority</label>
                        <input name="priority" type="number" min="0">
                    </div>
                    <button class="btn primary" type="submit">Add record</button>
                </div>
            </form>
        </div>
    @endforeach
@endsection
