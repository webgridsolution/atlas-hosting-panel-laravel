@if (session('status'))
    <div class="flash-stack">
        <x-alert variant="ok" data-flash>{{ session('status') }}</x-alert>
    </div>
@endif

@if ($errors->any())
    <div class="flash-stack">
        <x-alert variant="danger" title="Please fix the following" data-flash>
            {{ $errors->count() === 1 ? $errors->first() : $errors->count().' fields need attention.' }}
        </x-alert>
    </div>
@endif
