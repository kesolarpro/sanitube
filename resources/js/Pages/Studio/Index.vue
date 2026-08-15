<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import { trans } from '@/Support/i18n';
import type { StudioOverview } from '@/Types/studio';

/**
 * What the studio can do, and what it has done.
 *
 * The provider banner is the most important thing on the page. Most installs
 * have no generation provider — SaniTube runs entirely without one — and the
 * honest thing to say is so, plainly, rather than showing a studio that looks
 * ready and fails on use.
 *
 * The three states are genuinely different and read differently: **not
 * configured** is not a problem, **configured but unavailable** is a problem
 * somebody can fix, and **available** will accept work.
 */
defineProps<{ studio: StudioOverview }>();

const states = ['DRAFT', 'QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED'] as const;
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.studio.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.studio.description') }}</p>
        </div>

        <AppAlert
            v-if="!studio.provider.configured"
            tone="info"
            :title="trans('ui.studio.not_configured')"
        >
            {{ trans('ui.studio.not_configured_note') }}
        </AppAlert>

        <AppAlert
            v-else-if="!studio.provider.available"
            tone="warning"
            :title="trans('ui.studio.unavailable')"
        >
            {{ trans('ui.studio.unavailable_note') }}
        </AppAlert>

        <AppAlert v-else tone="success" :title="trans('ui.studio.available')">
            {{ trans('ui.studio.provider') }}: {{ studio.provider.name }}
        </AppAlert>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <MetricCard
                :label="trans('ui.studio.projects')"
                :value="studio.projects"
                href="/studio/projects"
            />
            <MetricCard
                :label="trans('ui.studio.generations')"
                :value="studio.generations.total"
                href="/studio/generations"
            />
            <MetricCard
                v-for="state in states"
                :key="state"
                :label="trans(`ui.status.generic.${state}`)"
                :value="studio.generations[state]"
            />
        </div>

        <AppCard>
            <template #header>{{ trans('ui.studio.commercial_rights') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.studio.commercial_rights_note') }}</p>

            <!-- UNKNOWN first and on its own, because it is the default and it
                 stays the default: nothing infers a rights answer from the
                 fact that audio arrived. A number here is a count of
                 recordings nobody has established may be sold. -->
            <AppAlert
                v-if="studio.rights.UNKNOWN > 0"
                tone="warning"
                :title="trans('ui.studio.rights_unknown_warning')"
            >
                {{ studio.rights.UNKNOWN }}
            </AppAlert>

            <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="(count, state) in studio.rights" :key="state">
                    <dt class="text-caption text-muted">{{ trans(`ui.status.generic.${state}`) }}</dt>
                    <dd class="text-small text-foreground">{{ count }}</dd>
                </div>
            </dl>
        </AppCard>

        <AppCard>
            <template #header>{{ trans('ui.studio.projects') }}</template>
            <p class="text-small text-muted">{{ trans('ui.studio.projects_description') }}</p>
            <p class="mt-3">
                <Link href="/studio/projects" class="text-small text-accent hover:underline">
                    {{ trans('ui.studio.projects') }}
                </Link>
            </p>
        </AppCard>
    </div>
</template>
