<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import FormField from '@/Components/Ui/FormField.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import ToggleInput from '@/Components/Ui/ToggleInput.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
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
const props = defineProps<{ studio: StudioOverview }>();

const states = ['DRAFT', 'QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED'] as const;

const page = usePage<SharedProps>();

const generating = ref(false);
const creatingProject = ref(false);

const generationForm = useForm({
    prompt: '',
    style_prompt: '',
    lyrics: '',
    instrumental: false,
    language_code: '',
});

const projectForm = useForm({
    name: '',
    target_track_count: '',
    default_language: '',
    default_genre: '',
    default_style_prompt: '',
});

/** A refusal from the domain, as its machine-readable reason code. */
const actionError = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.generation ?? null;
});

/**
 * Why generating is unavailable, when it is.
 *
 * Two different failures with two different fixes: no provider is configured
 * at all, or this account may not spend money at one. Collapsing them into
 * "unavailable" would send somebody to the wrong place.
 */
const generationBlockedReason = computed(() => {
    if (props.studio.may_generate) {
        return null;
    }

    return props.studio.provider.available
        ? 'ui.studio.cannot_generate_no_permission'
        : 'ui.studio.cannot_generate_no_provider';
});

function submitGeneration(): void {
    generationForm.post('/studio/generations', {
        preserveScroll: true,
        onSuccess: () => {
            generating.value = false;
        },
    });
}

function submitProject(): void {
    projectForm.post('/studio/projects', {
        preserveScroll: true,
        onSuccess: () => {
            creatingProject.value = false;
        },
    });
}
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

            <!--
                What this supplier can do, said on the screen that asks it to do
                things. Not every provider sings supplied lyrics or returns
                stems, and a request for one that cannot is refused at
                submission -- minutes later, on another page, in a language the
                form gave no warning of.
            -->
            <ul v-if="studio.provider.capabilities.length > 0" class="mt-2 flex flex-wrap gap-1">
                <li
                    v-for="capability in studio.provider.capabilities"
                    :key="capability"
                    class="rounded border border-border px-1.5 py-0.5 text-caption text-muted"
                >
                    {{ trans(`ui.studio.capability.${capability}`) }}
                </li>
            </ul>
        </AppAlert>

        <AppAlert v-if="actionError !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.studio.generation_failure.${actionError}`) }}
        </AppAlert>

        <div class="flex flex-wrap gap-2">
            <AppButton v-if="studio.may_generate" variant="primary" @click="generating = true">
                {{ trans('ui.studio.new_generation') }}
            </AppButton>
            <AppButton v-if="studio.provider.configured" variant="secondary" @click="creatingProject = true">
                {{ trans('ui.studio.new_project') }}
            </AppButton>
        </div>

        <!-- Said out loud rather than by a missing button. Somebody looking for
             the generate button deserves to know which of the two reasons
             applies. -->
        <p v-if="generationBlockedReason !== null" class="text-caption text-muted">
            {{ trans(generationBlockedReason) }}
        </p>

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

        <!-- Sending a request costs the operator money at a supplier, so the
             description says so before the button does anything. -->
        <AppModal
            :open="generating"
            :title="trans('ui.studio.new_generation')"
            :description="trans('ui.studio.new_generation_description')"
            @close="generating = false"
        >
            <div class="space-y-3">
                <FormField :label="trans('ui.studio.prompt')" :error="generationForm.errors.prompt" required>
                    <TextareaInput v-model="generationForm.prompt" :rows="3" />
                </FormField>
                <FormField :label="trans('ui.studio.style_prompt')" :error="generationForm.errors.style_prompt">
                    <TextInput v-model="generationForm.style_prompt" />
                </FormField>
                <FormField :label="trans('ui.studio.instrumental')">
                    <ToggleInput v-model="generationForm.instrumental" :label="trans('ui.studio.instrumental')" />
                </FormField>
                <FormField
                    v-if="!generationForm.instrumental"
                    :label="trans('ui.studio.lyrics')"
                    :error="generationForm.errors.lyrics"
                >
                    <TextareaInput v-model="generationForm.lyrics" :rows="4" />
                </FormField>
                <FormField
                    v-if="!generationForm.instrumental"
                    :label="trans('ui.catalog.column.language')"
                    :error="generationForm.errors.language_code"
                >
                    <TextInput v-model="generationForm.language_code" />
                </FormField>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="generating = false">{{ trans('ui.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="generationForm.processing" @click="submitGeneration">
                    {{ trans('ui.studio.start_generation') }}
                </AppButton>
            </template>
        </AppModal>

        <AppModal
            :open="creatingProject"
            :title="trans('ui.studio.new_project')"
            :description="trans('ui.studio.new_project_description')"
            @close="creatingProject = false"
        >
            <div class="space-y-3">
                <FormField :label="trans('ui.studio.project_name')" :error="projectForm.errors.name" required>
                    <TextInput v-model="projectForm.name" />
                </FormField>
                <FormField
                    :label="trans('ui.studio.target')"
                    :error="projectForm.errors.target_track_count"
                    :hint="trans('ui.studio.no_target')"
                >
                    <TextInput v-model="projectForm.target_track_count" type="number" />
                </FormField>
                <FormField :label="trans('ui.studio.default_style')" :error="projectForm.errors.default_style_prompt">
                    <TextInput v-model="projectForm.default_style_prompt" />
                </FormField>
                <FormField :label="trans('ui.studio.default_language')" :error="projectForm.errors.default_language">
                    <TextInput v-model="projectForm.default_language" />
                </FormField>
                <FormField :label="trans('ui.studio.default_genre')" :error="projectForm.errors.default_genre">
                    <TextInput v-model="projectForm.default_genre" />
                </FormField>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="creatingProject = false">
                    {{ trans('ui.actions.cancel') }}
                </AppButton>
                <AppButton variant="primary" :loading="projectForm.processing" @click="submitProject">
                    {{ trans('ui.studio.create_project') }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
