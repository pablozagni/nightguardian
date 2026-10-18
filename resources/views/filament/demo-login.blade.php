<div style="margin-bottom:1.25rem;padding:.9rem 1rem;border-radius:.6rem;background:rgb(245 158 11 / .12);font-size:.875rem">
    <strong>{{ __('nightguardian.demo.login_title') }}</strong>
    <p style="margin:.35rem 0 0">{{ __('nightguardian.demo.login_help') }}</p>
    <dl style="margin:.6rem 0 0;display:grid;grid-template-columns:max-content 1fr;gap:.2rem .9rem">
        <dt>{{ __('nightguardian.demo.email') }}</dt><dd style="margin:0;font-family:ui-monospace,Menlo,Consolas,monospace">{{ \App\Demo\DemoMode::visitorEmail() }}</dd>
        <dt>{{ __('nightguardian.demo.password') }}</dt><dd style="margin:0;font-family:ui-monospace,Menlo,Consolas,monospace">{{ \App\Demo\DemoMode::visitorPassword() }}</dd>
    </dl>
</div>
