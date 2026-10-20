{{--
    One result banner (request, restock, correction): the words, a shape slot and a Retry button, all bound to a
    bannerFor() result held in an Alpine property (resources/js/banner-model.js). The shape is drawn in CSS and is
    aria-hidden; the words carry the meaning, and the wrapper is announced as a status.
--}}
@props(['id', 'textId', 'retryId', 'bind', 'retry'])

<div id="{{ $id }}" role="status">
    <p class="banner" x-show="{{ $bind }}" x-cloak x-bind:class="{{ $bind }} ? 'banner--' + {{ $bind }}.kind : ''">
        <span class="banner__shape" aria-hidden="true" x-bind:class="{{ $bind }} ? 'banner__shape--' + {{ $bind }}.shape : ''"></span>
        <span class="banner__body">
            <span class="banner__text" id="{{ $textId }}" x-text="{{ $bind }}?.text"></span>
            <span class="banner__note" x-show="{{ $bind }}?.note" x-text="{{ $bind }}?.note"></span>
        </span>
        <button class="button button--secondary banner__retry" type="button" id="{{ $retryId }}" x-show="{{ $bind }}?.action === 'retry'" x-cloak x-on:click="{{ $retry }}">Retry</button>
    </p>
</div>
