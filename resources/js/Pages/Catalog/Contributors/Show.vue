<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { ContributorDetail } from '@/Types/catalog';

/**
 * One contributor.
 *
 * The legal name is shown under its own label rather than merged into the
 * display name. They are different facts, and merging them is how a legal name
 * gets published by somebody who thought it was a stage name.
 *
 * Composition shares are labelled as *credits*. They are catalogue evidence of
 * who wrote what, not ownership and not a royalty entitlement — that domain
 * does not exist yet, and showing a credit as an entitlement would invent one.
 */
defineProps<{ contributor: ContributorDetail }>();
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link href="/catalog/contributors" class="hover:text-foreground">
                    {{ trans('ui.catalog.contributors') }}
                </Link>
            </p>
            <h1 class="mt-1 text-page-title text-foreground">{{ contributor.name }}</h1>
            <p class="mt-1 text-caption text-muted">
                {{ trans(`ui.catalog.contributor_type.${contributor.type}`) }}
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.catalog.metadata') }}</template>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                    <dt class="text-muted">{{ trans('ui.catalog.legal_name') }}</dt>
                    <dd class="text-foreground">{{ contributor.legal_name }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.display_name') }}</dt>
                    <dd class="text-foreground">{{ contributor.display_name ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.country') }}</dt>
                    <dd class="text-foreground">{{ contributor.country ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.compositions') }}</dt>
                    <dd class="numeric text-foreground">{{ contributor.composition_count }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.tracks') }}</dt>
                    <dd class="numeric text-foreground">{{ contributor.track_count }}</dd>
                </dl>
                <p v-if="contributor.notes" class="mt-3 border-t border-border pt-3 text-small text-foreground">
                    {{ contributor.notes }}
                </p>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.identifiers') }}</template>
                <EmptyState
                    v-if="contributor.identifiers.length === 0"
                    :title="trans('ui.catalog.no_identifiers')"
                />
                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="identifier in contributor.identifiers"
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
            <template #header>{{ trans('ui.catalog.composition_credits') }}</template>
            <template #description>{{ trans('ui.catalog.credit_not_ownership') }}</template>
            <EmptyState v-if="contributor.compositions.length === 0" :title="trans('ui.catalog.no_credits')" />
            <template v-else>
                <ul class="divide-y divide-border">
                    <li
                        v-for="credit in contributor.compositions"
                        :key="credit.uuid"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <Link
                            :href="`/catalog/compositions/${credit.uuid}`"
                            class="min-w-0 truncate text-foreground hover:text-accent"
                        >
                            {{ credit.title }}
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
                <p v-if="contributor.compositions_truncated" class="pt-3 text-caption text-muted">
                    {{ trans('ui.catalog.credits_truncated', {
                        shown: contributor.compositions_shown,
                        total: contributor.composition_count,
                    }) }}
                </p>
            </template>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.catalog.track_credits') }}</template>
            <EmptyState v-if="contributor.tracks.length === 0" :title="trans('ui.catalog.no_credits')" />
            <template v-else>
                <ul class="divide-y divide-border">
                    <li
                        v-for="credit in contributor.tracks"
                        :key="credit.uuid"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0"
                    >
                        <Link
                            :href="`/catalog/tracks/${credit.uuid}`"
                            class="min-w-0 truncate text-foreground hover:text-accent"
                        >
                            {{ credit.title }}
                        </Link>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-caption text-muted">
                                {{ trans(`ui.catalog.contributor_role.${credit.role}`) }}
                            </span>
                            <StatusBadge :status="credit.status" group="generic" />
                        </div>
                    </li>
                </ul>
                <p v-if="contributor.tracks_truncated" class="pt-3 text-caption text-muted">
                    {{ trans('ui.catalog.credits_truncated', {
                        shown: contributor.tracks_shown,
                        total: contributor.track_count,
                    }) }}
                </p>
            </template>
        </AppCard>
    </div>
</template>
