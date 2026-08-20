<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime, duration } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { SuggestionEdit, SuggestionFilters, SuggestionPage, SuggestionRow } from '@/Types/enrichment';

/**
 * The enrichment review queue.
 *
 * **Four columns, four levels, labelled.** What the catalogue says, what the
 * platform measured, what a specialised model proposed, and what a language
 * model suggested. A reviewer who cannot tell those apart will accept a guess
 * believing it was observed, and the whole of ADR-0016 rests on them being able
 * to.
 *
 * The levels arrive as four separate objects from the server. Nothing here
 * re-derives which is which, and nothing here fills an empty canonical column
 * from the suggestion — a blank one is the honest answer to "what does the
 * catalogue say" when the answer is "nothing yet".
 *
 * **The fields are editable before accepting, and that is the ordinary case.**
 * A model's title is right about half the time and nearly right rather often,
 * and a queue offering only accept-or-reject trains people to accept
 * nearly-right.
 *
 * **Regenerate is not reject.** A rejection is a person saying the model was
 * wrong and it is counted; asking for a better answer is not a verdict, and the
 * two are separate buttons because they are separate acts.
 */
const props = defineProps<{
    page: SuggestionPage;
    filters: SuggestionFilters;
    options: Record<string, string[]>;
}>();

const shared = usePage<SharedProps>().props;
const locale = shared.app.locale;

/**
 * Presentation only. The route middleware is the authorisation — a hidden
 * button is not a permission check.
 */
const mayDecide = computed(() => shared.auth.user?.can.catalogue === true);

const status = ref(props.filters.status ?? '');

watch(
    () => props.filters,
    (filters) => {
        status.value = filters.status ?? '';
    },
);

/**
 * The reviewer's edits, per row, seeded from what the model said.
 *
 * Kept out of the row objects so the model's answer stays visible beside the
 * edit — a screen that overwrote the suggestion as you typed would lose the
 * only thing you were comparing against.
 */
const edits = reactive<Record<string, SuggestionEdit>>({});

function editFor(row: SuggestionRow): SuggestionEdit {
    const existing = edits[row.uuid];

    if (existing !== undefined) {
        return existing;
    }

    const seeded: SuggestionEdit = {
        title: row.suggested.title ?? '',
        language_code: row.suggested.language ?? '',
        genres: row.suggested.genres.join(', '),
    };

    edits[row.uuid] = seeded;

    return seeded;
}

function currentQuery(): Record<string, string> {
    return status.value === '' ? {} : { status: status.value };
}

function apply(): void {
    router.get('/enrichment/suggestions', currentQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null
        ? null
        : `/enrichment/suggestions?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.enrichment.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({
        value,
        label: trans(`ui.enrichment.status.${value}`),
    })),
]);

function accept(row: SuggestionRow): void {
    const edit = editFor(row);

    router.post(
        `/enrichment/suggestions/${row.uuid}/accept`,
        {
            title: edit.title,
            language_code: edit.language_code,
            genres: edit.genres
                .split(',')
                .map((genre) => genre.trim())
                .filter((genre) => genre !== ''),
        },
        { preserveScroll: true },
    );
}

function reject(row: SuggestionRow): void {
    router.post(`/enrichment/suggestions/${row.uuid}/reject`, {}, { preserveScroll: true });
}

function regenerate(row: SuggestionRow): void {
    if (row.asset_uuid === null) {
        return;
    }

    router.post(`/assets/${row.asset_uuid}/enrichment/regenerate`, {}, { preserveScroll: true });
}

/**
 * The model's opinion of its own answer. Shown as a number and never used to
 * decide anything: it is more output, not evidence about the output.
 */
function confidence(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(0)}%`;
}

function list(values: string[]): string {
    return values.length === 0 ? '—' : values.join(', ');
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.enrichment.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.enrichment.description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-3" @submit.prevent="apply">
                <FormField :label="trans('ui.enrichment.column.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <div class="sm:col-span-3">
                    <button
                        type="submit"
                        class="rounded-control bg-accent px-3 py-2 text-small font-medium text-accent-foreground hover:bg-accent-hover"
                    >
                        {{ trans('ui.catalog.apply') }}
                    </button>
                </div>
            </form>
        </AppCard>

        <EmptyState
            v-if="page.rows.length === 0"
            :title="trans('ui.enrichment.empty')"
            :description="trans('ui.enrichment.empty_description')"
        />

        <template v-else>
            <AppCard v-for="row in page.rows" :key="row.uuid" :padded="false">
                <template #header>
                    <div class="flex flex-wrap items-center gap-3">
                        <StatusBadge :status="row.status" group="generic" />
                        <span class="text-small text-muted">
                            {{ trans('ui.enrichment.produced_by', { provider: row.provider, model: row.model ?? '—' }) }}
                        </span>
                        <CodeValue :value="row.prompt_version" />
                        <span v-if="!row.evidence_is_current" class="text-small text-warning">
                            {{ trans('ui.enrichment.evidence_stale') }}
                        </span>
                    </div>
                </template>

                <div class="grid gap-4 p-4 lg:grid-cols-4">
                    <!--
                        Canonical. What the catalogue says, and blank when it
                        says nothing — never filled in from the suggestion.
                    -->
                    <section class="space-y-2 rounded-control border border-border p-3">
                        <h2 class="text-small font-medium text-foreground">
                            {{ trans('ui.enrichment.level.canonical') }}
                        </h2>
                        <p class="text-caption text-muted">{{ trans('ui.enrichment.level.canonical_note') }}</p>

                        <dl v-if="row.canonical" class="grid grid-cols-2 gap-1 text-small text-muted">
                            <dt>{{ trans('ui.enrichment.field.title') }}</dt>
                            <dd class="text-foreground">{{ row.canonical.title }}</dd>
                            <dt>{{ trans('ui.enrichment.field.language') }}</dt>
                            <dd class="text-foreground">{{ row.canonical.language_code }}</dd>
                            <dt>{{ trans('ui.enrichment.field.genres') }}</dt>
                            <dd class="text-foreground">
                                {{ list([row.canonical.genre_primary, row.canonical.genre_secondary].filter((g): g is string => g !== null)) }}
                            </dd>
                        </dl>
                        <p v-else class="text-small text-muted">{{ trans('ui.enrichment.no_track') }}</p>
                    </section>

                    <!-- Measured. Facts, from the file. -->
                    <section class="space-y-2 rounded-control border border-border p-3">
                        <h2 class="text-small font-medium text-foreground">
                            {{ trans('ui.enrichment.level.measured') }}
                        </h2>
                        <p class="text-caption text-muted">{{ trans('ui.enrichment.level.measured_note') }}</p>

                        <dl v-if="row.measured" class="grid grid-cols-2 gap-1 text-small text-muted">
                            <dt>{{ trans('ui.enrichment.field.duration') }}</dt>
                            <dd class="text-foreground">{{ duration(row.measured.duration_ms) }}</dd>
                            <dt>{{ trans('ui.enrichment.field.codec') }}</dt>
                            <dd class="text-foreground">{{ row.measured.codec ?? '—' }}</dd>
                        </dl>
                        <p v-else class="text-small text-muted">{{ trans('ui.enrichment.not_analysed') }}</p>
                    </section>

                    <!-- Proposed. A specialised model heard the audio. -->
                    <section class="space-y-2 rounded-control border border-border p-3">
                        <h2 class="text-small font-medium text-foreground">
                            {{ trans('ui.enrichment.level.proposed') }}
                        </h2>
                        <p class="text-caption text-muted">{{ trans('ui.enrichment.level.proposed_note') }}</p>

                        <dl v-if="row.proposed" class="grid grid-cols-2 gap-1 text-small text-muted">
                            <dt>{{ trans('ui.enrichment.field.language') }}</dt>
                            <dd class="text-foreground">{{ row.proposed.language ?? '—' }}</dd>
                            <dt>{{ trans('ui.enrichment.field.covered') }}</dt>
                            <dd class="text-foreground">
                                {{ row.proposed.covered_seconds === null ? '—' : `${row.proposed.covered_seconds}s` }}
                            </dd>
                        </dl>
                        <p v-else class="text-small text-muted">{{ trans('ui.enrichment.no_transcript') }}</p>

                        <p v-if="row.proposed?.contradicts_the_hint" class="text-caption text-warning">
                            {{ trans('ui.enrichment.contradicts_hint') }}
                        </p>
                    </section>

                    <!-- Suggested. The weakest level, and the editable one. -->
                    <section class="space-y-2 rounded-control border border-accent p-3">
                        <h2 class="text-small font-medium text-foreground">
                            {{ trans('ui.enrichment.level.suggested') }}
                        </h2>
                        <p class="text-caption text-muted">{{ trans('ui.enrichment.level.suggested_note') }}</p>

                        <FormField :label="trans('ui.enrichment.field.title')">
                            <input
                                v-model="editFor(row).title"
                                type="text"
                                :disabled="!mayDecide || !row.actions.decidable"
                                class="w-full rounded-control border border-border bg-surface px-2 py-1 text-small text-foreground"
                            />
                        </FormField>

                        <FormField :label="trans('ui.enrichment.field.language')">
                            <input
                                v-model="editFor(row).language_code"
                                type="text"
                                :disabled="!mayDecide || !row.actions.decidable"
                                class="w-full rounded-control border border-border bg-surface px-2 py-1 text-small text-foreground"
                            />
                        </FormField>

                        <FormField :label="trans('ui.enrichment.field.genres')">
                            <input
                                v-model="editFor(row).genres"
                                type="text"
                                :disabled="!mayDecide || !row.actions.decidable"
                                class="w-full rounded-control border border-border bg-surface px-2 py-1 text-small text-foreground"
                            />
                        </FormField>

                        <dl class="grid grid-cols-2 gap-1 text-small text-muted">
                            <dt>{{ trans('ui.enrichment.field.moods') }}</dt>
                            <dd class="text-foreground">{{ list(row.suggested.moods) }}</dd>
                            <dt>{{ trans('ui.enrichment.field.themes') }}</dt>
                            <dd class="text-foreground">{{ list(row.suggested.themes) }}</dd>
                            <dt>{{ trans('ui.enrichment.field.confidence') }}</dt>
                            <dd class="text-foreground">{{ confidence(row.confidence) }}</dd>
                        </dl>
                    </section>
                </div>

                <div v-if="row.suggested.description || row.suggested.rationale" class="space-y-2 border-t border-border p-4">
                    <p v-if="row.suggested.description" class="text-small text-foreground">
                        {{ row.suggested.description }}
                    </p>
                    <p v-if="row.suggested.rationale" class="text-caption text-muted">
                        {{ trans('ui.enrichment.field.rationale') }}: {{ row.suggested.rationale }}
                    </p>
                </div>

                <template #footer>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span class="text-caption text-muted">
                            {{ dateTime(row.suggested_at, locale) }}
                        </span>

                        <div v-if="mayDecide" class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                :disabled="!row.actions.decidable || !row.actions.has_track"
                                :title="row.actions.has_track ? undefined : trans('ui.enrichment.no_track')"
                                class="rounded-control bg-accent px-3 py-2 text-small font-medium text-accent-foreground hover:bg-accent-hover disabled:opacity-50"
                                @click="accept(row)"
                            >
                                {{ trans('ui.enrichment.action.accept') }}
                            </button>
                            <button
                                type="button"
                                :disabled="!row.actions.decidable"
                                class="rounded-control border border-border px-3 py-2 text-small font-medium text-foreground hover:bg-surface-hover disabled:opacity-50"
                                @click="reject(row)"
                            >
                                {{ trans('ui.enrichment.action.reject') }}
                            </button>
                            <button
                                type="button"
                                :disabled="row.asset_uuid === null"
                                class="rounded-control border border-border px-3 py-2 text-small font-medium text-foreground hover:bg-surface-hover disabled:opacity-50"
                                @click="regenerate(row)"
                            >
                                {{ trans('ui.enrichment.action.regenerate') }}
                            </button>
                        </div>
                    </div>
                </template>
            </AppCard>

            <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
        </template>
    </div>
</template>
