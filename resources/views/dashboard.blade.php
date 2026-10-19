@extends('layouts.app')

@section('title', $snapshot['pool']['name'].' · '.config('app.name'))

@section('content')
    <div x-data="poolDashboard">
        <header class="page__header">
            <h1 class="page__title" id="pool-name">{{ $snapshot['pool']['name'] }}</h1>

            <div class="page__tools">
                <div class="field__row">
                    <label class="field__label" for="user-picker">Acting as</label>
                    <select class="select" id="user-picker" x-model.number="userId">
                        <template x-for="user in users" x-bind:key="user.id">
                            <option x-bind:value="user.id" x-text="optionLabel(user)"></option>
                        </template>
                    </select>
                </div>

                <p class="indicator indicator--reconnecting" id="connection-indicator" role="status" x-bind:class="{ 'indicator--live': connectionState === 'live', 'indicator--reconnecting': connectionState === 'reconnecting', 'indicator--offline': connectionState === 'offline' }" x-text="indicatorText">Reconnecting…</p>
            </div>
        </header>

        {{-- Supervisors get two views; operators only the pool. --}}
        <nav class="tabs" id="view-tabs" aria-label="Views" x-show="isSupervisor" x-cloak>
            <button class="button button--secondary" type="button" id="tab-pool" x-on:click="tab = 'pool'" x-bind:aria-pressed="tab === 'pool'">Pool</button>
            <button class="button button--secondary" type="button" id="tab-supervisor" x-on:click="tab = 'supervisor'" x-bind:aria-pressed="tab === 'supervisor'">Supervisor</button>
        </nav>

        <div class="dashboard" id="pool-view" x-show="showPool">
            <div class="dashboard__main">
                <section class="panel" aria-labelledby="pool-heading">
                    <h2 class="panel__title" id="pool-heading">Pool</h2>
                    <p class="remaining__label">Remaining:</p>
                    <p class="remaining__number" id="remaining" aria-live="polite" x-text="remaining">{{ $snapshot['remaining'] }}</p>
                    {{-- Only the viewer's own status is ever shown, as words (FR4.3). --}}
                    <p class="status" id="status" x-show="isOperator" x-cloak>Status: <span class="status__name" x-text="statusText"></span></p>
                </section>

                {{-- Only operators can ask for units; a supervisor's request would be refused (403). --}}
                <section class="panel" id="request-panel" aria-labelledby="request-heading" x-show="isOperator" x-cloak>
                    <h2 class="panel__title" id="request-heading">Request units</h2>

                    <form id="request-form" novalidate x-on:submit.prevent="submit()">
                        <div class="field">
                            <label class="field__label" for="requested">How many?</label>
                            <div class="field__row">
                                <input class="input" id="requested" name="requested" type="number" inputmode="numeric" min="1" step="1" autocomplete="off"
                                    x-model="amount" x-on:input="amountChanged()" x-bind:max="maxAmount" x-bind:aria-invalid="amountMessage !== ''" aria-describedby="requested-hint requested-message">
                                <span class="hint" id="requested-hint" x-text="leftText"></span>
                            </div>
                            <p class="hint hint--warning" id="requested-message" role="alert" x-text="amountMessage"></p>

                            <fieldset class="choice" id="type-choice" aria-describedby="type-hint">
                                <legend class="field__label">Kind</legend>
                                <label class="choice__option" for="type-consumed">
                                    <input class="choice__input" type="radio" id="type-consumed" name="type" value="consumed" x-model="type" checked>
                                    <span>Consumed</span>
                                </label>
                                <label class="choice__option" for="type-temporary">
                                    <input class="choice__input" type="radio" id="type-temporary" name="type" value="temporary" x-model="type">
                                    <span>Temporary</span>
                                </label>
                            </fieldset>
                            <p class="hint" id="type-hint" x-show="type === 'temporary'" x-cloak x-text="windowHint"></p>

                            <p class="hint hint--warning" id="offline-message" x-show="isOffline" x-cloak>You are offline — nothing was sent</p>
                            <button class="button button--block" type="submit" id="request-submit" x-bind:disabled="submitDisabled" x-text="submitLabel">Request</button>
                        </div>
                    </form>

                    <div id="result-banner" role="status">
                        <p class="banner" x-show="banner" x-cloak x-bind:class="banner ? 'banner--' + banner.tone : ''">
                            <span id="result-text" x-text="banner?.text"></span>
                            <button class="button button--secondary" type="button" id="retry" x-show="isUnconfirmed" x-cloak x-on:click="retry()">Retry</button>
                            <span class="banner__note" x-show="banner?.message" x-text="banner?.message"></span>
                        </p>
                    </div>
                </section>

                {{-- The viewer's own temporary units that are still held, and when each returns (FR13). --}}
                <section class="panel" id="holdings" aria-labelledby="holdings-heading" x-show="isOperator" x-cloak>
                    <h2 class="panel__title" id="holdings-heading">Your temporary holdings</h2>
                    <ul class="holdings">
                        <template x-for="holding in holdings" x-bind:key="holding.key">
                            <li class="holdings__row" x-text="holding.units + ' · expires in ' + holding.timeLeft"></li>
                        </template>
                    </ul>
                    <p class="hint" id="holdings-empty" x-show="holdings.length === 0" x-cloak>No temporary holdings</p>
                </section>
            </div>

            <section class="dashboard__log" aria-labelledby="log-heading">
                <h2 class="panel__title" id="log-heading">Activity log</h2>
                <ol class="log" id="log">
                    <template x-for="row in rows" x-bind:key="row.key">
                        <li class="log__row" x-bind:class="{ 'log__row--own': row.own, 'log__row--pending': row.pending }">
                            <span class="log__label" x-text="row.label"></span>
                            <span class="log__text"><span x-text="row.text"></span> <span class="log__marker" x-show="row.own">You</span></span>
                        </li>
                    </template>
                </ol>
                <p class="hint hint--warning" id="older-error" role="alert" x-show="olderError" x-cloak x-text="olderError"></p>
                <button class="button button--secondary button--block" type="button" id="show-older" x-show="state.logHasMore" x-cloak
                    x-bind:disabled="loadingOlder" x-on:click="loadOlder()" x-text="loadingOlder ? 'Loading…' : 'Show older'">Show older</button>
            </section>
        </div>

        {{-- The supervisor's own screen arrives with M3. --}}
        <section class="panel" id="supervisor-view" aria-labelledby="supervisor-heading" x-show="showSupervisor" x-cloak>
            <h2 class="panel__title" id="supervisor-heading">Supervisor</h2>
            <p class="placeholder">Placeholder — arrives in M3</p>
        </section>
    </div>

    <script type="application/json" id="initial-snapshot">{!! \Illuminate\Support\Js::encode($snapshot) !!}</script>
@endsection
