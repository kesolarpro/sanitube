<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { CompositionDetail } from '@/Types/catalog';

/**
 * One composition — the work, as distinct from the recordings of it.
 *
 * Contributor shares here are **credits**, labelled as such. They record who
 * wrote what; they are not ownership and not a royalty entitlement. That domain
 * is separate and does not exist yet, and showing a credit as an entitlement
 * would be inventing a financial fact.
 */
defineProps<{ composition: CompositionDetail }>();
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link href="/catalog/compositions" class="hover:text-foreground">
                    {{ trans('ui.catalog.compositions') }}
                </Link>
            </p>
            <h1 class="mt-1 text-page-title text-foreground">{{ composition.title }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <StatusBadge :status="composition.status" group="generic" />
                <span v-if="composition.is_public_domain" class="text-caption text-muted">
                    {{ trans('ui.catalog.public_domain') }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.catalog.metadata') }}</template>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                    <dt class="text-muted">{{ trans('ui.catalog.alternative_title') }}</dt>
                    <dd class="text-foreground">{{ composition.alternative_title ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.language') }}</dt>
                    <dd class="text-foreground">{{ composition.language_code }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.created_year') }}</dt>
                    <dd class="numeric text-foreground">{{ composition.created_year ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.recordings') }}</dt>
                    <dd class="numeric text-foreground">{{ composition.track_count }}</dd>
                </dl>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.identifiers') }}</template>
                <EmptyState
                    v-if="composition.identifiers.length === 0"
                    :title="trans('ui.catalog.no_identifiers')"
                    :description="trans('ui.catalog.no_iswc_description')"
                />
                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="identifier in composition.identifiers"
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
            <template #header>{{ trans('ui.catalog.writers') }}</template>
            <template #description>{{ trans('ui.catalog.credit_not_ownership') }}</template>
            <EmptyState v-if="composition.contributors.length === 0" :title="trans('ui.catalog.no_writers')" />
            <template v-else>
                <ul class="divide-y divide-border">
                    <li
                        v-for="credit in composition.contributors"
                        :key="credit.uuid"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <Link
                            :href="`/catalog/contributors/${credit.uuid}`"
                            class="min-w-0 truncate text-foreground hover:text-accent"
                        >
                            {{ credit.name }}
                        </Link>
                        <div class="flex shrink-0 items-center gap-3">
                            <span class="text-caption text-muted">
                                {{ trans(`ui.catalog.composition_role.${credit.role}`) }}
                            </span>
                            <span class="numeric text-caption text-muted">
                                {{ credit.credited_share === null ? '—' : `${credit.credited_share}%` }}
                            </span>
                        </div>
                    </li>
                </ul>
                <p v-if="composition.contributors_truncated" class="pt-3 text-caption text-muted">
                    {{ trans('ui.catalog.credits_truncated', {
                        shown: composition.contributors_shown,
                        total: composition.contributor_count,
                    }) }}
                </p>
            </template>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.catalog.recordings') }}</template>
            <EmptyState v-if="composition.recordings.length === 0" :title="trans('ui.catalog.no_recordings')" />
            <template v-else>
                <ul class="divide-y divide-border">
                    <li
                        v-for="track in composition.recordings"
                        :key="track.uuid"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <Link
                            :href="`/catalog/tracks/${track.uuid}`"
                            class="min-w-0 truncate text-foreground hover:text-accent"
                        >
                            {{ track.title }}
                            <span v-if="track.version_title" class="text-muted">({{ track.version_title }})</span>
                        </Link>
                        <StatusBadge :status="track.status" group="generic" />
                    </li>
                </ul>
                <p v-if="composition.recordings_truncated" class="pt-3 text-caption text-muted">
                    {{ trans('ui.catalog.credits_truncated', {
                        shown: composition.recordings_shown,
                        total: composition.track_count,
                    }) }}
                </p>
            </template>
        </AppCard>
    </div>
</template>
