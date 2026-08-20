<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import IdentifierEditor from '@/Components/Catalog/IdentifierEditor.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ResourcePicker from '@/Components/Ui/ResourcePicker.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { bitrate, bytes, dateTime, duration, sampleRate } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { TrackDetail } from '@/Types/catalog';

/**
 * One track, in full.
 *
 * Everything shown here comes from `TrackDetailQuery`, which has already
 * removed the disk, the path, the original filename and the analyser's failure
 * message — the last because it quotes whatever the tool said, and on a failed
 * read that is a filesystem path.
 *
 * There is no player and no download link. A master is the asset this platform
 * exists to protect; handing the browser a URL to it is a decision for the
 * asset screen, with a signed and expiring URL, not a convenience to add here.
 */
const props = defineProps<{ track: TrackDetail }>();

/**
 * The refusal, if the last action was refused.
 *
 * A code, translated here. The domain's English is written for a log, and a
 * screen that rendered it would be a screen in one language on a platform that
 * ships six.
 */
const page = usePage();

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.track ?? null;
});

/** CAT-005. The identifier editor's own refusal, kept apart from the track's. */
const identifierRefusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.identifier ?? null;
});

/* ---------------------------------------------------------------- credits */

const roleOptions = ['PRIMARY', 'FEATURED', 'REMIXER', 'WITH'].map((value) => ({
    value,
    label: trans(`ui.catalog.artist_role.${value}`),
}));

/**
 * The credit list, edited locally and saved whole.
 *
 * `SetTrackCredits` replaces the list rather than patching it, because the
 * order *is* the credit — "A feat. B" and "B feat. A" are different records —
 * and a per-row endpoint cannot express a reordering without a moment where
 * the recording is credited to nobody.
 */
const credits = reactive([...props.track.artists]);

watch(
    () => props.track.artists,
    (artists) => {
        credits.splice(0, credits.length, ...artists);
    },
);

const artistPicker = ref(false);
const savingCredits = ref(false);

const hasPrimary = computed(() => credits.some((credit) => credit.role === 'PRIMARY'));

function addCredit(row: Record<string, unknown>): void {
    const uuid = String(row.uuid ?? '');

    if (uuid === '' || credits.some((credit) => credit.uuid === uuid)) {
        return;
    }

    credits.push({
        uuid,
        name: String(row.name ?? ''),
        // The first person credited is a primary by default: it is the
        // overwhelmingly common case, and the service refuses a list without
        // one anyway, so any other default would only teach people to fix it
        // every time.
        role: credits.length === 0 ? 'PRIMARY' : 'FEATURED',
    });

    artistPicker.value = false;
}

function removeCredit(uuid: string): void {
    const index = credits.findIndex((credit) => credit.uuid === uuid);

    if (index !== -1) {
        credits.splice(index, 1);
    }
}

function moveCredit(index: number, delta: number): void {
    const target = index + delta;

    if (target < 0 || target >= credits.length) {
        return;
    }

    const [moved] = credits.splice(index, 1);

    if (moved !== undefined) {
        credits.splice(target, 0, moved);
    }
}

function saveCredits(): void {
    savingCredits.value = true;

    router.post(
        `/catalog/tracks/${props.track.uuid}/credits`,
        { artists: credits.map((credit) => ({ uuid: credit.uuid, role: credit.role })) },
        {
            preserveScroll: true,
            onFinish: () => {
                savingCredits.value = false;
            },
        },
    );
}

/* -------------------------------------------------------------- readiness */

const markingReady = ref(false);

function markReady(): void {
    markingReady.value = true;

    router.post(
        `/catalog/tracks/${props.track.uuid}/ready`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                markingReady.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link href="/catalog/tracks" class="hover:text-foreground">{{ trans('ui.catalog.tracks') }}</Link>
            </p>
            <h1 class="mt-1 text-page-title text-foreground">
                {{ track.title }}
                <span v-if="track.version_title" class="text-muted">({{ track.version_title }})</span>
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <StatusBadge :status="track.status" group="generic" />
                <span v-if="track.is_instrumental" class="text-caption text-muted">{{ trans('ui.catalog.instrumental') }}</span>
                <span v-if="track.is_explicit" class="text-caption text-danger">{{ trans('ui.catalog.explicit') }}</span>
            </div>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.catalog.failure.${refusal}`) }}
        </AppAlert>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.catalog.metadata') }}</template>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                    <dt class="text-muted">{{ trans('ui.catalog.column.language') }}</dt>
                    <dd class="text-foreground">{{ track.language_code }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.duration') }}</dt>
                    <dd class="numeric text-foreground">{{ duration(track.duration_ms) }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.source_label') }}</dt>
                    <dd class="text-foreground">{{ trans(`ui.catalog.source.${track.source}`) }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.bpm') }}</dt>
                    <dd class="numeric text-foreground">{{ track.bpm ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.musical_key') }}</dt>
                    <dd class="text-foreground">{{ track.musical_key ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.genre') }}</dt>
                    <dd class="text-foreground">{{ track.genre_primary ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.recording_year') }}</dt>
                    <dd class="numeric text-foreground">{{ track.recording_year ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.p_line') }}</dt>
                    <dd class="text-foreground">{{ track.p_line ?? '—' }}</dd>
                </dl>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.people') }}</template>

                <p class="mb-3 text-caption text-muted">{{ trans('ui.catalog.credits_description') }}</p>

                <EmptyState
                    v-if="credits.length === 0 && track.contributors.length === 0"
                    :title="trans('ui.catalog.no_people')"
                />
                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="(credit, index) in credits"
                        :key="`a-${credit.uuid}`"
                        class="flex flex-wrap items-center gap-3 py-2 first:pt-0"
                    >
                        <span class="grow text-small text-foreground">{{ credit.name }}</span>
                        <SelectInput
                            v-model="credit.role"
                            :options="roleOptions"
                            :disabled="!track.actions.can_edit_credits"
                        />
                        <template v-if="track.actions.can_edit_credits">
                            <AppButton variant="ghost" :disabled="index === 0" @click="moveCredit(index, -1)">
                                {{ trans('ui.catalog.move_up') }}
                            </AppButton>
                            <AppButton
                                variant="ghost"
                                :disabled="index === credits.length - 1"
                                @click="moveCredit(index, 1)"
                            >
                                {{ trans('ui.catalog.move_down') }}
                            </AppButton>
                            <AppButton variant="ghost" @click="removeCredit(credit.uuid)">
                                {{ trans('ui.actions.delete') }}
                            </AppButton>
                        </template>
                    </li>
                    <li v-for="person in track.contributors" :key="`c-${person.uuid}`" class="flex justify-between py-2">
                        <span class="text-foreground">{{ person.name }}</span>
                        <span class="text-caption text-muted">{{ trans(`ui.catalog.contributor_role.${person.role}`) }}</span>
                    </li>
                </ul>

                <!-- The service refuses a list with no primary. Saying so
                     before the request is sent is kinder than a refusal; it is
                     not a substitute for one, and the server checks anyway. -->
                <AppAlert
                    v-if="track.actions.can_edit_credits && credits.length > 0 && !hasPrimary"
                    tone="warning"
                    class="mt-3"
                    :title="trans('ui.issue.TRACK_PRIMARY_ARTIST_REQUIRED')"
                />

                <div v-if="track.actions.can_edit_credits" class="mt-3 flex flex-wrap gap-2">
                    <AppButton variant="secondary" @click="artistPicker = true">
                        {{ trans('ui.catalog.add_artist') }}
                    </AppButton>
                    <AppButton variant="primary" :loading="savingCredits" @click="saveCredits">
                        {{ trans('ui.catalog.save_credits') }}
                    </AppButton>
                </div>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.master') }}</template>
                <EmptyState
                    v-if="track.master === null"
                    :title="trans('ui.catalog.no_master')"
                    :description="trans('ui.catalog.no_master_description')"
                />
                <template v-else>
                    <div class="flex items-center justify-between pb-2">
                        <StatusBadge :status="track.master.status" group="generic" />
                        <span v-if="track.master.is_duplicate" class="text-caption text-warning">
                            {{ trans('ui.catalog.duplicate') }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 border-t border-border pt-2 text-small">
                        <dt class="text-muted">{{ trans('ui.catalog.format') }}</dt>
                        <dd class="text-foreground">{{ track.master.mime_type }}</dd>
                        <dt class="text-muted">{{ trans('ui.catalog.size') }}</dt>
                        <dd class="numeric text-foreground">{{ bytes(track.master.byte_size) }}</dd>
                        <dt class="text-muted">{{ trans('ui.catalog.sample_rate') }}</dt>
                        <dd class="numeric text-foreground">{{ sampleRate(track.master.sample_rate) }}</dd>
                        <dt class="text-muted">{{ trans('ui.catalog.channels') }}</dt>
                        <dd class="numeric text-foreground">{{ track.master.channels ?? '—' }}</dd>
                        <dt class="text-muted">{{ trans('ui.catalog.checksum') }}</dt>
                        <dd><CodeValue :value="track.master.checksum_short" /></dd>
                        <dt class="text-muted">{{ trans('ui.catalog.verified_at') }}</dt>
                        <dd class="text-foreground">{{ track.master.verified_at ? dateTime(track.master.verified_at, 'en') : '—' }}</dd>
                    </dl>

                    <div v-if="track.master.analysis" class="mt-3 border-t border-border pt-2">
                        <p class="text-caption text-muted">{{ trans('ui.catalog.analysis') }}</p>
                        <p v-if="track.master.analysis.failed" class="mt-1 text-small text-danger">
                            {{ trans('ui.catalog.analysis_failed') }}
                        </p>
                        <dl v-else class="mt-1 grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                            <dt class="text-muted">{{ trans('ui.catalog.codec') }}</dt>
                            <dd class="text-foreground">{{ track.master.analysis.codec ?? '—' }}</dd>
                            <dt class="text-muted">{{ trans('ui.catalog.bitrate') }}</dt>
                            <dd class="numeric text-foreground">{{ bitrate(track.master.analysis.bitrate) }}</dd>
                            <dt class="text-muted">{{ trans('ui.catalog.loudness') }}</dt>
                            <dd class="numeric text-foreground">
                                {{ track.master.analysis.loudness_lufs === null ? '—' : `${track.master.analysis.loudness_lufs} LUFS` }}
                            </dd>
                        </dl>
                    </div>
                </template>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.identifiers') }}</template>
                <!-- CAT-005. Read-only until this ticket, which meant an ISRC
                     — the number a store and a royalty statement both key on —
                     could only be put in through a service with no caller. -->
                <IdentifierEditor
                    :identifiers="track.identifiers"
                    :assignable-types="track.assignable_identifier_types"
                    :endpoint="`/catalog/tracks/${track.uuid}`"
                    :can-assign="track.actions.can_assign_identifier"
                    :refusal="identifierRefusal"
                />
            </AppCard>
        </div>

        <AppCard>
            <template #header>{{ trans('ui.catalog.releases') }}</template>
            <EmptyState v-if="track.releases.length === 0" :title="trans('ui.catalog.no_releases')" />
            <ul v-else class="divide-y divide-border">
                <li v-for="release in track.releases" :key="release.uuid" class="flex items-center justify-between gap-3 py-2 first:pt-0">
                    <div class="min-w-0">
                        <p class="truncate text-foreground">{{ release.title }}</p>
                        <p class="text-caption text-muted">
                            {{ trans('ui.catalog.disc') }} {{ release.disc_number ?? '—' }} ·
                            {{ trans('ui.catalog.track_number') }} {{ release.track_number ?? '—' }}
                        </p>
                    </div>
                    <StatusBadge :status="release.status" group="generic" />
                </li>
            </ul>
        </AppCard>

        <!-- Readiness. A track is READY because it passed I3, never because
             somebody set a field: the button is only offered when nothing is
             outstanding, and the server re-runs the invariants regardless. -->
        <AppCard v-if="track.actions.may_edit">
            <template #header>{{ trans('ui.catalog.readiness') }}</template>

            <p class="text-caption text-muted">{{ trans('ui.catalog.readiness_description') }}</p>

            <ul v-if="track.readiness_problems.length > 0" class="mt-3 space-y-1">
                <li
                    v-for="problem in track.readiness_problems"
                    :key="`${problem.code}@${problem.path}`"
                    class="text-small text-danger"
                >
                    {{ trans(`ui.issue.${problem.code}`, problem.context) }}
                </li>
            </ul>

            <p v-else-if="track.status === 'DRAFT'" class="mt-3 text-small text-success">
                {{ trans('ui.catalog.readiness_clear') }}
            </p>

            <div v-if="track.actions.can_mark_ready" class="mt-3">
                <AppButton variant="primary" :loading="markingReady" @click="markReady">
                    {{ trans('ui.catalog.mark_ready') }}
                </AppButton>
            </div>
        </AppCard>

        <AppModal
            :open="artistPicker"
            :title="trans('ui.catalog.add_artist')"
            @close="artistPicker = false"
        >
            <ResourcePicker
                endpoint="/releases/options/artists"
                :label="trans('ui.catalog.people')"
                @select="addCredit"
            >
                <template #row="{ row }">{{ row.name }}</template>
            </ResourcePicker>
        </AppModal>
    </div>
</template>
