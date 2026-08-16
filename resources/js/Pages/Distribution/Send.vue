<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { Destination, Preflight, SendPayload } from '@/Types/distribution';

/**
 * Where this release could go.
 *
 * The screen behind the one irreversible act on the platform, so it is built
 * to put the two mistakes that cannot be taken back in front of a person
 * *before* the button:
 *
 *   - **Submitting twice.** A delivery that already exists is shown on the same
 *     row as its distributor, with its status.
 *   - **Submitting a real release into a sandbox.** A rehearsal is legitimate,
 *     which is exactly why it is labelled — a sandbox delivery that looks real
 *     is discovered weeks later, when stores have nothing.
 *
 * Asking for a verdict and handing over are separate buttons, and the verdict
 * creates no record. A preflight that left a draft delivery behind would turn
 * "let me check" into something a colleague reads as an intention.
 */
const props = defineProps<{ send: SendPayload }>();

const page = usePage<SharedProps>();

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.distribution ?? null;
});

/**
 * The verdict for one destination, held here rather than flashed.
 *
 * Asking is a read that changes nothing, so it is a GET answered with JSON —
 * the same shape the release builder's pickers use. Keeping it in component
 * state means a person can fix something, ask again, and compare, without a
 * full page round trip putting them back at the top.
 */
const preflight = ref<Preflight | null>(null);
const preflightFailed = ref(false);

const submitting = ref<Destination | null>(null);
const acting = ref(false);
const validating = ref<string | null>(null);

async function askForVerdict(destination: Destination): Promise<void> {
    validating.value = destination.provider;
    preflightFailed.value = false;
    preflight.value = null;

    try {
        const response = await fetch(
            `/releases/${props.send.release.uuid}/distribution/${destination.provider}/verdict`,
            {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            // A refusal is not a verdict. Showing "would be accepted" for a
            // 403 is the single worst thing this screen could say.
            preflightFailed.value = true;

            return;
        }

        preflight.value = (await response.json()) as Preflight;
    } catch {
        preflightFailed.value = true;
    } finally {
        validating.value = null;
    }
}

function submit(): void {
    const destination = submitting.value;

    if (destination === null) {
        return;
    }

    acting.value = true;

    router.post(
        `/releases/${props.send.release.uuid}/distribution/${destination.provider}/submit`,
        {},
        {
            onFinish: () => {
                acting.value = false;
                submitting.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link :href="`/releases/${send.release.uuid}`" class="hover:text-accent">
                    {{ send.release.title }}
                </Link>
            </p>
            <h1 class="text-page-title text-foreground">{{ trans('ui.distribution.send_title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.distribution.send_description') }}</p>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.distribution.failure.${refusal}`) }}
        </AppAlert>

        <!-- Said outright rather than left to a disabled button: "why can I
             not send this" is the question this screen exists to answer. -->
        <AppAlert
            v-if="!send.release_is_ready"
            tone="warning"
            :title="trans('ui.distribution.release_not_ready')"
        >
            {{ trans('ui.distribution.release_not_ready_note') }}
        </AppAlert>

        <AppCard :padded="false">
            <EmptyState
                v-if="send.destinations.length === 0"
                :title="trans('ui.distribution.no_destinations')"
                :description="trans('ui.distribution.no_destinations_description')"
            />

            <ul v-else class="divide-y divide-border">
                <li v-for="destination in send.destinations" :key="destination.provider" class="space-y-3 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-small font-medium text-foreground">{{ destination.provider }}</p>
                            <p v-if="destination.delivery !== null" class="mt-1 text-caption text-muted">
                                {{ trans('ui.distribution.already_delivered') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                v-if="destination.delivery !== null"
                                :status="destination.delivery.status"
                                group="generic"
                            />
                            <Link
                                v-if="destination.delivery !== null"
                                :href="`/distribution/${destination.delivery.uuid}`"
                                class="text-small hover:text-accent"
                            >
                                {{ trans('ui.distribution.view_delivery') }}
                            </Link>
                        </div>
                    </div>

                    <AppAlert
                        v-if="!destination.known"
                        tone="warning"
                        :title="trans('ui.distribution.unknown_provider')"
                    >
                        {{ trans('ui.distribution.unknown_provider_note') }}
                    </AppAlert>

                    <AppAlert
                        v-else-if="!destination.available"
                        tone="warning"
                        :title="trans('ui.distribution.unavailable')"
                    >
                        {{ trans('ui.distribution.unavailable_note') }}
                    </AppAlert>

                    <!-- Labelled, not hidden. A sandbox delivery is a
                         legitimate rehearsal and a silent one is an incident. -->
                    <AppAlert
                        v-if="destination.sandbox === true"
                        tone="info"
                        :title="trans('ui.distribution.sandbox')"
                    >
                        {{ trans('ui.distribution.sandbox_note') }}
                    </AppAlert>

                    <AppAlert
                        v-if="preflightFailed && validating === null"
                        tone="warning"
                        :title="trans('ui.distribution.preflight')"
                    >
                        {{ trans('ui.releases.picker_failed') }}
                    </AppAlert>

                    <div v-if="preflight !== null && preflight.provider === destination.provider" class="space-y-2">
                        <AppAlert
                            :tone="preflight.valid ? 'success' : 'danger'"
                            :title="
                                preflight.valid
                                    ? trans('ui.distribution.preflight_valid')
                                    : trans('ui.distribution.preflight_invalid')
                            "
                        />

                        <div v-if="preflight.errors.length > 0">
                            <p class="text-caption text-danger">{{ trans('ui.distribution.preflight_blocking') }}</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-small text-foreground">
                                <li v-for="(problem, index) in preflight.errors" :key="`e-${index}`">
                                    {{ problem }}
                                </li>
                            </ul>
                        </div>

                        <div v-if="preflight.warnings.length > 0">
                            <p class="text-caption text-muted">{{ trans('ui.distribution.preflight_warnings') }}</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-small text-muted">
                                <li v-for="(warning, index) in preflight.warnings" :key="`w-${index}`">
                                    {{ warning }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div v-if="send.may_distribute" class="flex flex-wrap gap-2">
                        <AppButton
                            v-if="destination.can_validate"
                            variant="secondary"
                            :loading="validating === destination.provider"
                            @click="askForVerdict(destination)"
                        >
                            {{ trans('ui.distribution.preflight') }}
                        </AppButton>

                        <AppButton
                            v-if="destination.can_submit"
                            variant="primary"
                            @click="submitting = destination"
                        >
                            {{ trans('ui.distribution.submit') }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </AppCard>

        <!-- A confirmation, and the wording says what cannot be taken back. -->
        <ConfirmDialog
            :open="submitting !== null"
            :title="trans('ui.distribution.submit')"
            :description="trans('ui.distribution.submit_description')"
            :confirm-label="trans('ui.distribution.submit_confirm')"
            destructive
            :busy="acting"
            @cancel="submitting = null"
            @confirm="submit"
        />
    </div>
</template>
