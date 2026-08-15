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

    // Work is under way, or somebody else owes an answer.
    PROCESSING: 'progress',
    VALIDATING: 'progress',
    SUBMITTING: 'progress',
    SUBMITTED: 'progress',
    ACCEPTED: 'progress',
    DELIVERED: 'progress',
    TAKEDOWN_REQUESTED: 'progress',

    // Finished, and finished well.
    READY: 'success',
    COMPLETED: 'success',
    IMPORTED: 'success',
    VERIFIED: 'success',
    PROMOTED: 'success',
    LIVE: 'success',
    ALLOWED: 'success',

    // A person needs to look.
    NEEDS_REVIEW: 'warning',
    WAITING_CAPABILITY: 'warning',
    DUPLICATE: 'warning',
    REVIEW_REQUIRED: 'warning',
    RESTRICTED: 'warning',
    COMPLETED_WITH_ERRORS: 'warning',

    // It went wrong, or somebody said no.
    FAILED: 'danger',
    REJECTED: 'danger',
    CANCELLED: 'danger',
    TAKEN_DOWN: 'danger',
    PROHIBITED: 'danger',
    ARCHIVED: 'neutral',
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
