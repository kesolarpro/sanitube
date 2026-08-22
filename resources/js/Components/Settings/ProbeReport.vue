<script setup lang="ts">
import AppAlert from '@/Components/Ui/AppAlert.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import { trans } from '@/Support/i18n';
import type { ProbeResult } from '@/Types/settings';

/**
 * What a connection test answered.
 *
 * CFG-001. Four words and no fifth: **working**, **working with limits**,
 * **not working**, **nothing to test yet**. A provider that answers but cannot
 * mint a presigned upload is a real, usable configuration, and calling it
 * broken would send somebody hunting a fault that is a capability — so
 * "degraded" is its own word rather than a shade of failure.
 *
 * Every line here comes from the server already scrubbed. There is no address
 * and no credential to render, because the response never carried one: a
 * settings screen is the most screenshotted page in any application, and a
 * failed signed read quotes the URL it could not fetch, signature and all.
 */
const props = defineProps<{ result: ProbeResult | null }>();

function tone(status: string): 'success' | 'warning' | 'danger' {
    if (status === 'CONNECTED') {
        return 'success';
    }

    return status === 'FAILED' || status === 'UNREACHABLE' ? 'danger' : 'warning';
}

function title(status: string): string {
    // UNREACHABLE is this component's own word, not the server's: it means the
    // request never came back at all, which is a different thing from a store
    // that answered and said no.
    return status === 'UNREACHABLE'
        ? trans('ui.settings.probe_unreachable')
        : trans(`ui.settings.probe.${status}`);
}
</script>

<template>
    <AppAlert
        v-if="props.result"
        class="mt-3"
        :tone="tone(props.result.status)"
        :title="title(props.result.status)"
    >
        <ul v-if="props.result.checks.length > 0" class="space-y-1">
            <li v-for="check in props.result.checks" :key="check.name" class="text-caption">
                <CodeValue :value="check.name" />
                <span class="ml-2">{{ check.outcome }}</span>
                <span v-if="check.detail" class="ml-2 text-muted">{{ check.detail }}</span>
            </li>
        </ul>
    </AppAlert>
</template>
