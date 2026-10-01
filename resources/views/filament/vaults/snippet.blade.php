<div x-data="{ copied: false }" style="position: relative;">
    <pre style="margin: 0; overflow-x: auto; border-radius: 0.5rem; background: #0b1020; color: #e5e7eb; padding: 0.75rem 4.5rem 0.75rem 0.75rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem; line-height: 1.6;"><code>{{ $snippet }}</code></pre>
    <button
        type="button"
        x-on:click="navigator.clipboard.writeText(@js($snippet)); copied = true; setTimeout(() => copied = false, 1500)"
        x-text="copied ? 'Copied' : 'Copy'"
        style="position: absolute; top: 0.5rem; right: 0.5rem; border-radius: 0.375rem; background: rgba(255,255,255,0.12); color: #e5e7eb; padding: 0.25rem 0.5rem; font-size: 0.75rem; cursor: pointer;"
    >Copy</button>
</div>
