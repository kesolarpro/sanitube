<script setup lang="ts">
import { computed } from 'vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import { bytes, count } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { StorageUsage } from '@/Types/system';

/**
 * STO-005. What this installation is keeping, said in bytes.
 *
 * The platform recorded `byte_size` on every asset from the first upload and
 * asked the question nowhere, so an operator could not find out how much they
 * were storing without a database client — which on a metered object store is
 * the number that turns into a bill. An asset *count* says nothing about it: a
 * thousand previews and a thousand masters are the same number and three
 * orders of magnitude apart.
 *
 * **The heading says whose number this is**, and that sentence is the whole
 * honesty of the panel. It is what the catalogue records, not what a provider
 * bills, and the two disagree for reasons that are nobody's fault.
 *
 * **The trash is shown apart and never folded in.** Those bytes still cost,
 * which is the entire reason to show them: an operator watching a total that
 * will not go down needs to know how much of it is waiting on a decision they
 * have not made.
 */
const props = defineProps<{ usage: StorageUsage; locale: string }>();

/** Kinds holding nothing are dropped from the list, not from the total. */
const kinds = computed(() =>
    Object.entries(props.usage.by_kind)
        .filter(([, total]) => total.bytes > 0)
        .sort((a, b) => b[1].bytes - a[1].bytes),
);
</script>

<template>
    <AppCard>
        <template #header>{{ trans('ui.storage_usage.title') }}</template>

        <p class="text-caption text-muted">{{ trans('ui.storage_usage.what_this_is') }}</p>

        <p v-if="!usage.measured" class="mt-3 text-small text-warning">
            {{ trans('ui.storage_usage.not_measured') }}
        </p>

        <template v-else>
            <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.storage_usage.held') }}</dt>
                    <dd class="text-metric text-foreground">{{ bytes(usage.held.bytes) }}</dd>
                    <dd class="text-caption text-muted">
                        {{ trans('ui.storage_usage.assets', { count: count(usage.held.assets, locale) }) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.storage_usage.trashed') }}</dt>
                    <dd
                        class="text-metric"
                        :class="(usage.trashed.bytes ?? 0) > 0 ? 'text-warning' : 'text-foreground'"
                    >
                        {{ bytes(usage.trashed.bytes) }}
                    </dd>
                    <dd class="text-caption text-muted">{{ trans('ui.storage_usage.trashed_note') }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.storage_usage.unsure') }}</dt>
                    <dd class="text-metric text-foreground">{{ bytes(usage.unsure.bytes) }}</dd>
                    <dd class="text-caption text-muted">{{ trans('ui.storage_usage.unsure_note') }}</dd>
                </div>
            </dl>

            <div v-if="kinds.length > 0" class="mt-4 border-t border-border pt-3">
                <p class="text-caption text-muted">{{ trans('ui.storage_usage.by_kind') }}</p>
                <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                    <div v-for="[kind, total] in kinds" :key="kind" class="flex justify-between gap-2">
                        <dt class="text-small text-muted">{{ trans(`ui.status.asset_kind.${kind}`) }}</dt>
                        <dd class="text-small text-foreground">{{ bytes(total.bytes) }}</dd>
                    </div>
                </dl>
            </div>

            <div v-if="usage.by_disk.length > 0" class="mt-4 border-t border-border pt-3">
                <!-- More than one disk is the ordinary outcome of moving to
                     object storage: the new provider takes what arrives next
                     and what came before stays where it was. Somebody paying
                     two bills at once is who this row is for. -->
                <p class="text-caption text-muted">{{ trans('ui.storage_usage.by_disk') }}</p>
                <dl class="mt-2 space-y-1">
                    <div v-for="disk in usage.by_disk" :key="disk.disk" class="flex flex-wrap justify-between gap-2">
                        <dt class="text-small text-muted"><CodeValue :value="disk.disk" /></dt>
                        <dd class="text-small text-foreground">
                            {{ bytes(disk.bytes) }}
                            <span v-if="disk.trashed_bytes > 0" class="text-caption text-warning">
                                {{ trans('ui.storage_usage.of_which_trashed', { bytes: bytes(disk.trashed_bytes) }) }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </template>
    </AppCard>
</template>
