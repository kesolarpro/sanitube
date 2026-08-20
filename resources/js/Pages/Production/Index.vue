<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { ProductionOptions, ProductionPlanPage } from '@/Types/production';

/**
 * What the platform has been told to do on its own.
 *
 * **Autonomy and running are shown separately, and that is the point of the
 * table.** A plan can be granted the right to act alone and be paused, and an
 * operator looking at a quiet month needs to know which of the two it is —
 * folding them into one column would make "why has nothing happened" a question
 * only a database answers.
 *
 * Skipped and failed are separate columns for the same reason. An inventory
 * that found enough is the system working; a column that summed them would
 * teach somebody to ignore the failure count.
 */
defineProps<{ page: ProductionPlanPage; options: ProductionOptions }>();

usePage<SharedProps>();
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.production.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.production.description') }}</p>
        </div>

        <!--
            Said on the screen rather than in a report nobody opens. A plan that
            may act alone turns a cadence into requests paid for at a supplier,
            and that is the one fact somebody should not have to infer.
        -->
        <AppAlert
            v-if="page.rows.some((row) => row.may_generate_unattended)"
            tone="warning"
            :title="trans('ui.production.title')"
        >
            {{ trans('ui.production.spends_money') }}
        </AppAlert>

        <AppCard :padded="false">
            <EmptyState
                v-if="page.rows.length === 0"
                :title="trans('ui.production.no_plans')"
                :description="trans('ui.production.description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.production.plans')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.production.plan') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.production.autonomy') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.production.occasions') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.occasion.SKIPPED') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.occasion.FAILED') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.production.target') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/production/plans/${row.uuid}`" class="hover:text-accent">
                                {{ row.name }}
                            </Link>
                            <span v-if="row.editorial_profile !== null" class="block text-caption text-muted">
                                {{ row.editorial_profile }}
                            </span>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="production_plan" /></TableCell>
                        <TableCell>
                            <span class="text-small">
                                {{ trans(`ui.production.autonomy_mode.${row.autonomy_mode}`) }}
                            </span>
                            <span
                                class="block text-caption"
                                :class="row.may_generate_unattended ? 'text-warning' : 'text-muted'"
                            >
                                {{
                                    row.may_generate_unattended
                                        ? trans('ui.production.unattended_yes')
                                        : trans('ui.production.unattended_no')
                                }}
                            </span>
                        </TableCell>
                        <TableCell align="right" numeric>{{ row.occasions.total }}</TableCell>
                        <TableCell align="right" numeric>{{ row.occasions.SKIPPED }}</TableCell>
                        <TableCell align="right" numeric>{{ row.occasions.FAILED }}</TableCell>
                        <TableCell align="right" numeric>
                            <span v-if="row.target_track_count === null" class="text-muted">
                                {{ trans('ui.production.no_target') }}
                            </span>
                            <span v-else>{{ row.target_track_count }}</span>
                        </TableCell>
                    </tr>
                </DataTable>
                <CursorPagination
                    :prev="page.previous_cursor === null ? null : `/production?cursor=${encodeURIComponent(page.previous_cursor)}`"
                    :next="page.next_cursor === null ? null : `/production?cursor=${encodeURIComponent(page.next_cursor)}`"
                />
            </template>
        </AppCard>

        <!-- The vocabulary the server owns, so a filter added later has it. -->
        <p class="sr-only">{{ options.status.join(' ') }}</p>
    </div>
</template>
