{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
@php
    $switchId = (string) ($id ?? $name . '_toggle');
    $switchName = (string) $name;
    $switchLabel = (string) ($label ?? '');
    $switchChecked = (bool) ($checked ?? false);
    $switchExtraOnChange = trim((string) ($extraOnChange ?? ''));

    $switchOnChange = "(function(cb){const fallback=cb.previousElementSibling; if(fallback && fallback.type === 'hidden'){const newVal=cb.checked ? '1' : '0'; fallback.value = newVal; let n=cb.parentElement; while(n){if(n.__vue__){const f=n.__vue__.form; if(f && typeof f==='object' && fallback.name in f){f[fallback.name]=newVal; break;}} n=n.parentElement;}} const track=cb.nextElementSibling; const thumb=track ? track.nextElementSibling : null; if(track){track.style.backgroundColor = cb.checked ? '#5e9f4d' : '#dbe8d4';} if(thumb){thumb.style.left = cb.checked ? '1.5rem' : '0.25rem';}";

    if ($switchExtraOnChange !== '') {
        $switchOnChange .= ' ' . $switchExtraOnChange;
    }

    $switchOnChange .= '})(this)';
@endphp

<label for="{{ $switchId }}" data-nfse-switch class="relative inline-flex h-7 w-12 flex-shrink-0 cursor-pointer items-center" aria-label="{{ $switchLabel }}">
    <input type="hidden" name="{{ $switchName }}" value="{{ $switchChecked ? '1' : '0' }}">
    <input
        id="{{ $switchId }}"
        type="checkbox"
        value="1"
        class="peer sr-only"
        {{ $switchChecked ? 'checked' : '' }}
        onchange="{!! $switchOnChange !!}"
    >
    <div data-toggle="track" class="block h-7 w-12 rounded-full bg-green-200 transition-colors duration-200" style="background-color: {{ $switchChecked ? '#5e9f4d' : '#dbe8d4' }};"></div>
    <div data-toggle="thumb" class="absolute top-1 h-5 w-5 rounded-full bg-white shadow transition-all duration-200" style="left: {{ $switchChecked ? '1.5rem' : '0.25rem' }};"></div>
</label>
