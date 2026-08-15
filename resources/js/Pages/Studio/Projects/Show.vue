<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { GenerationPage, ProjectDetail } from '@/Types/studio';

/** One campaign and the generations inside it. */
const props = defineProps<{ project: ProjectDetail; page: GenerationPage }>();

const locale = usePage<SharedProps>().props.app.locale;

function cursorHref(cursor: string | null): string | null {
    return cursor === null
        ? null
        : `/studio/projects/${props.project.uuid}?cursor=${encodeURIComponent(cursor)}`;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-caption text-muted">
                    <Link href="/studio/projects" class="hover:text-accent">{{ trans('ui.studio.projects') }}</Link>
                </p>
                <h1 class="text-page-title text-foreground">{{ project.name }}</h1>
                <p class="mt-1 text-small text-muted">
                    {{ project.accepts_generations ? trans('ui.studio.accepts_generations') : trans('ui.studio.closed_to_generations') }}
                </p>
            </div>
            <StatusBadge :status="project.status" group="generic" />
        </div>

        <AppCard>
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.target') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ project.target_track_count === null ? trans('ui.studio.no_target') : project.target_track_count }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.default_language') }}</dt>
                    <dd class="text-small text-foreground">{{ project.default_language ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.studio.default_genre') }}</dt>
                    <dd class="text-small text-foreground">{{ project.default_genre ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.created_at') }}</dt>
                    <dd class="text-small text-foreground">{{ dateTime(project.created_at, locale) }}</dd>
                </div>
                <div v-if="project.default_style_prompt !== null" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-caption text-muted">{{ trans('ui.studio.default_style') }}</dt>
                    <dd class="text-small text-foreground">{{ project.default_style_prompt }}</dd>
                </div>
            </dl>
        </AppCard>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.studio.generations') }}</template>
            <EmptyState
                v-if="page.rows.length === 0"
                :title="trans('ui.studio.no_generations')"
                :description="trans('ui.studio.no_generations_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.studio.generations')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.studio.prompt') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.studio.commercial_rights') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.studio.result_count') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/studio/generations/${row.uuid}`" class="hover:text-accent">
                                {{ row.prompt }}
                            </Link>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ trans(`ui.status.generic.${row.commercial_rights_status}`) }}</TableCell>
                        <TableCell align="right" numeric>{{ row.result_count }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
