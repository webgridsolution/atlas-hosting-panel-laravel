@extends('layouts.app')

@section('title', 'No account')

@section('content')
    <div class="card" style="max-width:620px">
        <h1>No hosting account yet</h1>
        <p>Your login exists, but no hosting account is attached to it.</p>
        <p class="small muted">This usually means provisioning did not finish. Your provider can attach the account from the WHM console.</p>
        <a class="btn" href="{{ route('profile.edit') }}">Your profile</a>
    </div>
@endsection
