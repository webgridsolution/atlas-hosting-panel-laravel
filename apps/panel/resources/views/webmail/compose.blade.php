@extends('layouts.app')

@section('title', 'Compose')

@section('content')
    <h1>Compose</h1>
    <p class="small muted">Sent through the local mail server as {{ $address }}.</p>

    <form method="POST" action="{{ route('webmail.send') }}" class="card">
        @csrf

        <div class="form-row cols">
            <div>
                <label for="to">To</label>
                <input id="to" name="to" type="email" value="{{ old('to') }}" required>
            </div>
            <div>
                <label for="subject">Subject</label>
                <input id="subject" name="subject" type="text" value="{{ old('subject') }}" required>
            </div>
        </div>

        <div class="form-row">
            <div>
                <label for="body">Message</label>
                <textarea id="body" name="body" style="min-height:320px;font-family:var(--sans);font-size:.92rem">{{ old('body') }}</textarea>
            </div>
        </div>

        <div class="row">
            <button class="btn primary" type="submit">Send message</button>
            <a class="btn ghost" href="{{ route('webmail.inbox') }}">Cancel</a>
        </div>
    </form>
@endsection
