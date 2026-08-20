<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import FormField from '@/Components/Ui/FormField.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import { bitrate, bytes, dateTime, duration, sampleRate } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { CandidateDetail } from '@/Types/ingestion';

/**
 * One proposal, and everything a person needs to decide about it.
 *
 * The screen is built around keeping four kinds of evidence visibly apart:
 *
 *   - **What the file is** — measured from the file. Facts.
 *   - **What the manifest claimed** — asserted by whoever prepared the import.
 *   - **What the platform noticed** — duplicate bytes, an ISRC already in use.
 *   - **The decision** — what a person has already concluded, if anything.
 *
 * Merging the manifest's title into the same block as the analyser's duration
 * would invite a reviewer to accord them the same confidence, and they have
 * earned very different amounts.
 *
 * The actions sit at the bottom, after the evidence. A decision is taken after
 * reading, and a promote button above the analysis is one people press before
 * they have looked.
 */
const props = defineProps<{ candidate: CandidateDetail }>();

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

const promoting = ref(false);
const rejecting = ref(false);
const revising = ref(false);

/**
 * Whether the domain will demand an explicit override with a note.
 *
 * Asked of the payload rather than inferred from the status here, so the rule
 * lives in one place. A NEEDS_REVIEW or DUPLICATE candidate can still be
 * promoted by a person who has listened to the file — what they cannot do is
 * overrule the machine silently.
 */
const needsOverride = computed(
    () => !props.candidate.actions.can_promote && props.candidate.actions.can_promote_with_override,
);

const promoteForm = useForm({
    title: props.candidate.suggested_title ?? '',
    override: false,
    note: '',
});

const rejectForm = useForm({ reason: '' });
const reviseForm = useForm({ suggested_title: props.candidate.suggested_title ?? '' });

/**
 * A refusal from the domain, as its machine-readable reason code.
 *
 * The server never sends the domain's English sentence — it is written for a
 * developer reading a log — so the code is what travels and this translates
 * it.
 */
const reviewError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.review ?? null;
});

function openPromote(): void {
    promoteForm.clearErrors();
    promoteForm.title = props.candidate.suggested_title ?? '';
    promoteForm.note = '';
    promoteForm.override = needsOverride.value;
    promoting.value = true;
}

function openReject(): void {
    rejectForm.clearErrors();
    rejectForm.reason = '';
    rejecting.value = true;
}

function openRevise(): void {
    reviseForm.clearErrors();
    reviseForm.suggested_title = props.candidate.suggested_title ?? '';
    revising.value = true;
}

function submitPromote(): void {
    promoteForm.post(`/ingestion/candidates/${props.candidate.uuid}/promote`, {
        preserveScroll: true,
        onSuccess: () => {
            promoting.value = false;
        },
    });
}

function submitReject(): void {
    rejectForm.post(`/ingestion/candidates/${props.candidate.uuid}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = false;
        },
    });
}

function submitRevise(): void {
    reviseForm.patch(`/ingestion/candidates/${props.candidate.uuid}`, {
        preserveScroll: true,
        onSuccess: () => {
            revising.value = false;
        },
    });
}

const claimed = computed(() => {
    const manifest = props.candidate.manifest;

    if (manifest === null) {
        return [];
    }

    const fields: { key: string; value: string | number | null }[] = [
        { key: 'title', value: manifest.claimed.title },
        { key: 'artist', value: manifest.claimed.artist },
        { key: 'release_title', value: manifest.claimed.release_title },
        { key: 'disc_number', value: manifest.claimed.disc_number },
        { key: 'track_number', value: manifest.claimed.track_number },
        { key: 'language', value: manifest.claimed.language },
        { key: 'isrc', value: manifest.claimed.isrc },
        { key: 'upc', value: manifest.claimed.upc },
        { key: 'legacy_provider', value: manifest.claimed.legacy_provider },
        { key: 'notes', value: manifest.claimed.notes },
    ];

    // Only what was actually stated. A row reading "Language: —" says the
    // manifest was asked and answered nothing; leaving it out says it was
    // never asked, which is what happened.
    return fields.filter((field) => field.value !== null && field.value !== '');
});

const findings = computed(() => {
    const manifest = props.candidate.manifest;

    return {
        conflicts: manifest?.conflicts ?? [],
        observations: manifest?.observations ?? [],
    };
});

const hasFindings = computed(
    () =>
        findings.value.conflicts.length > 0 ||
        findings.value.observations.length > 0 ||
        props.candidate.duplicate !== null,
);

const manifestLabels: Record<string, string> = {
    title: 'ui.catalog.column.title',
    artist: 'ui.catalog.column.artists',
    release_title: 'ui.catalog.column.releases',
    disc_number: 'ui.ingestion.manifest_line',
    track_number: 'ui.catalog.column.tracks',
    language: 'ui.catalog.column.language',
    isrc: 'ui.catalog.column.isrc',
    upc: 'ui.catalog.column.iswc',
    legacy_provider: 'ui.catalog.filter.source',
    notes: 'ui.ingestion.review_note',

    // TAG-001. The tag names ffprobe normalises to, sharing the labels the
    // manifest already uses where they mean the same thing.
    album: 'ui.catalog.album',
    album_artist: 'ui.catalog.column.artists',
    genre: 'ui.catalog.genre',
    year: 'ui.catalog.recording_year',
};

function labelFor(key: string): string {
    return trans(manifestLabels[key] ?? key);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-caption text-muted">
                    <Link href="/ingestion/candidates" class="hover:text-accent">
                        {{ trans('ui.ingestion.candidates') }}
                    </Link>
                </p>
                <h1 class="text-page-title text-foreground">{{ candidate.suggested_title ?? candidate.original_filename }}</h1>
                <p class="mt-1 text-small text-muted">{{ trans('ui.ingestion.suggested_title_note') }}</p>
            </div>
            <StatusBadge :status="candidate.status" group="generic" />
        </div>

        <AppCard>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.filename') }}</dt>
                    <dd class="text-small text-foreground">{{ candidate.original_filename }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.suggested_title') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ candidate.suggested_title ?? '—' }}
                        <span v-if="candidate.suggested_title_source !== null" class="ml-2 text-caption text-muted">
                            {{ trans(`ui.ingestion.title_source.${candidate.suggested_title_source}`) }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.filter.source') }}</dt>
                    <dd class="text-small text-foreground">{{ trans(`ui.ingestion.source.${candidate.source}`) }}</dd>
                </div>
                <div v-if="candidate.failure_code !== null">
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.failure') }}</dt>
                    <dd class="text-small text-danger">
                        {{ trans(`ui.ingestion.failure_code.${candidate.failure_code}`) }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <!-- What the file is: measured, not claimed. -->
        <AppCard>
            <template #header>{{ trans('ui.ingestion.what_the_file_is') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.ingestion.what_the_file_is_note') }}</p>

            <AppAlert v-if="candidate.analysis === null" tone="info" :title="trans('ui.ingestion.not_measured')">
                {{ trans('ui.ingestion.not_measured_note') }}
            </AppAlert>

            <AppAlert
                v-else-if="!candidate.analysis.succeeded"
                tone="warning"
                :title="trans('ui.ingestion.analysis_failed')"
            />

            <dl v-else class="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.column.duration') }}</dt>
                    <dd class="text-small text-foreground">{{ duration(candidate.analysis.duration_ms) }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.codec') }}</dt>
                    <dd class="text-small text-foreground">{{ candidate.analysis.codec ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.sample_rate') }}</dt>
                    <dd class="text-small text-foreground">{{ sampleRate(candidate.analysis.sample_rate) }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.channels') }}</dt>
                    <dd class="text-small text-foreground">{{ candidate.analysis.channels ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.bitrate') }}</dt>
                    <dd class="text-small text-foreground">{{ bitrate(candidate.analysis.bitrate) }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.loudness') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ candidate.analysis.loudness_lufs === null ? '—' : `${candidate.analysis.loudness_lufs} LUFS` }}
                    </dd>
                </div>
            </dl>

            <div v-if="candidate.asset !== null" class="mt-4 border-t border-border pt-3">
                <dl class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.checksum') }}</dt>
                        <dd class="text-small">
                            <Link :href="`/catalog/assets/${candidate.asset.uuid}`" class="hover:text-accent">
                                <CodeValue :value="candidate.asset.checksum_short" />
                            </Link>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.size') }}</dt>
                        <dd class="text-small text-foreground">{{ bytes(candidate.asset.byte_size) }}</dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.status') }}</dt>
                        <dd class="text-small"><StatusBadge :status="candidate.asset.status" group="generic" /></dd>
                    </div>
                </dl>
            </div>
        </AppCard>

        <!-- TAG-001. What the file says about itself: a fourth kind of claim,
             kept apart from the manifest, the analysis and the catalogue.
             Files acquire tags from whatever last touched them, so a reviewer
             has to be able to see the file disagree rather than be shown one
             merged answer. Absent entirely when the file carries nothing. -->
        <AppCard v-if="candidate.embedded_tags !== null">
            <template #header>{{ trans('ui.ingestion.what_the_file_says') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.ingestion.what_the_file_says_note') }}</p>

            <dl class="grid gap-3 sm:grid-cols-2">
                <div v-for="(value, key) in candidate.embedded_tags" :key="key">
                    <dt class="text-caption text-muted">{{ labelFor(String(key)) }}</dt>
                    <dd class="text-small text-foreground">{{ value }}</dd>
                </div>
            </dl>
        </AppCard>

        <!-- What the manifest claimed: evidence, never applied. -->
        <AppCard>
            <template #header>{{ trans('ui.ingestion.what_was_claimed') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.ingestion.what_was_claimed_note') }}</p>

            <AppAlert v-if="candidate.manifest === null" tone="info" :title="trans('ui.ingestion.no_manifest')">
                {{ trans('ui.ingestion.no_manifest_note') }}
            </AppAlert>

            <template v-else>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div v-for="field in claimed" :key="field.key">
                        <dt class="text-caption text-muted">{{ labelFor(field.key) }}</dt>
                        <dd class="text-small text-foreground">{{ field.value }}</dd>
                    </div>
                </dl>

                <div v-if="Object.keys(candidate.manifest.extra).length > 0" class="mt-4 border-t border-border pt-3">
                    <p class="text-caption text-muted">{{ trans('ui.ingestion.manifest_extra') }}</p>
                    <dl class="mt-2 grid gap-3 sm:grid-cols-2">
                        <div v-for="(value, key) in candidate.manifest.extra" :key="key">
                            <dt class="text-caption text-muted">{{ key }}</dt>
                            <dd class="text-small text-foreground">{{ value }}</dd>
                        </div>
                    </dl>
                </div>
            </template>
        </AppCard>

        <!-- What the platform noticed. -->
        <AppCard>
            <template #header>{{ trans('ui.ingestion.what_we_noticed') }}</template>

            <p v-if="!hasFindings" class="text-small text-muted">{{ trans('ui.ingestion.no_findings') }}</p>

            <div v-else class="space-y-3">
                <AppAlert
                    v-for="(conflict, index) in findings.conflicts"
                    :key="`conflict-${index}`"
                    tone="danger"
                    :title="trans('ui.ingestion.conflict')"
                >
                    {{ trans(`ui.ingestion.conflict_code.${conflict.code}`) }}
                    <Link
                        v-if="conflict.held_by_track"
                        :href="`/catalog/tracks/${conflict.held_by_track}`"
                        class="ml-1 underline hover:text-accent"
                    >
                        {{ trans('ui.ingestion.duplicate_track') }}
                    </Link>
                </AppAlert>

                <AppAlert
                    v-if="candidate.duplicate !== null"
                    tone="warning"
                    :title="trans('ui.ingestion.duplicate_bytes')"
                >
                    {{ trans('ui.ingestion.duplicate_bytes_note') }}
                    <Link
                        :href="`/catalog/assets/${candidate.duplicate.asset_uuid}`"
                        class="ml-1 underline hover:text-accent"
                    >
                        {{ trans('ui.ingestion.duplicate_asset') }}
                    </Link>
                    <Link
                        v-if="candidate.duplicate.track !== null"
                        :href="`/catalog/tracks/${candidate.duplicate.track.uuid}`"
                        class="ml-1 underline hover:text-accent"
                    >
                        {{ candidate.duplicate.track.title }}
                    </Link>
                </AppAlert>

                <AppAlert
                    v-for="(observation, index) in findings.observations"
                    :key="`observation-${index}`"
                    tone="info"
                    :title="trans('ui.ingestion.observation')"
                >
                    {{ trans(`ui.ingestion.conflict_code.${observation.code}`) }}
                </AppAlert>
            </div>
        </AppCard>

        <!-- The decision. -->
        <AppCard>
            <template #header>{{ trans('ui.ingestion.the_decision') }}</template>

            <p v-if="candidate.review.reviewed_at === null" class="text-small text-muted">
                {{ trans('ui.ingestion.not_reviewed') }}
            </p>

            <dl v-else class="grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.reviewed_at') }}</dt>
                    <dd class="text-small text-foreground">{{ dateTime(candidate.review.reviewed_at, locale) }}</dd>
                </div>
                <div v-if="candidate.review.note !== null">
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.review_note') }}</dt>
                    <dd class="text-small text-foreground">{{ candidate.review.note }}</dd>
                </div>
                <div v-if="candidate.review.promoted_track !== null">
                    <dt class="text-caption text-muted">{{ trans('ui.ingestion.promoted_track') }}</dt>
                    <dd class="text-small">
                        <Link
                            :href="`/catalog/tracks/${candidate.review.promoted_track.uuid}`"
                            class="hover:text-accent"
                        >
                            {{ candidate.review.promoted_track.title }}
                        </Link>
                    </dd>
                </div>
            </dl>
        </AppCard>

        <!-- The actions. Last on the page on purpose: a decision is taken
             after reading the evidence, not before it. -->
        <AppCard v-if="candidate.actions.may_review">
            <template #header>{{ trans('ui.ingestion.review_actions') }}</template>

            <AppAlert v-if="reviewError !== null" tone="danger" :title="trans('ui.ingestion.refused')">
                {{ trans(`ui.ingestion.refusal.${reviewError}`) }}
            </AppAlert>

            <div class="mt-3 flex flex-wrap gap-2">
                <AppButton
                    v-if="candidate.actions.can_promote || candidate.actions.can_promote_with_override"
                    variant="primary"
                    @click="openPromote"
                >
                    {{ trans('ui.ingestion.promote') }}
                </AppButton>

                <AppButton v-if="candidate.actions.can_revise" variant="secondary" @click="openRevise">
                    {{ trans('ui.ingestion.revise') }}
                </AppButton>

                <AppButton v-if="candidate.actions.can_reject" variant="danger" @click="openReject">
                    {{ trans('ui.ingestion.reject') }}
                </AppButton>
            </div>

            <p
                v-if="!candidate.actions.can_promote && !candidate.actions.can_promote_with_override"
                class="mt-3 text-caption text-muted"
            >
                {{ trans('ui.ingestion.not_promotable_here') }}
            </p>
        </AppCard>

        <!-- Promote. A confirmation rather than a button, because it is the one
             action on this screen that writes to the catalogue. -->
        <ConfirmDialog
            :open="promoting"
            :title="trans('ui.ingestion.promote')"
            :description="trans('ui.ingestion.promote_description')"
            :confirm-label="trans('ui.ingestion.promote_confirm')"
            :busy="promoteForm.processing"
            @cancel="promoting = false"
            @confirm="submitPromote"
        >
            <div class="space-y-3">
                <FormField :label="trans('ui.ingestion.title_for_catalogue')" :error="promoteForm.errors.title">
                    <TextInput v-model="promoteForm.title" />
                </FormField>

                <!-- Only when the domain would demand it. Showing an override
                     box on a READY candidate would teach reviewers to fill one
                     in every time, which is how an override stops meaning
                     anything. -->
                <template v-if="needsOverride">
                    <AppAlert tone="warning" :title="trans('ui.ingestion.override_required')">
                        {{ trans('ui.ingestion.override_required_note') }}
                    </AppAlert>

                    <FormField
                        :label="trans('ui.ingestion.override_note')"
                        :error="promoteForm.errors.note"
                        required
                    >
                        <TextareaInput v-model="promoteForm.note" :rows="3" />
                    </FormField>
                </template>
            </div>
        </ConfirmDialog>

        <!-- Reject. Destructive in the sense that matters: the proposal stops
             being one, and the row is kept so nobody reviews it twice. -->
        <ConfirmDialog
            :open="rejecting"
            :title="trans('ui.ingestion.reject')"
            :description="trans('ui.ingestion.reject_description')"
            :confirm-label="trans('ui.ingestion.reject_confirm')"
            destructive
            :busy="rejectForm.processing"
            @cancel="rejecting = false"
            @confirm="submitReject"
        >
            <FormField :label="trans('ui.ingestion.reject_reason')" :error="rejectForm.errors.reason" required>
                <TextareaInput v-model="rejectForm.reason" :rows="3" />
            </FormField>
        </ConfirmDialog>

        <!-- Revise. No confirmation: correcting a guess before anybody acts on
             it is the cheapest action here to undo. -->
        <AppModal
            :open="revising"
            :title="trans('ui.ingestion.revise')"
            :description="trans('ui.ingestion.revise_description')"
            @close="revising = false"
        >
            <FormField
                :label="trans('ui.ingestion.suggested_title')"
                :error="reviseForm.errors.suggested_title"
                required
            >
                <TextInput v-model="reviseForm.suggested_title" />
            </FormField>

            <template #footer>
                <AppButton variant="ghost" @click="revising = false">{{ trans('ui.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="reviseForm.processing" @click="submitRevise">
                    {{ trans('ui.actions.save') }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
