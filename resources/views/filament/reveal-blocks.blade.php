{{-- Shows secrets exactly once, each in a block with a copy button. $blocks: list of [label, value, multiline?] --}}
@php /** @var list<array{label: string, value: string, rows?: int}> $blocks */ @endphp
<div style="display:grid;gap:1rem;font-size:.875rem">
    @isset($warning)
        <p style="margin:0;padding:.6rem .8rem;border-radius:.5rem;background:rgb(245 158 11 / .12);color:rgb(180 83 9)">{{ $warning }}</p>
    @endisset

    @foreach ($blocks as $block)
        <div x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(this.$refs.value.textContent); this.copied = true; setTimeout(() => this.copied = false, 2000) } catch (e) {} } }">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.25rem">
                <strong>{{ $block['label'] }}</strong>
                <button type="button" x-on:click="copy()" style="border:1px solid rgb(156 163 175 / .5);border-radius:.375rem;padding:.15rem .6rem;font-size:.8125rem;cursor:pointer;background:transparent;color:inherit">
                    <span x-show="!copied">{{ __('nightguardian.reveal.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('nightguardian.reveal.copied') }}</span>
                </button>
            </div>
            <pre x-ref="value" data-reveal="{{ $block['key'] ?? '' }}" style="margin:0;padding:.7rem .8rem;border-radius:.5rem;background:rgb(17 24 39 / .06);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.8125rem;white-space:pre-wrap;word-break:break-all;user-select:all">{{ $block['value'] }}</pre>
        </div>
    @endforeach
</div>
