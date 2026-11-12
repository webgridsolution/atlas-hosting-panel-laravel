@extends('layouts.app')

@section('title', 'Message')

@section('content')
    <div class="spread" style="margin-bottom:16px">
        <div>
            <h1 style="font-size:1.2rem">{{ \Illuminate\Support\Str::limit($message['headers'], 0) ?: 'Message' }}</h1>
            <p class="small muted">{{ $address }}</p>
        </div>
        <a class="btn ghost" href="{{ route('webmail.inbox') }}"><x-icon name="arrow-left" :size="15" /> Back to inbox</a>
    </div>

    <div class="card">
        <pre class="small" style="white-space:pre-wrap;margin-bottom:16px">{{ $message['headers'] }}</pre>
        <div class="mail-body">{{ $message['body'] }}</div>
    </div>

    <p class="small faint" style="margin-top:14px">
        Bodies are shown as text with HTML escaped. Attachments and rich formatting are not rendered.
    </p>
@endsection
