<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
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
 * This screen takes no action. Promoting and rejecting are writes to the
 * catalogue and are not here.
 */
const props = defineProps<{ candidate: CandidateDetail }>();

const locale = usePage<SharedProps>().props.app.locale;

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
    </div>
</template>
