<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import IdentifierEditor from '@/Components/Catalog/IdentifierEditor.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import ResourcePicker from '@/Components/Ui/ResourcePicker.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { bytes, duration } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type {
    ReleaseArtistCredit,
    ReleaseDetail,
    ReleasePackagePreparation,
    ReleaseTrackRow,
} from '@/Types/releases';

/**
 * The release builder.
 *
 * Seven sections, in the order a release is actually assembled: what it is,
 * who made it, what is on it, what it looks like, what identifies it, what is
 * still wrong, and — last — whether it may go.
 *
 * Three things this screen deliberately does not do:
 *
 *   - **It never decides what may be edited.** `actions.can_edit_*` comes from
 *     `ReleaseMutationPolicy` on the server. A screen that worked out for
 *     itself which fields a SUBMITTED release still accepts would be a second
 *     copy of I4, kept in the least trustworthy place.
 *   - **It never mints an identifier.** The identifiers section is a table.
 *   - **It never writes `status`.** "Mark ready" asks the domain, which re-runs
 *     the invariants and refuses if they do not hold. The button being enabled
 *     is a prediction; `markReady()` is the decision.
 *
 * Every disabled control is disabled *with a reason shown*. A greyed-out field
 * with no explanation is indistinguishable from a broken one.
 */
const props = defineProps<{ release: ReleaseDetail }>();

const page = usePage<SharedProps>();

/**
 * A refusal from the domain, as its machine-readable reason code.
 *
 * The server never sends the domain's English sentence for a *refusal* — that
 * is written for a developer reading a log — so the code travels and this
 * translates it. The validation lists further down are a different thing and
 * are discussed where they are rendered.
 */
const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.release ?? null;
});

/* ---------------------------------------------------------------- details */

const detailsForm = useForm({
    title: props.release.title,
    version_title: props.release.version_title ?? '',
    type: props.release.type,
    label_name: props.release.label_name ?? '',
    catalogue_number: props.release.catalogue_number ?? '',
    release_date: props.release.release_date ?? '',
    original_release_date: props.release.original_release_date ?? '',
    language_code: props.release.language_code,
    p_line: props.release.p_line ?? '',
    c_line: props.release.c_line ?? '',
});

watch(
    () => props.release,
    (release) => {
        detailsForm.defaults({
            title: release.title,
            version_title: release.version_title ?? '',
            type: release.type,
            label_name: release.label_name ?? '',
            catalogue_number: release.catalogue_number ?? '',
            release_date: release.release_date ?? '',
            original_release_date: release.original_release_date ?? '',
            language_code: release.language_code,
            p_line: release.p_line ?? '',
            c_line: release.c_line ?? '',
        });
        detailsForm.reset();
    },
);

const typeOptions = ['SINGLE', 'EP', 'ALBUM', 'COMPILATION'].map((value) => ({
    value,
    label: trans(`ui.releases.release_type.${value}`),
}));

function saveDetails(): void {
    detailsForm.patch(`/releases/${props.release.uuid}`, { preserveScroll: true });
}

/* ---------------------------------------------------------------- artists */

const roleOptions = ['PRIMARY', 'FEATURED'].map((value) => ({
    value,
    label: trans(`ui.releases.artist_role.${value}`),
}));

/**
 * The credit list, edited locally and saved whole.
 *
 * `setArtists()` replaces the list rather than patching it, because the order
 * *is* the credit — "A feat. B" and "B feat. A" are different releases — and a
 * per-row endpoint cannot express a reordering without a moment where the
 * release is credited to nobody.
 */
const credits = reactive<ReleaseArtistCredit[]>([...props.release.artists]);

watch(
    () => props.release.artists,
    (artists) => {
        credits.splice(0, credits.length, ...artists);
    },
);

const artistPicker = ref(false);

const hasPrimary = computed(() => credits.some((credit) => credit.role === 'PRIMARY'));

function addCredit(row: Record<string, unknown>): void {
    const uuid = String(row.uuid ?? '');

    if (uuid === '' || credits.some((credit) => credit.uuid === uuid)) {
        return;
    }

    credits.push({
        uuid,
        name: String(row.name ?? ''),
        // The first artist credited is a primary by default. It is the
        // overwhelmingly common case, and the builder refuses a list without
        // one anyway, so defaulting to FEATURED would only teach people to fix
        // it every time.
        role: credits.length === 0 ? 'PRIMARY' : 'FEATURED',
        position: credits.length,
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

    if (moved === undefined) {
        return;
    }

    credits.splice(target, 0, moved);
}

const savingArtists = ref(false);

function saveArtists(): void {
    savingArtists.value = true;

    router.post(
        `/releases/${props.release.uuid}/artists`,
        { artists: credits.map((credit) => ({ uuid: credit.uuid, role: credit.role })) },
        {
            preserveScroll: true,
            onFinish: () => {
                savingArtists.value = false;
            },
        },
    );
}

/* ----------------------------------------------------------------- tracks */

const trackPicker = ref(false);
const removing = ref<ReleaseTrackRow | null>(null);

/** The tracklist grouped by disc, in delivery order. */
const discs = computed(() => {
    const grouped = new Map<number, ReleaseTrackRow[]>();

    for (const track of props.release.tracks) {
        const list = grouped.get(track.disc_number) ?? [];
        list.push(track);
        grouped.set(track.disc_number, list);
    }

    return [...grouped.entries()]
        .sort((a, b) => a[0] - b[0])
        .map(([disc, tracks]) => ({ disc, tracks }));
});

function addTrack(row: Record<string, unknown>): void {
    const uuid = String(row.uuid ?? '');

    if (uuid === '') {
        return;
    }

    router.post(
        `/releases/${props.release.uuid}/tracks`,
        { track: uuid, disc: 1 },
        {
            preserveScroll: true,
            onSuccess: () => {
                trackPicker.value = false;
            },
        },
    );
}

function confirmRemoveTrack(): void {
    const track = removing.value;

    if (track === null) {
        return;
    }

    router.delete(`/releases/${props.release.uuid}/tracks`, {
        data: { track: track.uuid },
        preserveScroll: true,
        onFinish: () => {
            removing.value = null;
        },
    });
}

/**
 * Move a track within its disc.
 *
 * The whole disc's order is sent, not the pair that swapped. Numbering is a
 * property of the list — `UNIQUE(release_id, disc_number, track_number)` makes
 * that literal — and the builder parks every row above the occupied range
 * before writing final numbers. A two-row swap sent as two updates collides in
 * the middle.
 */
function moveTrack(disc: number, index: number, delta: number): void {
    const group = discs.value.find((entry) => entry.disc === disc);

    if (group === undefined) {
        return;
    }

    const target = index + delta;

    if (target < 0 || target >= group.tracks.length) {
        return;
    }

    const order = group.tracks.map((track) => track.uuid);
    const [moved] = order.splice(index, 1);

    if (moved === undefined) {
        return;
    }

    order.splice(target, 0, moved);

    router.post(
        `/releases/${props.release.uuid}/tracks/order`,
        { disc, tracks: order },
        { preserveScroll: true },
    );
}

/* ------------------------------------------------------------------ cover */

const coverPicker = ref(false);

function setCover(row: Record<string, unknown>): void {
    const uuid = String(row.uuid ?? '');

    if (uuid === '') {
        return;
    }

    router.post(
        `/releases/${props.release.uuid}/cover`,
        { asset: uuid },
        {
            preserveScroll: true,
            onSuccess: () => {
                coverPicker.value = false;
            },
        },
    );
}

/** CAT-005. The identifier editor's own refusal, kept apart from the release's. */
const identifierRefusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.identifier ?? null;
});

/* ---------------------------------------------------------------- package */

/**
 * REL-004. What would actually cross to a distributor.
 *
 * Fetched on demand rather than shipped with the page: packaging walks every
 * track, credit and identifier on the release, and a label keeps this screen
 * open while editing. Most renders do not need the answer.
 *
 * **Three things are shown, and they are not the same thing.** The package
 * either assembled or was refused. The identifier requirements — a UPC, an ISRC
 * per track — block every delivery to every distributor and are reported even
 * when the package assembles perfectly. And if this installation has no
 * distributor configured, that is said plainly, because it is the one blocker
 * no amount of editing the release will clear.
 */
const preparation = ref<ReleasePackagePreparation | null>(null);
const preparing = ref(false);
const preparationFailed = ref(false);

async function prepare(): Promise<void> {
    preparing.value = true;
    preparationFailed.value = false;

    try {
        const response = await fetch(`/releases/${props.release.uuid}/package`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            preparationFailed.value = true;
            preparation.value = null;

            return;
        }

        preparation.value = (await response.json()) as ReleasePackagePreparation;
    } catch {
        // The request never arrived. Distinct from a refusal, and shown as
        // such: "we could not ask" and "it said no" are opposite answers.
        preparationFailed.value = true;
        preparation.value = null;
    } finally {
        preparing.value = false;
    }
}

/**
 * Cleared when the release changes underneath it. A package assembled before an
 * edit describes a release that no longer exists, and leaving it on screen
 * beside the new tracklist is how somebody delivers from a stale reading.
 */
watch(
    () => props.release,
    () => {
        preparation.value = null;
        preparationFailed.value = false;
    },
);

/* -------------------------------------------------------------- readiness */

const markingReady = ref(false);
const reopening = ref(false);
const acting = ref(false);

function markReady(): void {
    acting.value = true;

    router.post(
        `/releases/${props.release.uuid}/ready`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                acting.value = false;
                markingReady.value = false;
            },
        },
    );
}

function reopen(): void {
    acting.value = true;

    router.post(
        `/releases/${props.release.uuid}/reopen`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                acting.value = false;
                reopening.value = false;
            },
        },
    );
}

const isReady = computed(() => props.release.status !== 'DRAFT');
const editable = computed(() => props.release.actions.can_edit_details);
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-caption text-muted">
                    <Link href="/releases" class="hover:text-accent">{{ trans('ui.releases.title') }}</Link>
                </p>
                <h1 class="text-page-title text-foreground">{{ release.title }}</h1>
                <p v-if="release.version_title !== null" class="mt-1 text-small text-muted">
                    {{ release.version_title }}
                </p>
            </div>
            <StatusBadge :status="release.status" group="generic" />
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.releases.failure.${refusal}`) }}
        </AppAlert>

        <!-- Why a section is read-only, said once and in plain words rather
             than repeated as a disabled attribute on every field. -->
        <AppAlert
            v-if="release.actions.may_edit && !editable"
            tone="info"
            :title="trans('ui.releases.locked')"
        >
            {{ trans('ui.releases.locked_note') }}
        </AppAlert>

        <!-- 1. What it is. -->
        <AppCard>
            <template #header>{{ trans('ui.releases.section.metadata') }}</template>

            <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveDetails">
                <FormField :label="trans('ui.releases.release_title')" :error="detailsForm.errors.title">
                    <TextInput v-model="detailsForm.title" :disabled="!editable" />
                </FormField>
                <FormField :label="trans('ui.releases.version_title')" :error="detailsForm.errors.version_title">
                    <TextInput v-model="detailsForm.version_title" :disabled="!editable" />
                </FormField>
                <FormField :label="trans('ui.releases.type')" :error="detailsForm.errors.type">
                    <SelectInput v-model="detailsForm.type" :options="typeOptions" :disabled="!editable" />
                </FormField>
                <FormField :label="trans('ui.catalog.column.language')" :error="detailsForm.errors.language_code">
                    <TextInput v-model="detailsForm.language_code" :disabled="!editable" />
                </FormField>
                <FormField :label="trans('ui.releases.label_name')" :error="detailsForm.errors.label_name">
                    <TextInput v-model="detailsForm.label_name" :disabled="!editable" />
                </FormField>
                <FormField
                    :label="trans('ui.releases.catalogue_number')"
                    :error="detailsForm.errors.catalogue_number"
                >
                    <TextInput v-model="detailsForm.catalogue_number" :disabled="!editable" />
                </FormField>
                <FormField :label="trans('ui.releases.release_date')" :error="detailsForm.errors.release_date">
                    <TextInput v-model="detailsForm.release_date" type="date" :disabled="!editable" />
                </FormField>
                <FormField
                    :label="trans('ui.releases.original_release_date')"
                    :error="detailsForm.errors.original_release_date"
                >
                    <TextInput v-model="detailsForm.original_release_date" type="date" :disabled="!editable" />
                </FormField>
                <FormField
                    :label="trans('ui.releases.p_line')"
                    :error="detailsForm.errors.p_line"
                    :hint="trans('ui.releases.p_line_hint')"
                >
                    <TextInput v-model="detailsForm.p_line" :disabled="!editable" />
                </FormField>
                <FormField :label="trans('ui.releases.c_line')" :error="detailsForm.errors.c_line">
                    <TextInput v-model="detailsForm.c_line" :disabled="!editable" />
                </FormField>

                <div v-if="editable" class="sm:col-span-2">
                    <AppButton type="submit" variant="primary" :loading="detailsForm.processing">
                        {{ trans('ui.releases.save') }}
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <!-- 2. Who made it. -->
        <AppCard>
            <template #header>{{ trans('ui.releases.section.artists') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.releases.artists_description') }}</p>

            <EmptyState v-if="credits.length === 0" :title="trans('ui.releases.no_artists')" />

            <ul v-else class="divide-y divide-border">
                <li
                    v-for="(credit, index) in credits"
                    :key="credit.uuid"
                    class="flex flex-wrap items-center gap-3 py-2"
                >
                    <span class="grow text-small text-foreground">{{ credit.name }}</span>
                    <SelectInput
                        v-model="credit.role"
                        :options="roleOptions"
                        :disabled="!release.actions.can_edit_artists"
                    />
                    <template v-if="release.actions.can_edit_artists">
                        <AppButton variant="ghost" :disabled="index === 0" @click="moveCredit(index, -1)">
                            {{ trans('ui.releases.move_up') }}
                        </AppButton>
                        <AppButton
                            variant="ghost"
                            :disabled="index === credits.length - 1"
                            @click="moveCredit(index, 1)"
                        >
                            {{ trans('ui.releases.move_down') }}
                        </AppButton>
                        <AppButton variant="ghost" @click="removeCredit(credit.uuid)">
                            {{ trans('ui.actions.delete') }}
                        </AppButton>
                    </template>
                </li>
            </ul>

            <!-- The builder refuses a list with no primary. Saying so before
                 the request is sent is kinder than a refusal; it is not a
                 substitute for one, and the server checks regardless. -->
            <AppAlert
                v-if="release.actions.can_edit_artists && credits.length > 0 && !hasPrimary"
                tone="warning"
                class="mt-3"
                :title="trans('ui.releases.failure.PRIMARY_ARTIST_REQUIRED')"
            />

            <div v-if="release.actions.can_edit_artists" class="mt-3 flex flex-wrap gap-2">
                <AppButton variant="secondary" @click="artistPicker = true">
                    {{ trans('ui.releases.add_artist') }}
                </AppButton>
                <AppButton variant="primary" :loading="savingArtists" @click="saveArtists">
                    {{ trans('ui.releases.save_artists') }}
                </AppButton>
            </div>
        </AppCard>

        <!-- 3. What is on it. -->
        <AppCard :padded="false">
            <template #header>{{ trans('ui.releases.section.tracks') }}</template>

            <EmptyState
                v-if="release.tracks.length === 0"
                :title="trans('ui.releases.no_tracks')"
                :description="trans('ui.releases.no_tracks_description')"
            />

            <div v-for="group in discs" v-else :key="group.disc">
                <p v-if="discs.length > 1" class="px-4 pt-3 text-caption text-muted">
                    {{ trans('ui.releases.disc') }} {{ group.disc }}
                </p>
                <DataTable :caption="trans('ui.releases.section.tracks')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.releases.position') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.duration') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.isrc') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.releases.focus_track') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.actions') }}</TableHeaderCell>
                    </template>
                    <tr v-for="(track, index) in group.tracks" :key="track.uuid" class="border-t border-border">
                        <TableCell>{{ track.track_number }}</TableCell>
                        <TableCell>
                            <Link :href="`/catalog/tracks/${track.uuid}`" class="hover:text-accent">
                                {{ track.title }}
                            </Link>
                            <span v-if="track.version_title !== null" class="ml-2 text-caption text-muted">
                                {{ track.version_title }}
                            </span>
                        </TableCell>
                        <TableCell>{{ duration(track.duration_ms) }}</TableCell>
                        <TableCell>
                            <CodeValue v-if="track.isrc !== null" :value="track.isrc" />
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                        <TableCell>
                            <!-- Read-only here. Which track is promoted is set
                                 when it is added; a toggle a person can move
                                 but that saves nothing is worse than a fact. -->
                            <span v-if="track.is_focus_track" class="text-small text-foreground">
                                {{ trans('ui.releases.focus_track') }}
                            </span>
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                        <TableCell>
                            <div v-if="release.actions.can_edit_tracks" class="flex flex-wrap gap-1">
                                <AppButton
                                    variant="ghost"
                                    :disabled="index === 0"
                                    @click="moveTrack(group.disc, index, -1)"
                                >
                                    {{ trans('ui.releases.move_up') }}
                                </AppButton>
                                <AppButton
                                    variant="ghost"
                                    :disabled="index === group.tracks.length - 1"
                                    @click="moveTrack(group.disc, index, 1)"
                                >
                                    {{ trans('ui.releases.move_down') }}
                                </AppButton>
                                <AppButton variant="ghost" @click="removing = track">
                                    {{ trans('ui.releases.remove_track') }}
                                </AppButton>
                            </div>
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                    </tr>
                </DataTable>
            </div>

            <div v-if="release.actions.can_edit_tracks" class="p-4">
                <AppButton variant="secondary" @click="trackPicker = true">
                    {{ trans('ui.releases.add_track') }}
                </AppButton>
            </div>
        </AppCard>

        <!-- 4. What it looks like. -->
        <AppCard>
            <template #header>{{ trans('ui.releases.section.cover') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.releases.cover_description') }}</p>

            <EmptyState v-if="release.cover === null" :title="trans('ui.releases.no_cover')" />

            <template v-else>
                <dl class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.status') }}</dt>
                        <dd class="text-small">
                            <StatusBadge :status="release.cover.status" group="generic" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.dimensions') }}</dt>
                        <dd class="text-small text-foreground">
                            {{ release.cover.width ?? '—' }} × {{ release.cover.height ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-caption text-muted">{{ trans('ui.catalog.column.size') }}</dt>
                        <dd class="text-small text-foreground">{{ bytes(release.cover.byte_size) }}</dd>
                    </div>
                </dl>

                <AppAlert
                    v-if="!release.cover.verified"
                    tone="warning"
                    class="mt-3"
                    :title="trans('ui.releases.cover_unverified')"
                >
                    {{ trans('ui.releases.cover_unverified_note') }}
                </AppAlert>
            </template>

            <div v-if="release.actions.can_edit_cover" class="mt-3">
                <AppButton variant="secondary" @click="coverPicker = true">
                    {{ trans('ui.releases.set_cover') }}
                </AppButton>
            </div>
        </AppCard>

        <!-- 5. What identifies it. Read, never minted. -->
        <AppCard>
            <template #header>{{ trans('ui.releases.section.identifiers') }}</template>

            <p class="mb-3 text-caption text-muted">{{ trans('ui.releases.identifiers_description') }}</p>

            <!-- CAT-005. A table until this ticket: REL-004 had just made "no
                 UPC" the thing that blocks every delivery, and there was no way
                 in the product to supply one. Telling somebody what is wrong
                 while withholding the fix is worse than saying nothing, because
                 it looks like the platform is working. -->
            <IdentifierEditor
                :identifiers="release.identifiers"
                :assignable-types="release.assignable_identifier_types"
                :endpoint="`/releases/${release.uuid}`"
                :can-assign="release.actions.can_assign_identifier"
                :refusal="identifierRefusal"
            />
        </AppCard>

        <!-- 6. What is still wrong.

             REL-002: issues with codes, translated here. `context` carries the
             values — a track number, a disc — so each locale puts them in its
             own word order rather than receiving a pre-built English sentence. -->
        <AppCard>
            <template #header>{{ trans('ui.releases.section.validation') }}</template>

            <p v-if="release.validation.errors.length === 0" class="text-small text-muted">
                {{ trans('ui.releases.no_problems') }}
            </p>

            <div v-else class="space-y-2">
                <p class="text-caption text-danger">{{ trans('ui.releases.errors') }}</p>
                <ul class="list-disc space-y-1 pl-5 text-small text-foreground">
                    <li v-for="problem in release.validation.errors" :key="problem.code + problem.path">
                        {{ trans(`ui.issue.${problem.code}`, problem.context) }}
                    </li>
                </ul>
            </div>

            <div class="mt-4 space-y-2">
                <p class="text-caption text-muted">{{ trans('ui.releases.warnings') }}</p>
                <p v-if="release.validation.warnings.length === 0" class="text-small text-muted">
                    {{ trans('ui.releases.no_warnings') }}
                </p>
                <ul v-else class="list-disc space-y-1 pl-5 text-small text-muted">
                    <li v-for="warning in release.validation.warnings" :key="warning.code + warning.path">
                        {{ trans(`ui.issue.${warning.code}`, warning.context) }}
                    </li>
                </ul>
            </div>
        </AppCard>

        <!-- REL-004. The distribution package, prepared on demand.
             Between validation and the readiness decision, because it is the
             last thing a label looks at before deciding — and because it is
             the only place the content that leaves this platform is visible. -->
        <AppCard>
            <template #header>{{ trans('ui.releases.section.package') }}</template>
            <template #description>{{ trans('ui.releases.package_description') }}</template>

            <div class="flex flex-wrap items-center gap-2">
                <AppButton variant="secondary" :loading="preparing" @click="prepare">
                    {{ trans('ui.releases.prepare_package') }}
                </AppButton>
            </div>

            <AppAlert
                v-if="preparationFailed"
                tone="danger"
                class="mt-3"
                :title="trans('ui.releases.package_unreachable')"
            />

            <div v-if="preparation !== null" class="mt-4 space-y-4">
                <!-- Did it assemble. A code, translated here; the server's own
                     sentence names internal concepts and stays in the log. -->
                <AppAlert
                    v-if="!preparation.prepared"
                    tone="danger"
                    :title="trans(`ui.releases.package_refusal.${preparation.refusal}`)"
                >
                    <!-- Only the uuids, and only for the one refusal whose
                         context is uuids. RELEASE_NOT_VALID carries validation
                         codes, and those are already listed — translated, with
                         their paths — in the section directly above; repeating
                         them here would say the same thing twice under two
                         different headings. -->
                    <ul
                        v-if="preparation.refusal === 'TRACK_WITHOUT_MASTER'"
                        class="mt-1 space-y-1"
                    >
                        <li v-for="uuid in preparation.refusal_context" :key="uuid">
                            <CodeValue :value="uuid" />
                        </li>
                    </ul>
                </AppAlert>

                <AppAlert v-else tone="success" :title="trans('ui.releases.package_prepared')">
                    <p>
                        {{
                            trans('ui.releases.package_summary', {
                                tracks: String(preparation.package?.tracks.length ?? 0),
                                artists: String(preparation.package?.artists.length ?? 0),
                            })
                        }}
                    </p>
                </AppAlert>

                <!-- Blocking whatever the package did, and whoever it goes to.
                     Reported even beside a package that assembled perfectly:
                     packaging asks REL-001's validator, delivery also asks
                     these, and conflating the two would call a release ready
                     that no store can identify. -->
                <div v-if="preparation.blocking_identifiers.length > 0" class="space-y-2">
                    <p class="text-caption text-danger">{{ trans('ui.releases.package_blocking') }}</p>
                    <ul class="list-disc space-y-1 pl-5 text-small text-foreground">
                        <li
                            v-for="issue in preparation.blocking_identifiers"
                            :key="issue.code + issue.path"
                        >
                            {{ trans(`ui.issue.${issue.code}`, issue.context) }}
                        </li>
                    </ul>
                </div>

                <!-- The blocker no amount of editing the release will clear. -->
                <AppAlert
                    v-if="preparation.destinations_configured === 0"
                    tone="warning"
                    :title="trans('ui.releases.package_no_destination')"
                />

                <!-- What would actually cross over. The tracklist as the
                     distributor receives it, not as the builder shows it:
                     ordered by (disc, track), each with the identifier that
                     names the recording and the master that carries it. -->
                <DataTable
                    v-if="preparation.package !== null"
                    :caption="trans('ui.releases.package_tracklist')"
                >
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.releases.position') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.isrc') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.releases.package_master') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.releases.package_credits') }}</TableHeaderCell>
                    </template>

                    <tr v-for="row in preparation.package.tracks" :key="row.uuid" class="border-t border-border">
                        <TableCell>{{ row.disc_number }}-{{ row.track_number }}</TableCell>
                        <TableCell>{{ row.title }}</TableCell>
                        <TableCell>
                            <CodeValue v-if="row.isrc !== null" :value="row.isrc" />
                            <span v-else class="text-small text-muted">{{ trans('ui.releases.package_no_isrc') }}</span>
                        </TableCell>
                        <!-- A uuid, never a path. The package names assets; only
                             the storage service knows where anything lives. -->
                        <TableCell><CodeValue :value="row.master_asset_uuid" /></TableCell>
                        <TableCell>{{ row.artists.length + row.contributors.length }}</TableCell>
                    </tr>
                </DataTable>
            </div>
        </AppCard>

        <!-- 7. Whether it may go. Last on the page: the decision comes after
             everything it depends on. -->
        <AppCard v-if="release.actions.may_edit">
            <template #header>{{ trans('ui.releases.section.readiness') }}</template>

            <AppAlert v-if="isReady" tone="success" :title="trans('ui.releases.is_ready')" />

            <p
                v-else-if="!release.actions.can_mark_ready"
                class="text-small text-muted"
            >
                {{ trans('ui.releases.cannot_mark_ready') }}
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                <AppButton
                    v-if="release.actions.can_mark_ready"
                    variant="primary"
                    @click="markingReady = true"
                >
                    {{ trans('ui.releases.mark_ready') }}
                </AppButton>

                <AppButton v-if="release.actions.can_reopen" variant="secondary" @click="reopening = true">
                    {{ trans('ui.releases.reopen') }}
                </AppButton>

                <!-- Always offered, not only once ready: "where could this go
                     and has it gone there already" is a question people have
                     while a release is still being assembled, and the
                     destination screen answers it without handing anything
                     over. -->
                <AppButton variant="ghost" :href="`/releases/${release.uuid}/distribution`">
                    {{ trans('ui.distribution.send_title') }}
                </AppButton>
            </div>
        </AppCard>

        <AppModal
            :open="artistPicker"
            :title="trans('ui.releases.add_artist')"
            @close="artistPicker = false"
        >
            <ResourcePicker
                endpoint="/releases/options/artists"
                :label="trans('ui.releases.section.artists')"
                @select="addCredit"
            >
                <template #row="{ row }">{{ row.name }}</template>
            </ResourcePicker>
        </AppModal>

        <AppModal
            :open="trackPicker"
            :title="trans('ui.releases.add_track')"
            :description="trans('ui.releases.add_track_description')"
            @close="trackPicker = false"
        >
            <ResourcePicker
                :endpoint="`/releases/${release.uuid}/options/tracks`"
                :label="trans('ui.releases.track_uuid')"
                @select="addTrack"
            >
                <template #row="{ row }">{{ row.title }}</template>
            </ResourcePicker>
        </AppModal>

        <AppModal
            :open="coverPicker"
            :title="trans('ui.releases.set_cover')"
            :description="trans('ui.releases.cover_description')"
            @close="coverPicker = false"
        >
            <ResourcePicker
                endpoint="/releases/options/artwork"
                :label="trans('ui.releases.cover_asset')"
                @select="setCover"
            >
                <template #row="{ row }">{{ row.original_filename }}</template>
            </ResourcePicker>
        </AppModal>

        <ConfirmDialog
            :open="removing !== null"
            :title="trans('ui.releases.remove_track')"
            :description="trans('ui.releases.remove_track_description')"
            :confirm-label="trans('ui.releases.remove_track_confirm')"
            destructive
            @cancel="removing = null"
            @confirm="confirmRemoveTrack"
        />

        <ConfirmDialog
            :open="markingReady"
            :title="trans('ui.releases.mark_ready')"
            :description="trans('ui.releases.mark_ready_description')"
            :confirm-label="trans('ui.releases.mark_ready_confirm')"
            :busy="acting"
            @cancel="markingReady = false"
            @confirm="markReady"
        />

        <ConfirmDialog
            :open="reopening"
            :title="trans('ui.releases.reopen')"
            :description="trans('ui.releases.reopen_description')"
            :confirm-label="trans('ui.releases.reopen_confirm')"
            :busy="acting"
            @cancel="reopening = false"
            @confirm="reopen"
        />
    </div>
</template>
