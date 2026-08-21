<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { PackagePayload, PackagedMaster } from '@/Types/distribution';

/**
 * Delivering by hand, from a browser.
 *
 * DIST-004 built the package and gave it a command. That is the right tool for
 * the initial catalogue and the wrong one for the deployment this platform
 * exists to support: a label on a shared cPanel account has no shell, and a
 * delivery path that requires SSH is a delivery path that installation does not
 * have.
 *
 * **Nothing is submitted here, and the screen says so twice** — once where the
 * button is and once at the end. Building a package writes two files and no
 * delivery row. There is deliberately no "mark as delivered" control beside the
 * download: a button there would turn "I have the files" into "they have them",
 * which is a claim about somebody else's system that only a person can make.
 *
 * **A link is asked for one master at a time.** The page never arrives holding
 * one. A signed URL is a bearer credential that expires; minting twelve of them
 * because somebody opened a screen to read a filename would create eleven
 * nobody wanted.
 */
const props = defineProps<{ package: PackagePayload }>();

const page = usePage<SharedProps>();

const building = ref(false);

/** Held in memory, per asset, until the page is left. Never stored, never in the URL. */
const links = reactive<Record<string, { url: string; expiresAt: string } | null>>({});
const linkErrors = reactive<Record<string, string | null>>({});
const minting = ref<string | null>(null);

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;
    const code = errors?.distribution ?? null;

    return code === null ? null : refusalText(code);
});

/**
 * The two refusal vocabularies, in order, with nothing duplicated.
 *
 * Packaging refuses in Releases' words and delivery in Distribution's, and both
 * can reach this screen because building a package asks both. Copying four
 * sentences into a third catalogue would be four sentences six translators keep
 * in step by hand; a missing key comes back as the key itself, which is what
 * makes this readable as a lookup rather than a guess.
 */
function refusalText(code: string): string {
    const packaging = trans(`ui.releases.package_refusal.${code}`);

    if (!packaging.startsWith('ui.')) {
        return packaging;
    }

    const delivery = trans(`ui.distribution.failure.${code}`);

    if (!delivery.startsWith('ui.')) {
        return delivery;
    }

    return trans('ui.distribution.package_refusal_unknown');
}

function build(): void {
    building.value = true;

    router.post(
        `/releases/${props.package.release.uuid}/distribution/package`,
        {},
        { onFinish: () => (building.value = false) },
    );
}

async function mintLink(master: PackagedMaster): Promise<void> {
    minting.value = master.asset_uuid;
    linkErrors[master.asset_uuid] = null;

    try {
        // The ordinary minting endpoint — same policy, same throttle, same
        // audit line every other screen gets. A second download route for
        // delivery would be a second place the preview policy has to be
        // remembered.
        const response = await fetch(`/catalog/assets/${master.asset_uuid}/preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': String(page.props.csrf_token),
                Accept: 'application/json',
            },
        });

        const payload = await response.json();

        if (!response.ok) {
            // A reason code, translated here. The server never sends a sentence.
            linkErrors[master.asset_uuid] = trans(
                `ui.catalog.preview_reason.${payload.reason ?? 'PREVIEW_UNAVAILABLE'}`,
            );

            return;
        }

        links[master.asset_uuid] = { url: payload.url, expiresAt: payload.expires_at };
    } catch {
        linkErrors[master.asset_uuid] = trans('ui.catalog.preview_reason.PREVIEW_UNAVAILABLE');
    } finally {
        minting.value = null;
    }
}

function sizeOf(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    return `${(bytes / 1_048_576).toFixed(1)} MB`;
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link :href="`/releases/${package.release.uuid}`" class="hover:text-accent">
                    {{ package.release.title }}
                </Link>
            </p>
            <h1 class="text-page-title text-foreground">{{ trans('ui.distribution.package_title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.distribution.package_description') }}</p>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ refusal }}
        </AppAlert>

        <!-- Said outright rather than left to a disabled button: "why can I not
             build this" is the question this screen exists to answer. -->
        <AppAlert
            v-if="!package.release_is_ready"
            tone="warning"
            :title="trans('ui.distribution.release_not_ready')"
        >
            {{ trans('ui.distribution.release_not_ready_note') }}
        </AppAlert>

        <AppCard>
            <template #header>{{ trans('ui.distribution.package_sheet') }}</template>

            <EmptyState
                v-if="package.package === null"
                :title="trans('ui.distribution.package_none')"
                :description="trans('ui.distribution.package_none_description')"
            />

            <div v-else class="space-y-3">
                <p class="text-small text-muted">
                    {{ trans('ui.distribution.package_built_at', { when: package.package.built_at }) }}
                </p>

                <a
                    class="inline-block rounded border border-border px-3 py-1.5 text-small text-foreground hover:text-accent"
                    :href="`/releases/${package.release.uuid}/distribution/package/metadata`"
                >
                    {{ trans('ui.distribution.package_download_sheet') }}
                </a>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-border pt-3">
                <AppButton
                    variant="primary"
                    :loading="building"
                    :disabled="!package.release_is_ready"
                    @click="build"
                >
                    {{
                        package.package === null
                            ? trans('ui.distribution.package_build')
                            : trans('ui.distribution.package_rebuild')
                    }}
                </AppButton>
                <p class="text-caption text-muted">{{ trans('ui.distribution.package_not_submitted') }}</p>
            </div>
        </AppCard>

        <AppAlert
            v-if="package.package !== null && package.package.warnings.length > 0"
            tone="warning"
            :title="trans('ui.distribution.package_warnings')"
        >
            <ul class="list-disc space-y-1 pl-4">
                <li v-for="warning in package.package.warnings" :key="warning">{{ warning }}</li>
            </ul>
        </AppAlert>

        <AppCard v-if="package.package !== null" :padded="false">
            <template #header>{{ trans('ui.distribution.package_files') }}</template>

            <ul class="divide-y divide-border">
                <li v-for="master in package.package.tracks" :key="master.asset_uuid" class="space-y-2 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-small font-medium text-foreground">{{ master.file_name }}</p>
                            <p class="mt-1 text-caption text-muted">
                                {{ master.disc_number }}-{{ master.track_number }} · {{ sizeOf(master.bytes) }}
                            </p>
                        </div>

                        <AppButton
                            variant="secondary"
                            :loading="minting === master.asset_uuid"
                            @click="mintLink(master)"
                        >
                            {{ trans('ui.distribution.package_link') }}
                        </AppButton>
                    </div>

                    <!-- What an operator compares against after uploading. The
                         whole digest, because half of one proves nothing. -->
                    <p class="text-caption text-muted">
                        {{ trans('ui.distribution.package_checksum') }}
                        <CodeValue :value="master.sha256" />
                    </p>

                    <p v-if="linkErrors[master.asset_uuid]" class="text-caption text-warning">
                        {{ linkErrors[master.asset_uuid] }}
                    </p>

                    <div v-if="links[master.asset_uuid]" class="space-y-1">
                        <a
                            class="break-all text-small text-accent hover:underline"
                            :href="links[master.asset_uuid]!.url"
                            rel="noopener noreferrer"
                        >
                            {{ master.file_name }}
                        </a>
                        <p class="text-caption text-muted">
                            {{
                                trans('ui.distribution.package_link_expires', {
                                    when: links[master.asset_uuid]!.expiresAt,
                                })
                            }}
                        </p>
                    </div>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
