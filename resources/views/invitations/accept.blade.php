<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('nightguardian.invitation.title') }} · NightGuardian</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f4f8; --card: #fff; --fg: #1d1d27; --muted: #6b6b7b; --line: #d9d9e3; --accent: #4f46e5; --danger: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f0f17; --card: #181824; --fg: #ececf3; --muted: #9a9aae; --line: #2c2c3d; --accent: #818cf8; --danger: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1rem; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 26rem; background: var(--card); border: 1px solid var(--line); border-radius: .75rem; padding: 1.75rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .25rem; }
        p { color: var(--muted); margin: 0 0 1.25rem; }
        label { display: block; font-size: .875rem; font-weight: 600; margin: 1rem 0 .25rem; }
        input { width: 100%; padding: .55rem .7rem; border: 1px solid var(--line); border-radius: .5rem; background: transparent; color: inherit; font: inherit; }
        input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        button { margin-top: 1.5rem; width: 100%; padding: .65rem; border: 0; border-radius: .5rem; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .error { color: var(--danger); font-size: .875rem; margin-top: .25rem; }
        .moon { font-size: 1.5rem; }
    </style>
</head>
<body>
<main>
    <div class="moon" aria-hidden="true">🌙</div>
    @if ($invitation === null)
        <h1>{{ __('nightguardian.invitation.unavailable_title') }}</h1>
        <p>{{ __('nightguardian.invitation.unavailable') }}</p>
    @else
        <h1>{{ __('nightguardian.invitation.title') }}</h1>
        <p>
            @if ($invitation->project)
                {{ __('nightguardian.invitation.to_project', ['project' => $invitation->project->name, 'role' => __('nightguardian.roles.'.$invitation->role?->value)]) }}
            @else
                {{ __('nightguardian.invitation.to_instance') }}
            @endif
        </p>
        <form method="post" action="{{ $action }}">
            @csrf
            <label for="name">{{ __('nightguardian.invitation.name') }}</label>
            <input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
            @error('name') <div class="error">{{ $message }}</div> @enderror

            <label for="email">{{ __('nightguardian.invitation.email') }}</label>
            <input id="email" name="email" type="email" value="{{ $invitation->email ?? old('email') }}" @if ($invitation->email) readonly @else required @endif autocomplete="email">
            @error('email') <div class="error">{{ $message }}</div> @enderror

            <label for="password">{{ __('nightguardian.invitation.password') }}</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label for="password_confirmation">{{ __('nightguardian.invitation.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">

            <button type="submit">{{ __('nightguardian.invitation.submit') }}</button>
        </form>
    @endif
</main>
</body>
</html>
