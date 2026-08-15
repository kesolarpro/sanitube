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
import type { ProjectPage } from '@/Types/studio';

/**
 * Generation projects — campaigns.
 *
 * `target_track_count` is nullable and renders as "no target set" rather than
 * as 0. A project with no target has none; 0 would say somebody set a target
 * of nothing.
 */
defineProps<{ page: ProjectPage }>();

const locale = usePage<SharedProps>().props.app.locale;

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/studio/projects?cursor=${encodeURIComponent(cursor)}`;
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.studio.projects') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.studio.projects_description') }}</p>
        </div>

        <AppCard :padded="false">
            <EmptyState
                v-if="page.rows.length === 0"
                :title="trans('ui.studio.no_projects')"
                :description="trans('ui.studio.no_projects_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.studio.projects')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.name') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.studio.generations') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.studio.target') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.created_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/studio/projects/${row.uuid}`" class="hover:text-accent">
                                {{ row.name }}
                            </Link>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell align="right" numeric>{{ row.generation_count }}</TableCell>
                        <TableCell align="right" numeric>
                            <span v-if="row.target_track_count === null" class="text-muted">
                                {{ trans('ui.studio.no_target') }}
                            </span>
                            <span v-else>{{ row.target_track_count }}</span>
                        </TableCell>
                        <TableCell>{{ dateTime(row.created_at, locale) }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
