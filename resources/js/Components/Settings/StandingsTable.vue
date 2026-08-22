<script setup lang="ts">
import AppCard from '@/Components/Ui/AppCard.vue';
import { trans } from '@/Support/i18n';
import type { CertificationStatus, ProviderStanding } from '@/Types/settings';

/**
 * Has anybody actually proved this works?
 *
 * CFG-003. The six-state vocabulary existed for a phase and could be read from
 * exactly one place — `sanitube:providers`, over SSH — so the question was
 * answerable only by somebody with a shell, on the screen whose whole subject
 * is what is configured.
 *
 * **Six words, and never a boolean.** "Is object storage working?" answered
 * yes-or-no is how a platform ships claiming things nobody ever ran. Each of
 * these means something different and points at a different next step:
 * *configured but uncertified* is a button away from an answer, *blocked
 * externally* is waiting on somebody who is not here, and *unavailable* is the
 * world rather than this installation — no amount of configuring changes it.
 *
 * A standing reports what the **ledger recorded**, not what is reachable now.
 * That is deliberate: rendering this page must never depend on nine providers
 * answering, or an outage takes down the one page somebody opens to find out
 * why.
 */
defineProps<{ standings: ProviderStanding[] }>();

const TONES: Record<CertificationStatus, string> = {
    CERTIFIED: 'text-success',
    CODE_READY: 'text-success',
    CONFIGURED_UNCERTIFIED: 'text-warning',
    BLOCKED_EXTERNAL: 'text-warning',
    NOT_CONFIGURED: 'text-muted',
    UNAVAILABLE: 'text-muted',
};

function tone(status: CertificationStatus): string {
    return TONES[status] ?? 'text-muted';
}
</script>

<template>
    <AppCard>
        <template #header>{{ trans('ui.settings.standings') }}</template>

        <p class="text-caption text-muted">{{ trans('ui.settings.standings_note') }}</p>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-small">
                <thead>
                    <tr class="border-b border-border text-left text-caption text-muted">
                        <th class="py-1 pr-3 font-medium">{{ trans('ui.settings.standing_provider') }}</th>
                        <th class="py-1 pr-3 font-medium">{{ trans('ui.settings.standing_status') }}</th>
                        <th class="py-1 font-medium">{{ trans('ui.settings.standing_detail') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="standing in standings" :key="standing.key" class="border-b border-border">
                        <td class="py-1.5 pr-3 align-top text-foreground">{{ standing.label }}</td>
                        <td class="py-1.5 pr-3 align-top whitespace-nowrap" :class="tone(standing.status)">
                            {{ trans(`ui.settings.standing.${standing.status}`) }}
                        </td>
                        <td class="py-1.5 align-top text-muted">
                            {{ standing.detail }}
                            <!-- When, not whether. A certified standing with no
                                 date would be a claim with nothing behind it. -->
                            <span v-if="standing.certified_at" class="block text-caption">
                                {{ trans('ui.settings.certified_at') }}: {{ standing.certified_at }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppCard>
</template>
