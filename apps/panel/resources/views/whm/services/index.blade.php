@extends('layouts.app')

@section('title', 'Services')

@section('content')
    <h1>Services</h1>
    <p class="small muted">Only the units listed here can be restarted from the panel — the agent refuses anything else.</p>

    <div class="card">
        @if ($states->isEmpty())
            <div class="alert warn"><x-icon name="alert" :size="16" /><div>The agent did not report service states. Start <span class="mono">atlas-agent</span> to see them.</div></div>
        @endif

        <div class="table-wrap">
            <table>
                <thead><tr><th>Unit</th><th>What it does</th><th>State</th><th class="right">Action</th></tr></thead>
                <tbody>
                    @foreach ($units as $unit => $label)
                        @php($state = $states->get($unit))
                        <tr>
                            <td class="mono">{{ $unit }}</td>
                            <td class="small">{{ $label }}</td>
                            <td>
                                @if ($state === null)
                                    <span class="badge">not reported</span>
                                @elseif ($state['active'])
                                    <span class="badge ok">{{ $state['state'] ?: 'active' }}</span>
                                @else
                                    <span class="badge danger">{{ $state['state'] ?: 'inactive' }}</span>
                                @endif
                            </td>
                            <td class="right">
                                <form method="POST" action="{{ route('whm.services.restart', $unit) }}" class="inline-form"
                                      onsubmit="return confirm('Restart {{ $unit }}? Websites on it will briefly return errors.')">
                                    @csrf
                                    <button class="btn sm" type="submit"><x-icon name="refresh" :size="14" /> Restart</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
