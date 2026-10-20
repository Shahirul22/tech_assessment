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
                                <button class="button button--secondary stepper__button" type="button" id="amount-decrease" aria-label="Decrease" x-bind:disabled="!canDecrease" x-on:click="step(-1)">−</button>
                                <input class="input" id="requested" name="requested" type="number" inputmode="numeric" min="1" step="1" autocomplete="off"
                                    x-model="amount" x-on:input="amountChanged()" x-bind:max="maxAmount" x-bind:aria-invalid="amountMessage !== ''" aria-describedby="requested-hint requested-message">
                                <button class="button button--secondary stepper__button" type="button" id="amount-increase" aria-label="Increase" x-bind:disabled="!canIncrease" x-on:click="step(1)">+</button>
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

                            <div class="quick-amounts" id="quick-amounts" role="group" aria-label="Quick amounts">
                                <template x-for="item in quickAmountList" x-bind:key="item.label">
                                    <button class="button button--secondary quick-amounts__button" type="button" x-bind:disabled="!item.usable" x-text="item.label" x-on:click="pickAmount(item.value)"></button>
                                </template>
                            </div>

                            <p class="hint hint--warning" id="offline-message" x-show="isOffline" x-cloak>You are offline — nothing was sent</p>
                            <button class="button button--block" type="submit" id="request-submit" x-bind:disabled="submitDisabled" x-text="submitLabel">Request</button>
                        </div>
                    </form>

                    <x-result-banner id="result-banner" text-id="result-text" retry-id="retry" bind="banner" retry="retry()" />
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
                            <span class="log__text">
                                <span x-text="row.text"></span> <span class="log__marker" x-show="row.own">You</span>
                                {{-- Supervisors pick the entry to correct from the log (M3 DD13). --}}
                                <button class="button button--secondary log__action" type="button" x-show="isSupervisor && row.correctable" x-cloak
                                    x-on:click="startCorrection(row)" x-bind:aria-label="'Correct entry #' + row.seq">Correct</button>
                            </span>
                        </li>
                    </template>
                </ol>
                <p class="hint hint--warning" id="older-error" role="alert" x-show="olderError" x-cloak x-text="olderError"></p>
                <button class="button button--secondary button--block" type="button" id="show-older" x-show="state.logHasMore" x-cloak
                    x-bind:disabled="loadingOlder" x-on:click="loadOlder()" x-text="loadingOlder ? 'Loading…' : 'Show older'">Show older</button>
            </section>
        </div>

        {{-- The supervisor's screen: restock, correct or void, and the audit view (FR18). Operators never see it. --}}
        <section class="panel supervisor" id="supervisor-view" aria-labelledby="supervisor-heading" x-show="showSupervisor" x-cloak>
            <h2 class="panel__title" id="supervisor-heading">Supervisor</h2>
            <p class="remaining__label">Remaining: <span id="supervisor-remaining" x-text="remaining">{{ $snapshot['remaining'] }}</span></p>

            <form class="supervisor__form" id="restock-form" novalidate aria-labelledby="restock-heading" x-on:submit.prevent="submitRestock()">
                <h3 class="panel__subtitle" id="restock-heading">Restock</h3>
                <div class="field">
                    <label class="field__label" for="restock-amount">Units to add</label>
                    <input class="input" id="restock-amount" name="amount" type="number" inputmode="numeric" min="1" step="1" autocomplete="off"
                        x-model="restockAmount" x-on:input="restockEdited()" x-bind:aria-invalid="restockMessage !== ''" aria-describedby="restock-message">
                    <label class="field__label" for="restock-note">Note (optional)</label>
                    <input class="input input--wide" id="restock-note" name="note" type="text" maxlength="255" autocomplete="off" x-model="restockNote" x-on:input="restockEdited()">
                    <p class="hint hint--warning" id="restock-message" role="alert" x-text="restockMessage"></p>
                    <p class="hint hint--warning" id="restock-offline" x-show="isOffline" x-cloak>You are offline — nothing was sent</p>
                    <button class="button button--block" type="submit" id="restock-submit" x-bind:disabled="restockDisabled" x-text="restockLabel">Add units</button>
                </div>
                <x-result-banner id="restock-result" text-id="restock-result-text" retry-id="restock-retry" bind="restockBanner" retry="retryRestock()" />
            </form>

            <form class="supervisor__form" id="correction-form" novalidate aria-labelledby="correction-heading" x-on:submit.prevent="submitCorrection()">
                <h3 class="panel__subtitle" id="correction-heading">Correct or void an entry</h3>
                <p class="hint" id="correction-target" x-text="correctionTarget">Pick an entry in the log with its Correct button.</p>
                <div class="field">
                    <div class="field__row">
                        <label class="field__label" for="correction-seq">Entry</label>
                        <input class="input" id="correction-seq" name="allocation_seq" type="text" readonly x-bind:value="correction.seq === null ? '' : '#' + correction.seq">
                        <label class="field__label" for="correction-units">Holds now</label>
                        <input class="input" id="correction-units" name="current_units" type="text" readonly x-bind:value="correction.units === null ? '' : correction.units">
                    </div>

                    <fieldset class="choice" id="correction-kind">
                        <legend class="field__label">Change</legend>
                        <label class="choice__option" for="correction-amend">
                            <input class="choice__input" type="radio" id="correction-amend" name="correction-kind" value="amend" x-model="correctionKind" x-on:change="correctionEdited()" checked>
                            <span>Amend</span>
                        </label>
                        <label class="choice__option" for="correction-void">
                            <input class="choice__input" type="radio" id="correction-void" name="correction-kind" value="void" x-model="correctionKind" x-on:change="correctionEdited()">
                            <span>Void</span>
                        </label>
                    </fieldset>

                    <label class="field__label" for="correction-amount" x-show="correctionKind === 'amend'">New amount</label>
                    <input class="input" id="correction-amount" name="amount" type="number" inputmode="numeric" min="1" step="1" autocomplete="off"
                        x-show="correctionKind === 'amend'" x-model="correctionAmount" x-on:input="correctionEdited()" aria-describedby="correction-message">

                    <label class="field__label" for="correction-reason">Reason</label>
                    <input class="input input--wide" id="correction-reason" name="reason" type="text" maxlength="500" autocomplete="off" x-model="correctionReason" x-on:input="correctionEdited()">

                    <p class="hint hint--warning" id="correction-message" role="alert" x-text="correctionMessage"></p>
                    <p class="hint hint--warning" id="correction-offline" x-show="isOffline" x-cloak>You are offline — nothing was sent</p>
                    <button class="button button--block" type="submit" id="correction-submit" x-bind:disabled="correctionDisabled" x-text="correctionLabel">Correct</button>
                </div>
                <x-result-banner id="correction-result" text-id="correction-result-text" retry-id="correction-retry" bind="correctionBanner" retry="retryCorrection()" />
            </form>

            <section class="supervisor__audit" id="audit" aria-labelledby="audit-heading">
                <h3 class="panel__subtitle" id="audit-heading">Audit</h3>
                <p class="hint hint--warning" id="audit-error" role="alert" x-show="auditError" x-cloak x-text="auditError"></p>
                <ul class="audit__hindsight" id="audit-hindsight">
                    <template x-for="line in audit.hindsight" x-bind:key="line">
                        <li class="audit__flag" x-text="line"></li>
                    </template>
                </ul>
                <p class="hint" id="audit-empty" x-show="audit.empty" x-cloak x-text="audit.emptyText">No corrections yet</p>
                <ol class="audit" id="audit-list">
                    <template x-for="row in audit.rows" x-bind:key="row.seq">
                        <li class="audit__row">
                            <span class="audit__text" x-text="row.text"></span>
                            <ul class="audit__points">
                                <template x-for="point in row.points" x-bind:key="point">
                                    <li x-text="point"></li>
                                </template>
                            </ul>
                        </li>
                    </template>
                </ol>
            </section>
        </section>
    </div>

    <script type="application/json" id="initial-snapshot">{!! \Illuminate\Support\Js::encode($snapshot) !!}</script>
@endsection
