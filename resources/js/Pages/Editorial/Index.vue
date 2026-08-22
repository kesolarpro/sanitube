<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import ResourcePicker from '@/Components/Ui/ResourcePicker.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import ToggleInput from '@/Components/Ui/ToggleInput.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { EditorialProfileRow, EditorialProfileView } from '@/Types/production';

/**
 * The imprints this installation writes in the manner of.
 *
 * PROD-002. `WriteEditorialProfile` had no caller anywhere in the product — no
 * controller, no console command, no seeder — so a profile came into being
 * through a database client or not at all. A production plan requires one,
 * which meant the planner could not be started from inside the product either:
 * a working installation had a production screen that could only ever be empty.
 *
 * **A profile is preferences, never constraints.** Nothing here refuses
 * anything. An editor who wants a track outside the palette makes one, and this
 * is what a suggestion or a plan starts from rather than what either is limited
 * to. The screen says so, because a page full of "preferred" and "avoided"
 * reads like a rule set otherwise.
 *
 * **The slug is shown and never editable.** It is frozen at creation because it
 * is how a plan and a console command name a profile: one that followed a
 * rename would turn "rename this imprint" into "orphan everything that
 * mentioned it".
 *
 * **There is no delete.** A profile is referenced by every plan pointed at it,
 * under a foreign key that refuses the deletion — so a delete button would work
 * only for profiles nothing has ever used and fail for exactly the ones
 * somebody wants gone. Retiring is the operation, and a retired profile keeps
 * the plans it already has while accepting no new ones.
 */
const props = defineProps<{ profiles: EditorialProfileView }>();

const page = usePage<SharedProps>();

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.editorial ?? null;
});

/** The four palettes, named once so the form and the table agree. */
const LISTS = ['preferred_genres', 'preferred_moods', 'preferred_themes', 'avoided_terms'] as const;

type ListName = (typeof LISTS)[number];

const editing = ref<EditorialProfileRow | null>(null);
const creating = ref(false);
const artistPicker = ref(false);

const form = useForm({
    name: '',
    summary: '',
    default_artist: '' as string | null,
    default_language: '',
    preferred_genres: [] as string[],
    preferred_moods: [] as string[],
    preferred_themes: [] as string[],
    avoided_terms: [] as string[],
    title_guidance: '',
    description_guidance: '',
    is_active: true,
});

/**
 * The palettes are edited as one comma-separated line each.
 *
 * A tag widget would be nicer and is not what this needs: these are short
 * lists an operator writes once and adjusts rarely, and a line of text is
 * something they can paste from wherever they already keep it.
 */
const lines = reactive<Record<ListName, string>>({
    preferred_genres: '',
    preferred_moods: '',
    preferred_themes: '',
    avoided_terms: '',
});

/** The name shown beside the picker, which the picker itself does not carry. */
const artistName = ref<string | null>(null);

function terms(line: string): string[] {
    return line
        .split(',')
        .map((term) => term.trim())
        .filter((term) => term !== '');
}

function open(profile: EditorialProfileRow | null): void {
    editing.value = profile;
    creating.value = profile === null;
    form.clearErrors();

    form.name = profile?.name ?? '';
    form.summary = profile?.summary ?? '';
    form.default_language = profile?.default_language ?? '';
    form.title_guidance = profile?.title_guidance ?? '';
    form.description_guidance = profile?.description_guidance ?? '';
    form.is_active = profile?.is_active ?? true;

    // Left absent rather than blank on open. An empty string means "clear the
    // usual credit", and reopening a form should not be a way to do that.
    form.default_artist = null;
    artistName.value = profile?.default_artist ?? null;

    for (const list of LISTS) {
        lines[list] = (profile?.[list] ?? []).join(', ');
    }
}

function chooseArtist(row: Record<string, unknown>): void {
    form.default_artist = typeof row.uuid === 'string' ? row.uuid : null;
    artistName.value = typeof row.name === 'string' ? row.name : null;
    artistPicker.value = false;
}

function clearArtist(): void {
    form.default_artist = '';
    artistName.value = null;
}

function submit(): void {
    for (const list of LISTS) {
        form[list] = terms(lines[list]);
    }

    const done = {
        preserveScroll: true,
        onSuccess: (): void => {
            editing.value = null;
            creating.value = false;
        },
    };

    const target = editing.value;

    if (target === null) {
        form.post('/editorial/profiles', done);

        return;
    }

    form.patch(`/editorial/profiles/${target.uuid}`, done);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-page-title text-foreground">{{ trans('ui.editorial.title') }}</h1>
                <p class="mt-1 text-small text-muted">{{ trans('ui.editorial.description') }}</p>
            </div>
            <AppButton variant="primary" @click="open(null)">{{ trans('ui.editorial.add') }}</AppButton>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.states.error_title')">
            {{ trans(`ui.editorial.failure.${refusal}`) }}
        </AppAlert>

        <EmptyState
            v-if="profiles.rows.length === 0"
            :title="trans('ui.editorial.empty_title')"
            :description="trans('ui.editorial.empty_description')"
        />

        <AppCard v-for="profile in profiles.rows" v-else :key="profile.uuid">
            <template #header>
                <div class="flex flex-wrap items-center gap-2">
                    <span>{{ profile.name }}</span>
                    <!-- Not a StatusBadge: that component renders one of the
                         platform's status vocabularies, and "active" here is a
                         property of an imprint rather than a state machine. -->
                    <span
                        class="text-caption"
                        :class="profile.is_active ? 'text-success' : 'text-muted'"
                    >
                        {{ profile.is_active ? trans('ui.editorial.active') : trans('ui.editorial.retired') }}
                    </span>
                    <CodeValue :value="profile.slug" />
                </div>
            </template>

            <p v-if="profile.summary !== null" class="text-small text-foreground">{{ profile.summary }}</p>

            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.editorial.default_language') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ profile.default_language ?? trans('ui.editorial.none_set') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.editorial.default_artist') }}</dt>
                    <dd class="text-small text-foreground">
                        {{ profile.default_artist ?? trans('ui.editorial.none_set') }}
                    </dd>
                </div>
                <div v-for="list in LISTS" :key="list">
                    <dt class="text-caption text-muted">{{ trans(`ui.editorial.${list}`) }}</dt>
                    <dd class="text-small text-foreground">
                        {{ profile[list].length > 0 ? profile[list].join(', ') : trans('ui.editorial.none_set') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.editorial.plans') }}</dt>
                    <dd class="text-small text-foreground">{{ profile.plans }}</dd>
                </div>
            </dl>

            <div class="mt-4 flex justify-end">
                <AppButton variant="secondary" @click="open(profile)">{{ trans('ui.editorial.edit') }}</AppButton>
            </div>
        </AppCard>

        <p class="text-caption text-muted">{{ trans('ui.editorial.no_delete') }}</p>

        <AppModal
            :open="editing !== null || creating"
            :title="creating ? trans('ui.editorial.add') : trans('ui.editorial.edit')"
            :description="trans('ui.editorial.preferences_not_rules')"
            @close="
                editing = null;
                creating = false;
            "
        >
            <div class="space-y-3">
                <FormField :label="trans('ui.editorial.name')" :error="form.errors.name" required>
                    <TextInput v-model="form.name" autocomplete="off" />
                </FormField>

                <FormField :label="trans('ui.editorial.summary')" :error="form.errors.summary">
                    <TextareaInput v-model="form.summary" :rows="2" />
                </FormField>

                <FormField
                    :label="trans('ui.editorial.default_language')"
                    :error="form.errors.default_language"
                    :description="trans('ui.editorial.language_hint')"
                >
                    <TextInput v-model="form.default_language" autocomplete="off" />
                </FormField>

                <FormField
                    :label="trans('ui.editorial.default_artist')"
                    :error="form.errors.default_artist"
                    :description="trans('ui.editorial.artist_hint')"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-small text-foreground">
                            {{ artistName ?? trans('ui.editorial.none_set') }}
                        </span>
                        <AppButton variant="secondary" @click="artistPicker = true">
                            {{ trans('ui.editorial.choose_artist') }}
                        </AppButton>
                        <AppButton v-if="artistName !== null" variant="ghost" @click="clearArtist">
                            {{ trans('ui.editorial.clear_artist') }}
                        </AppButton>
                    </div>
                </FormField>

                <FormField
                    v-for="list in LISTS"
                    :key="list"
                    :label="trans(`ui.editorial.${list}`)"
                    :error="form.errors[list]"
                    :description="trans('ui.editorial.list_hint')"
                >
                    <TextInput v-model="lines[list]" autocomplete="off" />
                </FormField>

                <FormField :label="trans('ui.editorial.title_guidance')" :error="form.errors.title_guidance">
                    <TextareaInput v-model="form.title_guidance" :rows="2" />
                </FormField>

                <FormField
                    :label="trans('ui.editorial.description_guidance')"
                    :error="form.errors.description_guidance"
                >
                    <TextareaInput v-model="form.description_guidance" :rows="2" />
                </FormField>

                <!-- Only when correcting. A profile is created active, because
                     one that arrived retired is one somebody has to remember
                     to switch on before it can be used for anything. -->
                <FormField
                    v-if="!creating"
                    :label="trans('ui.editorial.is_active')"
                    :description="trans('ui.editorial.retire_hint')"
                >
                    <ToggleInput v-model="form.is_active" :label="trans('ui.editorial.is_active')" />
                </FormField>
            </div>

            <template #footer>
                <AppButton
                    variant="secondary"
                    @click="
                        editing = null;
                        creating = false;
                    "
                >
                    {{ trans('ui.actions.cancel') }}
                </AppButton>
                <AppButton variant="primary" :disabled="form.processing" @click="submit">
                    {{ trans('ui.actions.save') }}
                </AppButton>
            </template>
        </AppModal>

        <AppModal
            :open="artistPicker"
            :title="trans('ui.editorial.choose_artist')"
            @close="artistPicker = false"
        >
            <ResourcePicker
                endpoint="/releases/options/artists"
                :label="trans('ui.editorial.default_artist')"
                @select="chooseArtist"
            >
                <template #row="{ row }">{{ row.name }}</template>
            </ResourcePicker>
        </AppModal>
    </div>
</template>
