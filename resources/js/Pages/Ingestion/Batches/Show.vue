<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { BatchProgress, ItemPage } from '@/Types/ingestion';

/**
 * One import, and every file in it.
 *
 * Each state gets a tile, **including the ones at zero**. A bucket that
 * disappears when empty makes "no failures" and "the failed count is not being
 * shown" look identical, and those call for opposite reactions from somebody
 * watching an import of nine hundred files.
 */
const props = defineProps<{ batch: BatchProgress; items: ItemPage }>();

const locale = usePage<SharedProps>().props.app.locale;

const states = ['PENDING', 'PROCESSING', 'IMPORTED', 'DUPLICATE', 'NEEDS_REVIEW', 'FAILED', 'SKIPPED'] as const;

function count(state: (typeof states)[number]): number {
    return props.batch.items[state];
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/ingestion/batches/${props.batch.uuid}?cursor=${encodeURIComponent(cursor)}`;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-caption text-muted">
                    <Link href="/ingestion/batches" class="hover:text-accent">{{ trans('ui.ingestion.batches') }}</Link>
                </p>
                <h1 class="text-page-title text-foreground">
                    {{ trans('ui.ingestion.batch') }} · {{ dateTime(batch.started_at, locale) }}
                </h1>
                <p class="mt-1 text-small text-muted">{{ trans(`ui.ingestion.source.${batch.source}`) }}</p>
            </div>
            <StatusBadge :status="batch.status" group="generic" />
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <MetricCard :label="trans('ui.ingestion.total')" :value="batch.items.total" />
            <MetricCard
                v-for="state in states"
                :key="state"
                :label="trans(`ui.status.generic.${state}`)"
                :value="count(state)"
            />
        </div>

        <AppCard>
            <template #header>{{ trans('ui.ingestion.the_decision') }}</template>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.started_at') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ batch.started_at === null ? trans('ui.ingestion.not_started') : dateTime(batch.started_at, locale) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.completed_at') }}</dt>
                    <dd class="text-small text-foreground">
                        <!-- Never a guess. A batch still running has no finish time, and printing one would be inventing it. -->
                        {{ batch.completed_at === null ? trans('ui.ingestion.in_progress') : dateTime(batch.completed_at, locale) }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.ingestion.items') }}</template>
            <EmptyState
                v-if="items.rows.length === 0"
                :title="trans('ui.ingestion.no_items')"
                :description="trans('ui.ingestion.no_items_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.ingestion.items')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.ingestion.filename') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.ingestion.attempts') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.failure') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.candidate') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in items.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            {{ row.original_filename }}
                            <span v-if="row.has_manifest_row" class="ml-2 text-caption text-muted">
                                {{ trans('ui.ingestion.from_manifest') }}
                            </span>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell align="right" numeric>{{ row.attempt_count }}</TableCell>
                        <TableCell>
                            <!-- The code, translated. The server never sends the message: it quotes
                                 whatever threw, and those quotes contain paths. -->
                            <span v-if="row.failure_code !== null" class="text-small text-danger">
                                {{ trans(`ui.ingestion.failure_code.${row.failure_code}`) }}
                            </span>
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                        <TableCell>
                            <Link
                                v-if="row.candidate_uuid !== null"
                                :href="`/ingestion/candidates/${row.candidate_uuid}`"
                                class="text-small hover:text-accent"
                            >
                                {{ trans('ui.ingestion.candidate') }}
                            </Link>
                            <span v-else class="text-muted">{{ trans('ui.ingestion.no_candidate') }}</span>
                        </TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(items.previous_cursor)" :next="cursorHref(items.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
