@extends('layouts.app')

@section('title', 'Inbox')

@section('content')
    <div class="spread" style="margin-bottom:16px">
        <div>
            <h1>Inbox</h1>
            <p class="small muted">{{ $address }} · {{ $count }} message(s) in INBOX</p>
        </div>
        <div class="row">
            <a class="btn primary" href="{{ route('webmail.compose') }}"><x-icon name="plus" :size="15" /> Compose</a>
            <form method="POST" action="{{ route('webmail.logout') }}" class="inline-form">
                @csrf
                <button class="btn ghost" type="submit"><x-icon name="logout" :size="15" /> Sign out</button>
            </form>
        </div>
    </div>

    @if (empty($messages))
        <div class="card">
            <div class="empty"><h3>No messages</h3><p>New mail will appear here.</p></div>
        </div>
    @else
        <div class="mail-list">
            @foreach ($messages as $message)
                <a class="mail-row" href="{{ route('webmail.show', $message['uid']) }}" style="color:inherit">
                    <div class="from">{{ $message['from'] ?: 'unknown sender' }}</div>
                    <div class="subject">{{ $message['subject'] }}</div>
                    <div class="date">{{ $message['date'] ? \Illuminate\Support\Str::limit($message['date'], 24, '') : '' }}</div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
