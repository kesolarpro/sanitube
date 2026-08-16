<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { Operations } from '@/Types/system';

/**
 * What the platform last saw when it looked outside.
 *
 * **Loading this page probes nothing.** It is what somebody opens during an
 * outage, which is exactly when a page that reaches five external systems will
 * not load. "Check now" is the one exception, and it is a button a person
 * presses rather than a side effect of arriving.
 *
 * When the stored report is stale every verdict is withheld by the server, so
 * this renders unknown rather than a stale green. A health screen that reports
 * healthy through an outage is worse than one that reports nothing.
 */
const props = defineProps<{ operations: Operations }>();

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

const refreshForm = useForm({});
const refreshing = ref(false);

const refreshError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.health ?? null;
});

/** A verdict, or null when nothing may be asserted. */
function verdict(value: boolean | null | undefined): string {
    if (value === null || value === undefined) {
        return trans('ui.states.unknown');
    }

    return value ? trans('ui.status.generic.AVAILABLE') : trans('ui.status.generic.UNAVAILABLE');
}

const schedulerNeverRan = computed(() => props.operations.scheduler.last_run_at === null);

/**
 * Never backed up is a state of its own, not an unknown.
 *
 * A backup job that silently stopped is the classic disaster: nothing looks
 * wrong until the day something is, and by then the newest backup is months
 * old. A dash would hide exactly that.
 */
const neverBackedUp = computed(
    () => props.operations.backups.readable && props.operations.backups.taken_at === null,
);

function refresh(): void {
    refreshing.value = true;
    refreshForm.post('/system/operations/refresh', {
        preserveScroll: true,
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-page-title text-foreground">{{ trans('ui.system.operations') }}</h1>
                <p class="mt-1 text-small text-muted">{{ trans('ui.system.operations_description') }}</p>
            </div>
            <AppButton variant="secondary" :loading="refreshForm.processing || refreshing" @click="refresh">
                {{ trans('ui.system.refresh') }}
            </AppButton>
        </div>

        <AppAlert v-if="refreshError !== null" tone="danger" :title="trans('ui.states.error_title')">
            {{ trans(`ui.system.refresh_failure.${refreshError}`) }}
        </AppAlert>

        <!-- The three states read differently on purpose. -->
        <AppAlert
            v-if="operations.health.state === 'UNKNOWN'"
            tone="info"
            :title="trans('ui.system.state.UNKNOWN')"
        >
            {{ trans('ui.system.unknown_note') }}
        </AppAlert>

        <AppAlert
            v-else-if="operations.health.state === 'STALE'"
            tone="warning"
            :title="trans('ui.system.state.STALE')"
        >
            {{ trans('ui.system.stale_note') }}
        </AppAlert>

        <AppCard>
            <template #header>{{ trans('ui.system.health') }}</template>

            <p class="mb-3 text-caption text-muted">
                {{ trans('ui.system.checked_at') }}:
                {{
                    operations.health.checked_at === null
                        ? trans('ui.system.never_checked')
                        : dateTime(operations.health.checked_at, locale)
                }}
            </p>

            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.storage') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ operations.health.storage.provider ?? '—' }} ·
                        {{ verdict(operations.health.storage.healthy) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.ai') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ operations.health.ai.name ?? '—' }} · {{ verdict(operations.health.ai.available) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.generation') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ operations.health.generation.name ?? '—' }} ·
                        {{ verdict(operations.health.generation.available) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.capabilities') }}</dt>
                    <dd class="text-small text-foreground">{{ verdict(operations.health.capabilities.healthy) }}</dd>
                </div>
            </dl>

            <div v-if="operations.health.distributors.length > 0" class="mt-4 border-t border-border pt-3">
                <p class="text-caption text-muted">{{ trans('ui.system.distributors') }}</p>
                <dl class="mt-2 grid gap-3 sm:grid-cols-3">
                    <div v-for="(distributor, index) in operations.health.distributors" :key="index">
                        <dt class="text-caption text-muted">{{ distributor.name ?? '—' }}</dt>
                        <dd class="text-small text-foreground">{{ verdict(distributor.available) }}</dd>
                    </div>
                </dl>
            </div>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.system.scheduler') }}</template>
            <p class="mb-3 text-caption text-muted">{{ trans('ui.system.scheduler_description') }}</p>

            <!-- Never-ran is not an error state, but it is the one that
                 silently breaks retries and imports, so it is said plainly. -->
            <AppAlert v-if="schedulerNeverRan" tone="warning" :title="trans('ui.system.scheduler_last_run')">
                {{ trans('ui.system.scheduler_never') }}
            </AppAlert>

            <dl v-else>
                <dt class="text-caption text-muted">{{ trans('ui.system.scheduler_last_run') }}</dt>
                <dd class="text-small text-foreground">
                    {{ dateTime(operations.scheduler.last_run_at, locale) }}
                </dd>
            </dl>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.system.backups') }}</template>

            <!-- A misconfigured destination reports itself rather than taking
                 this screen down. This is the page somebody opens when things
                 are already wrong. -->
            <AppAlert
                v-if="!operations.backups.readable"
                tone="danger"
                :title="trans('ui.system.backups_unreadable')"
            >
                {{ trans('ui.system.backups_unreadable_note') }}
            </AppAlert>

            <AppAlert
                v-else-if="neverBackedUp"
                tone="warning"
                :title="trans('ui.system.never_backed_up')"
            >
                {{ trans('ui.system.never_backed_up_note') }}
            </AppAlert>

            <dl v-else class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.last_backup') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ dateTime(operations.backups.taken_at, locale) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.backup_count') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.backups.count }}</dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.system.queue') }}</template>

            <AppAlert
                v-if="!operations.queue.readable"
                tone="info"
                :title="trans('ui.system.queue_unreadable')"
            >
                {{ trans('ui.system.queue_unreadable_note') }}
            </AppAlert>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard :label="trans('ui.system.queue_driver')" :value="null" :hint="operations.queue.driver" />
                <!-- null renders as an em dash, never as 0. -->
                <MetricCard :label="trans('ui.system.pending')" :value="operations.queue.pending" href="/system/jobs" />
                <MetricCard :label="trans('ui.system.reserved')" :value="operations.queue.reserved" />
                <MetricCard :label="trans('ui.system.failed')" :value="operations.queue.failed" href="/system/jobs" />
            </div>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.system.runtime') }}</template>

            <!-- Debug mode in production shows stack traces and configuration
                 to anybody who can trigger an error. -->
            <AppAlert v-if="operations.runtime.debug" tone="warning" :title="trans('ui.system.debug_on')">
                {{ trans('ui.system.debug_on_note') }}
            </AppAlert>

            <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.php_version') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.runtime.php_version }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.environment') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.runtime.environment }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.database_driver') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.runtime.database_driver }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.queue_driver') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.runtime.queue_driver }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.cache_driver') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.runtime.cache_driver }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.system.session_driver') }}</dt>
                    <dd class="text-small text-foreground">{{ operations.runtime.session_driver }}</dd>
                </div>
            </dl>
        </AppCard>
    </div>
</template>
