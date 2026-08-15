<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
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
defineProps<{ track: TrackDetail }>();
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
                <EmptyState
                    v-if="track.artists.length === 0 && track.contributors.length === 0"
                    :title="trans('ui.catalog.no_people')"
                />
                <ul v-else class="divide-y divide-border">
                    <li v-for="artist in track.artists" :key="`a-${artist.uuid}`" class="flex justify-between py-2 first:pt-0">
                        <span class="text-foreground">{{ artist.name }}</span>
                        <span class="text-caption text-muted">{{ trans(`ui.catalog.artist_role.${artist.role}`) }}</span>
                    </li>
                    <li v-for="person in track.contributors" :key="`c-${person.uuid}`" class="flex justify-between py-2">
                        <span class="text-foreground">{{ person.name }}</span>
                        <span class="text-caption text-muted">{{ trans(`ui.catalog.contributor_role.${person.role}`) }}</span>
                    </li>
                </ul>
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
                <EmptyState
                    v-if="track.identifiers.length === 0"
                    :title="trans('ui.catalog.no_identifiers')"
                    :description="trans('ui.catalog.no_identifiers_description')"
                />
                <ul v-else class="divide-y divide-border">
                    <li v-for="identifier in track.identifiers" :key="identifier.uuid" class="flex items-center justify-between gap-3 py-2 first:pt-0">
                        <div class="min-w-0">
                            <p class="text-caption text-muted">
                                {{ identifier.type }}<template v-if="identifier.namespace"> · {{ identifier.namespace }}</template>
                            </p>
                            <CodeValue :value="identifier.value" />
                        </div>
                        <span v-if="identifier.is_authoritative" class="shrink-0 text-caption text-success">
                            {{ trans('ui.catalog.authoritative') }}
                        </span>
                    </li>
                </ul>
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
    </div>
</template>
