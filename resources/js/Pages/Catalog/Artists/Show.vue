<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { ArtistDetail } from '@/Types/catalog';

/**
 * One artist.
 *
 * The track list is capped server-side. The cap is stated rather than left to
 * be inferred: a page showing fifty of nine hundred with no note reads as an
 * artist with fifty tracks.
 */
defineProps<{ artist: ArtistDetail }>();
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link href="/catalog/artists" class="hover:text-foreground">{{ trans('ui.catalog.artists') }}</Link>
            </p>
            <h1 class="mt-1 text-page-title text-foreground">{{ artist.name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <StatusBadge :status="artist.status" group="generic" />
                <span class="text-caption text-muted">{{ trans(`ui.catalog.artist_type.${artist.type}`) }}</span>
                <span v-if="artist.is_owner_controlled" class="text-caption text-success">
                    {{ trans('ui.catalog.owner_controlled') }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.catalog.metadata') }}</template>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                    <dt class="text-muted">{{ trans('ui.catalog.sort_name') }}</dt>
                    <dd class="text-foreground">{{ artist.sort_name ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.country') }}</dt>
                    <dd class="text-foreground">{{ artist.country ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.primary_locale') }}</dt>
                    <dd class="text-foreground">{{ artist.primary_locale }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.tracks') }}</dt>
                    <dd class="numeric text-foreground">{{ artist.track_count }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.releases') }}</dt>
                    <dd class="numeric text-foreground">{{ artist.release_count }}</dd>
                </dl>
                <p v-if="artist.biography" class="mt-3 border-t border-border pt-3 text-small text-foreground">
                    {{ artist.biography }}
                </p>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.identifiers') }}</template>
                <EmptyState
                    v-if="artist.identifiers.length === 0"
                    :title="trans('ui.catalog.no_identifiers')"
                />
                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="identifier in artist.identifiers"
                        :key="identifier.uuid"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <div class="min-w-0">
                            <p class="text-caption text-muted">{{ identifier.type }}</p>
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
            <template #header>{{ trans('ui.catalog.tracks') }}</template>
            <EmptyState v-if="artist.tracks.length === 0" :title="trans('ui.catalog.no_tracks_for_artist')" />
            <template v-else>
                <ul class="divide-y divide-border">
                    <li
                        v-for="track in artist.tracks"
                        :key="track.uuid"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <Link :href="`/catalog/tracks/${track.uuid}`" class="min-w-0 truncate text-foreground hover:text-accent">
                            {{ track.title }}
                        </Link>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-caption text-muted">{{ trans(`ui.catalog.artist_role.${track.role}`) }}</span>
                            <StatusBadge :status="track.status" group="generic" />
                        </div>
                    </li>
                </ul>
                <p v-if="artist.tracks_truncated" class="pt-3 text-caption text-muted">
                    {{ trans('ui.catalog.tracks_truncated', { shown: artist.tracks_shown, total: artist.track_count }) }}
                </p>
            </template>
        </AppCard>
    </div>
</template>
