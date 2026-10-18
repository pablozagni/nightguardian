{{-- Filament does not compile Tailwind classes used in custom views, so the event detail carries its own CSS. --}}
<style>
    .ng-event { display: grid; gap: 1.25rem; font-size: .875rem; }
    .ng-event .ng-heading { margin: 0 0 .5rem; font-size: .75rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: rgb(107 114 128); }
    .ng-event .ng-exception { margin: 0; font-size: 1rem; word-break: break-word; }
    .ng-event .ng-message { margin: .25rem 0 1rem; word-break: break-word; }
    .ng-event .ng-muted { color: rgb(107 114 128); }
    .ng-event .ng-frame { border: 1px solid rgb(229 231 235); border-radius: .5rem; margin-bottom: .5rem; overflow: hidden; }
    .ng-event .ng-frame-head { padding: .4rem .7rem; display: flex; flex-wrap: wrap; gap: .25rem 1rem; justify-content: space-between; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem; }
    .ng-event .ng-frame-app { border-color: rgb(99 102 241 / .55); }
    .ng-event .ng-frame-app .ng-frame-head { background: rgb(99 102 241 / .08); font-weight: 600; }
    .ng-event .ng-frame-vendor .ng-frame-head { color: rgb(107 114 128); }
    .ng-event .ng-call { color: rgb(107 114 128); font-weight: 400; word-break: break-all; }
    .ng-event .ng-vendor { margin-bottom: .5rem; }
    .ng-event .ng-vendor > summary { cursor: pointer; padding: .35rem .2rem; color: rgb(107 114 128); }
    .ng-event .ng-code { margin: 0; padding: .5rem 0; overflow-x: auto; background: rgb(17 24 39 / .04); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem; line-height: 1.5; }
    .ng-event .ng-line { display: block; padding: 0 .7rem; white-space: pre; }
    .ng-event .ng-line-current { background: rgb(239 68 68 / .14); }
    .ng-event .ng-no { display: inline-block; width: 3.2rem; padding-right: .8rem; text-align: right; color: rgb(156 163 175); user-select: none; }
    .ng-event .ng-json { margin: 0; padding: .75rem; overflow-x: auto; border-radius: .5rem; background: rgb(17 24 39 / .04); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem; white-space: pre-wrap; word-break: break-word; }
    .ng-event .ng-kv { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: 0; }
    .ng-event .ng-kv dt { color: rgb(107 114 128); }
    .ng-event .ng-kv dd { margin: 0; word-break: break-all; }
    .ng-event .ng-tag { display: inline-block; margin: 0 .35rem .35rem 0; padding: .1rem .5rem; border-radius: 999px; background: rgb(99 102 241 / .12); font-size: .8125rem; }
    .dark .ng-event .ng-frame { border-color: rgb(255 255 255 / .12); }
    .dark .ng-event .ng-frame-app { border-color: rgb(129 140 248 / .6); }
    .dark .ng-event .ng-code, .dark .ng-event .ng-json { background: rgb(255 255 255 / .05); }
    .dark .ng-event .ng-line-current { background: rgb(248 113 113 / .2); }
</style>
