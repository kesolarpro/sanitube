<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime, duration } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { GenerationDetail } from '@/Types/studio';

/**
 * One generation: what was asked for, and what came back.
 *
 * **No audio URL reaches this page.** A provider's download reference is
 * routinely a time-limited signed URL — a bearer credential — and republishing
 * it would hand out access SaniTube never checked. A result that has been
 * imported links to its asset, where the preview rules UI-003d settled apply.
 *
 * The rights answer is shown on its own rather than beside the status, because
 * "the audio arrived" and "the audio may be sold" are different questions and
 * only one of them has been answered.
 */
defineProps<{ generation: GenerationDetail }>();

const locale = usePage<SharedProps>().props.app.locale;
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-caption text-muted">
                    <Link href="/studio/generations" class="hover:text-accent">
                        {{ trans('ui.studio.generations') }}
                    </Link>
                </p>
                <h1 class="text-page-title text-foreground">{{ trans('ui.studio.generation') }}</h1>
                <p class="mt-1 text-small text-muted">
                    {{ trans('ui.studio.provider') }}: {{ generation.provider }}
                    <template v-if="generation.model !== null"> · {{ generation.model }}</template>
                </p>
            </div>
            <StatusBadge :status="generation.status" group="generic" />
        </div>

        <AppAlert
            v-if="generation.commercial_rights_status === 'UNKNOWN'"
            tone="warning"
            :title="trans('ui.studio.rights_unknown_warning')"
        >
            {{ trans('ui.studio.commercial_rights_note') }}
        </AppAlert>

        <AppAlert v-if="generation.failure_reason !== null" tone="danger" :title="trans('ui.studio.failure_reason')">
            {{ generation.failure_reason }}
        </AppAlert>

        <AppCard>
            <template #header>{{ trans('ui.studio.inputs') }}</template>
            <dl class="grid gap-3">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.prompt') }}</dt>
                    <dd class="text-small whitespace-pre-line text-foreground">{{ generation.prompt }}</dd>
                </div>
                <div v-if="generation.style_prompt !== null">
                    <dt class="text-caption text-muted">{{ trans('ui.studio.style_prompt') }}</dt>
                    <dd class="text-small text-foreground">{{ generation.style_prompt }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.lyrics') }}</dt>
                    <dd class="text-small whitespace-pre-line text-foreground">
                        <span v-if="generation.instrumental" class="text-muted">{{ trans('ui.studio.instrumental') }}</span>
                        <span v-else-if="generation.lyrics === null" class="text-muted">
                            {{ trans('ui.studio.no_lyrics') }}
                        </span>
                        <template v-else>{{ generation.lyrics }}</template>
                    </dd>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.language') }}</dt>
                        <dd class="text-small text-foreground">{{ generation.language_code }}</dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.studio.commercial_rights') }}</dt>
                        <dd class="text-small text-foreground">
                            {{ trans(`ui.status.generic.${generation.commercial_rights_status}`) }}
                        </dd>
                    </div>
                    <div v-if="generation.project !== null">
                        <dt class="text-caption text-muted">{{ trans('ui.studio.project') }}</dt>
                        <dd class="text-small">
                            <Link :href="`/studio/projects/${generation.project.uuid}`" class="hover:text-accent">
                                {{ generation.project.name }}
                            </Link>
                        </dd>
                    </div>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.studio.polling') }}</template>
            <dl class="grid gap-3 sm:grid-cols-4">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.submitted_at') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ generation.submitted_at === null ? trans('ui.studio.never_submitted') : dateTime(generation.submitted_at, locale) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.completed_at') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ generation.completed_at === null ? trans('ui.studio.still_running') : dateTime(generation.completed_at, locale) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.last_polled_at') }}</dt>
                    <dd class="text-small text-foreground">{{ dateTime(generation.last_polled_at, locale) }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.polling') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ trans('ui.studio.polled_times', { count: generation.poll_count }) }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.studio.results') }}</template>

            <div v-if="generation.results.length === 0" class="p-4">
                <p class="text-small text-muted">{{ trans('ui.studio.no_results') }}</p>
                <p class="mt-1 text-caption text-muted">{{ trans('ui.studio.no_results_note') }}</p>
            </div>

            <DataTable v-else :caption="trans('ui.studio.results')">
                <template #head>
                    <TableHeaderCell>{{ trans('ui.catalog.column.title') }}</TableHeaderCell>
                    <TableHeaderCell align="right">{{ trans('ui.catalog.column.duration') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.studio.has_audio') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.studio.imported_as') }}</TableHeaderCell>
                </template>
                <tr v-for="result in generation.results" :key="result.uuid" class="border-t border-border">
                    <TableCell>
                        {{ result.title ?? '—' }}
                        <span v-if="result.selected" class="ml-2 text-caption text-accent">
                            {{ trans('ui.studio.selected') }}
                        </span>
                    </TableCell>
                    <TableCell align="right" numeric>{{ duration(result.duration_ms) }}</TableCell>
                    <TableCell>
                        <!-- Whether there is something to import, never where from. -->
                        <span :class="result.has_audio ? '' : 'text-muted'">
                            {{ result.has_audio ? trans('ui.studio.has_audio') : trans('ui.studio.no_audio') }}
                        </span>
                    </TableCell>
                    <TableCell>
                        <Link
                            v-if="result.candidate_uuid !== null"
                            :href="`/ingestion/candidates/${result.candidate_uuid}`"
                            class="text-small hover:text-accent"
                        >
                            {{ trans('ui.studio.awaiting_review') }}
                        </Link>
                        <Link
                            v-else-if="result.asset_uuid !== null"
                            :href="`/catalog/assets/${result.asset_uuid}`"
                            class="text-small hover:text-accent"
                        >
                            {{ trans('ui.catalog.assets') }}
                        </Link>
                        <span v-else class="text-muted">{{ trans('ui.studio.not_imported') }}</span>
                    </TableCell>
                </tr>
            </DataTable>
        </AppCard>
    </div>
</template>
