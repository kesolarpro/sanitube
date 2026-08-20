<script setup lang="ts">
import { computed } from 'vue';
import { trans } from '@/Support/i18n';

/**
 * A domain status, rendered in the colour of what it means.
 *
 * The mapping lives here and nowhere else. Every module in SaniTube has its
 * own status enum — candidates, generations, releases, deliveries — and
 * without one place deciding what "in progress" looks like, the same idea ends
 * up amber on one screen and blue on another.
 *
 * The *label* comes from the translations, never from the enum value: showing
 * `WAITING_CAPABILITY` to a user is showing them the database.
 */
const props = defineProps<{ status: string; group: string }>();

type Tone = 'neutral' | 'progress' | 'success' | 'warning' | 'danger';

const TONES: Record<string, Tone> = {
    // Nothing has happened yet.
    DRAFT: 'neutral',
    PENDING: 'neutral',
    QUEUED: 'neutral',
    UNKNOWN: 'neutral',
    SKIPPED: 'neutral',
    OPTIONAL: 'neutral',
    WAITING: 'neutral',

    // Work is under way, or somebody else owes an answer.
    PROCESSING: 'progress',
    // PROD-UI-001: an occasion something has taken and not yet settled, and a
    // plan that is running. Both mean "in motion", which is what progress is.
    CLAIMED: 'progress',
    ACTIVE: 'progress',
    UPLOADING: 'progress',
    VERIFYING: 'progress',
    VALIDATING: 'progress',
    SUBMITTING: 'progress',
    // ART-004: a cover request that has reached a provider.
    SUBMITTED: 'progress',
    ACCEPTED: 'progress',
    DELIVERED: 'progress',
    TAKEDOWN_REQUESTED: 'progress',

    // Finished, and finished well.
    READY: 'success',
    COMPLETED: 'success',
    DONE: 'success',
    IMPORTED: 'success',
    UPLOADED: 'success',
    VERIFIED: 'success',
    PROMOTED: 'success',
    LIVE: 'success',
    RELEASED: 'success',
    AVAILABLE: 'success',
    ALLOWED: 'success',
    // A plan that made what it set out to make. Finished well, and the reason
    // it is not `neutral`: an exhausted plan is a plan that worked.
    EXHAUSTED: 'success',

    // A person needs to look.
    NEEDS_REVIEW: 'warning',
    WAITING_CAPABILITY: 'warning',
    DUPLICATE: 'warning',
    REVIEW_REQUIRED: 'warning',
    RESTRICTED: 'warning',
    DEGRADED: 'warning',
    COMPLETED_WITH_ERRORS: 'warning',
    // Somebody stopped it on purpose. Not danger -- nothing went wrong -- and
    // not neutral, because a paused plan is a plan somebody has to restart.
    PAUSED: 'warning',
    // PROD-005: a supplier was asked and the answer was lost. Warning rather
    // than danger, because nothing is known to have gone wrong -- and firmly
    // not neutral, because somebody may have been charged. Deliberately a
    // different key from the `UNKNOWN` commercial rights use, where "not yet
    // determined" is the ordinary starting state.
    UNKNOWN_RESULT: 'warning',

    // It went wrong, or somebody said no.
    FAILED: 'danger',
    REJECTED: 'danger',
    CANCELLED: 'danger',
    TAKEN_DOWN: 'danger',
    PROHIBITED: 'danger',
    UNAVAILABLE: 'danger',
    ARCHIVED: 'neutral',
    DISABLED: 'neutral',
};

const tone = computed<Tone>(() => TONES[props.status] ?? 'neutral');

const classes = computed(
    () =>
        ({
            neutral: 'bg-surface-sunken text-muted border-border',
            progress: 'bg-info-subtle text-info border-transparent',
            success: 'bg-success-subtle text-success border-transparent',
            warning: 'bg-warning-subtle text-warning border-transparent',
            danger: 'bg-danger-subtle text-danger border-transparent',
        })[tone.value],
);
</script>

<template>
    <span
        class="inline-flex items-center rounded-pill border px-2 py-0.5 text-caption font-medium"
        :class="classes"
    >
        {{ trans(`ui.status.${group}.${status}`) }}
    </span>
</template>
