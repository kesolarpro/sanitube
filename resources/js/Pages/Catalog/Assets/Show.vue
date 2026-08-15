<script setup lang="ts">
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { bitrate, bytes, dateTime, duration, sampleRate } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { AssetDetail } from '@/Types/catalog';
import type { SharedProps } from '@/Types/inertia';

/**
 * One asset.
 *
 * **The page never receives a playable URL.** A signed URL is a temporary
 * bearer credential; minting one every time somebody opens this screen would
 * create credentials nobody asked for, for every asset merely looked at.
 *
 * The props say whether a preview *could* be minted. Pressing the button asks
 * the server for one, and it is held in memory here until the page is left —
 * never stored, never put in the URL bar.
 */
const props = defineProps<{ asset: AssetDetail }>();

const page = usePage<SharedProps>();

const previewUrl = ref<string | null>(null);
const previewExpiresAt = ref<string | null>(null);
const previewError = ref<string | null>(null);
const requesting = ref(false);

async function requestPreview(): Promise<void> {
    requesting.value = true;
    previewError.value = null;

    try {
        const response = await fetch(`/catalog/assets/${props.asset.uuid}/preview`, {
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
            previewError.value = trans(`ui.catalog.preview_reason.${payload.reason ?? 'PREVIEW_UNAVAILABLE'}`);

            return;
        }

        previewUrl.value = payload.url;
        previewExpiresAt.value = payload.expires_at;
    } catch {
        previewError.value = trans('ui.catalog.preview_reason.PREVIEW_UNAVAILABLE');
    } finally {
        requesting.value = false;
    }
}

const isAudio = ['AUDIO_MASTER', 'AUDIO_DERIVATIVE', 'PREVIEW', 'STEM'].includes(props.asset.kind);
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-caption text-muted">
                <Link href="/catalog/assets" class="hover:text-foreground">{{ trans('ui.catalog.assets') }}</Link>
            </p>
            <h1 class="mt-1 text-page-title text-foreground">
                {{ trans(`ui.catalog.asset_kind.${asset.kind}`) }}
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <StatusBadge :status="asset.status" group="generic" />
                <CodeValue :value="asset.checksum_short" />
                <span v-if="asset.duplicate_of" class="text-caption text-warning">
                    {{ trans('ui.catalog.duplicate') }}
                </span>
            </div>
        </div>

        <AppCard>
            <template #header>{{ trans('ui.catalog.preview') }}</template>
            <template #description>{{ trans('ui.catalog.preview_description') }}</template>

            <div v-if="asset.preview.available" class="space-y-3">
                <button
                    v-if="previewUrl === null"
                    type="button"
                    :disabled="requesting"
                    class="rounded-control bg-accent px-3 py-2 text-small font-medium text-accent-foreground hover:bg-accent-hover disabled:opacity-60"
                    @click="requestPreview"
                >
                    {{ requesting ? trans('ui.states.loading') : trans('ui.catalog.request_preview') }}
                </button>

                <template v-else>
                    <audio v-if="isAudio" :src="previewUrl" controls class="w-full" />
                    <img v-else :src="previewUrl" alt="" class="max-h-96 rounded-card border border-border" />
                    <p v-if="previewExpiresAt" class="text-caption text-muted">
                        {{ trans('ui.catalog.preview_expires') }} {{ dateTime(previewExpiresAt, page.props.app.locale) }}
                    </p>
                </template>

                <AppAlert v-if="previewError" tone="danger">{{ previewError }}</AppAlert>
            </div>

            <AppAlert v-else tone="info">
                {{ trans(`ui.catalog.preview_reason.${asset.preview.reason ?? 'PREVIEW_UNAVAILABLE'}`) }}
            </AppAlert>
        </AppCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.catalog.metadata') }}</template>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                    <dt class="text-muted">{{ trans('ui.catalog.format') }}</dt>
                    <dd class="text-foreground">{{ asset.mime_type }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.size') }}</dt>
                    <dd class="numeric text-foreground">{{ bytes(asset.byte_size) }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.column.duration') }}</dt>
                    <dd class="numeric text-foreground">{{ duration(asset.duration_ms) }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.sample_rate') }}</dt>
                    <dd class="numeric text-foreground">{{ sampleRate(asset.sample_rate) }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.channels') }}</dt>
                    <dd class="numeric text-foreground">{{ asset.channels ?? '—' }}</dd>
                    <dt class="text-muted">{{ trans('ui.catalog.verified_at') }}</dt>
                    <dd class="text-foreground">
                        {{ asset.verified_at ? dateTime(asset.verified_at, page.props.app.locale) : '—' }}
                    </dd>
                </dl>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.catalog.relationships') }}</template>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                    <dt class="text-muted">{{ trans('ui.catalog.parent') }}</dt>
                    <dd>
                        <Link
                            v-if="asset.parent"
                            :href="`/catalog/assets/${asset.parent.uuid}`"
                            class="text-foreground hover:text-accent"
                        >
                            {{ trans(`ui.catalog.asset_kind.${asset.parent.kind}`) }}
                        </Link>
                        <span v-else class="text-muted">—</span>
                    </dd>
                    <dt class="text-muted">{{ trans('ui.catalog.duplicate_of') }}</dt>
                    <dd>
                        <Link
                            v-if="asset.duplicate_of"
                            :href="`/catalog/assets/${asset.duplicate_of.uuid}`"
                            class="text-foreground hover:text-accent"
                        >
                            {{ trans('ui.catalog.original') }}
                        </Link>
                        <span v-else class="text-muted">—</span>
                    </dd>
                    <dt class="text-muted">{{ trans('ui.catalog.derivatives') }}</dt>
                    <dd class="numeric text-foreground">{{ asset.derivative_count }}</dd>
                </dl>

                <div v-if="asset.analysis" class="mt-3 border-t border-border pt-3">
                    <p class="text-caption text-muted">{{ trans('ui.catalog.analysis') }}</p>
                    <p v-if="asset.analysis.failed" class="mt-1 text-small text-danger">
                        {{ trans('ui.catalog.analysis_failed') }}
                    </p>
                    <dl v-else class="mt-1 grid grid-cols-2 gap-x-4 gap-y-2 text-small">
                        <dt class="text-muted">{{ trans('ui.catalog.codec') }}</dt>
                        <dd class="text-foreground">{{ asset.analysis.codec ?? '—' }}</dd>
                        <dt class="text-muted">{{ trans('ui.catalog.bitrate') }}</dt>
                        <dd class="numeric text-foreground">{{ bitrate(asset.analysis.bitrate) }}</dd>
                    </dl>
                </div>
            </AppCard>
        </div>
    </div>
</template>
