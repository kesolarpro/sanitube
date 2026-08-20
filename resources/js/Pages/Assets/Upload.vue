<script setup lang="ts">
import { computed, ref } from 'vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import ProgressBar from '@/Components/Ui/ProgressBar.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { bytes } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { UploadCapability, UploadItem } from '@/Types/upload';

/**
 * Putting a file into SaniTube.
 *
 * Before this screen the application had no `<input type="file">` at all, so
 * neither audio nor artwork could enter from a browser and the release cover
 * picker was permanently empty on a fresh installation.
 *
 * **Two paths, one screen, and it says which one it is on.** Object storage
 * takes the file directly, so the bytes never enter PHP and the ceiling is
 * SaniTube's own. Local storage — the shipped default, and the only option on
 * a host without object storage — cannot, so the file is relayed through the
 * application and the server's own PHP limits apply. Advertising the larger
 * figure on a host that will refuse at eight megabytes would be lying in the
 * one place somebody would look, so the effective ceiling is shown and the
 * binding limit is named.
 *
 * **One file at a time, in sequence.** A person selecting forty masters on a
 * shared host would otherwise open forty concurrent connections, and the first
 * thing to break would be the host rather than the upload. Sequential is also
 * what makes per-file failure legible: one refusal does not take the batch
 * down with it, and the rest keep going.
 *
 * Refusals arrive as codes and are translated here. The server never sends a
 * sentence, because its sentences name storage keys.
 */
const props = defineProps<{ upload: UploadCapability }>();

const kind = ref(props.upload.kinds[0]?.value ?? 'AUDIO_MASTER');
const items = ref<UploadItem[]>([]);
const running = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

let nextId = 0;

const selected = computed(() => props.upload.kinds.find((k) => k.value === kind.value) ?? null);

const kindOptions = computed(() =>
    props.upload.kinds.map((k) => ({ value: k.value, label: trans(`ui.upload.kind_${k.value}`) })),
);

/**
 * The `accept` attribute is a convenience for the file picker and nothing more.
 * The type that decides is read from the bytes on the server; this only stops a
 * person scrolling past the file they wanted.
 */
const acceptAttribute = computed(() => selected.value?.accepted_types.join(',') ?? '');

const summary = computed(() => ({
    total: items.value.length,
    done: items.value.filter((i) => i.state === 'done').length,
    failed: items.value.filter((i) => i.state === 'failed').length,
}));

function choose(): void {
    fileInput.value?.click();
}

function onPicked(event: Event): void {
    const input = event.target as HTMLInputElement;

    add(input.files);

    // Cleared so that choosing the same file twice in a row still fires a
    // change event, which it otherwise would not.
    input.value = '';
}

function onDrop(event: DragEvent): void {
    add(event.dataTransfer?.files ?? null);
}

function add(files: FileList | null): void {
    if (files === null) {
        return;
    }

    for (const file of Array.from(files)) {
        items.value.push({
            id: nextId++,
            name: file.name,
            size: file.size,
            state: 'queued',
            progress: 0,
            code: null,
            assetUuid: null,
            duplicate: false,
            candidateUuid: null,
        });

        queue.push({ id: nextId - 1, file });
    }
}

/** Held outside the reactive list: a `File` is not state to render. */
const queue: { id: number; file: File }[] = [];

function itemFor(id: number): UploadItem | undefined {
    return items.value.find((i) => i.id === id);
}

async function start(): Promise<void> {
    if (running.value) {
        return;
    }

    running.value = true;

    try {
        // Sequential on purpose. See the note at the top of this file.
        for (const entry of queue.filter((e) => itemFor(e.id)?.state === 'queued')) {
            await send(entry.id, entry.file);
        }
    } finally {
        running.value = false;
    }
}

async function send(id: number, file: File): Promise<void> {
    const item = itemFor(id);

    if (item === undefined) {
        return;
    }

    item.state = 'uploading';
    item.progress = 0;

    try {
        const result = props.upload.direct ? await sendDirect(item, file) : await sendRelayed(item, file);

        item.assetUuid = result.asset;
        item.duplicate = result.duplicate === true;
        item.candidateUuid = typeof result.candidate === 'string' ? result.candidate : null;
        item.state = 'done';
        item.progress = 100;
    } catch (error) {
        item.state = 'failed';
        item.code = error instanceof UploadRefused ? error.code : 'NETWORK';
    }
}

/** What the server says came of one file. */
interface UploadOutcome {
    asset: string;
    duplicate?: boolean;
    /** UPL-003: the review candidate a verified master became, if it became one. */
    candidate?: string | null;
}

/** A refusal the server named, as distinct from a connection that died. */
class UploadRefused extends Error {
    constructor(public readonly code: string) {
        super(code);
    }
}

async function readCode(response: Response): Promise<never> {
    let code = 'UPLOAD_NOT_ACCEPTABLE';

    try {
        const body = (await response.json()) as { code?: unknown };

        if (typeof body.code === 'string') {
            code = body.code;
        }
    } catch {
        // A response that is not JSON tells us nothing more than its status
        // already did.
    }

    throw new UploadRefused(code);
}

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Through the application. One request, and progress is reported by the browser
 * as the body goes out.
 */
function sendRelayed(item: UploadItem, file: File): Promise<UploadOutcome> {
    const body = new FormData();

    body.append('kind', kind.value);
    body.append('file', file);

    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();

        request.open('POST', '/assets/uploads/relay');
        request.setRequestHeader('X-CSRF-TOKEN', csrf());
        request.setRequestHeader('Accept', 'application/json');

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                // Capped below 100: the bytes being sent is not the same as the
                // server having accepted them, and a bar that sits at 100%
                // while a checksum runs reads as finished.
                item.progress = Math.min(95, Math.round((event.loaded / event.total) * 100));
            }
        });

        request.addEventListener('load', () => {
            if (request.status >= 200 && request.status < 300) {
                item.state = 'verifying';
                resolve(JSON.parse(request.responseText));

                return;
            }

            let code = 'UPLOAD_NOT_ACCEPTABLE';

            try {
                const parsed = JSON.parse(request.responseText) as { code?: unknown };

                if (typeof parsed.code === 'string') {
                    code = parsed.code;
                }
            } catch {
                // Not JSON. The status is all there is.
            }

            reject(new UploadRefused(code));
        });

        request.addEventListener('error', () => reject(new UploadRefused('NETWORK')));
        request.addEventListener('abort', () => reject(new UploadRefused('NETWORK')));

        request.send(body);
    });
}

/**
 * Straight to storage. Three steps: ask for a capability, write the object,
 * then tell the server to go and look.
 *
 * The completion call carries no size, no checksum and no media type, because
 * anything it carried would be the browser's word for it.
 */
async function sendDirect(item: UploadItem, file: File): Promise<UploadOutcome> {
    const begin = await fetch('/assets/uploads', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ kind: kind.value, filename: file.name }),
    });

    if (!begin.ok) {
        await readCode(begin);
    }

    const started = (await begin.json()) as {
        asset: string;
        upload: { url: string; headers: Record<string, string> };
    };

    item.progress = 5;

    const written = await fetch(started.upload.url, {
        method: 'PUT',
        headers: started.upload.headers,
        body: file,
    });

    if (!written.ok) {
        throw new UploadRefused('UPLOAD_NOT_ACCEPTABLE');
    }

    item.state = 'verifying';
    item.progress = 95;

    const finished = await fetch(`/assets/uploads/${started.asset}/complete`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });

    if (!finished.ok) {
        await readCode(finished);
    }

    return (await finished.json()) as UploadOutcome;
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.upload.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.upload.description') }}</p>
        </div>

        <!--
            Which path this installation is on. Stated rather than hidden: it
            changes the ceiling, and an operator who cannot see why a file was
            refused will change the wrong setting.
        -->
        <AppAlert tone="info" :title="trans(upload.direct ? 'ui.upload.direct_on' : 'ui.upload.direct_off')">
            <p v-if="selected !== null">
                {{ trans('ui.upload.limit', { size: bytes(selected.effective_maximum_bytes) }) }}
            </p>
            <p v-if="selected?.limited_by_php" class="mt-1">
                {{ trans('ui.upload.limited_by_php') }}
            </p>
            <p v-if="selected !== null" class="mt-1">
                {{
                    selected.accepted_types.length === 0
                        ? trans('ui.upload.accepted_any')
                        : trans('ui.upload.accepted_types', { types: selected.accepted_types.join(', ') })
                }}
            </p>
        </AppAlert>

        <AppCard>
            <div class="space-y-4">
                <FormField :label="trans('ui.upload.kind')" for-id="upload-kind">
                    <SelectInput id="upload-kind" v-model="kind" :options="kindOptions" :disabled="running" />
                </FormField>

                <div
                    class="rounded-control border border-dashed border-border p-6 text-center"
                    @dragover.prevent
                    @drop.prevent="onDrop"
                >
                    <input
                        ref="fileInput"
                        type="file"
                        multiple
                        class="sr-only"
                        :accept="acceptAttribute"
                        @change="onPicked"
                    />
                    <AppButton variant="primary" :disabled="running" @click="choose">
                        {{ trans('ui.upload.choose') }}
                    </AppButton>
                    <p class="mt-2 text-small text-muted">{{ trans('ui.upload.drop_hint') }}</p>
                </div>

                <div v-if="items.length > 0" class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-small text-muted">
                        {{
                            trans('ui.upload.summary', {
                                done: String(summary.done),
                                failed: String(summary.failed),
                                total: String(summary.total),
                            })
                        }}
                    </p>
                    <AppButton variant="primary" :loading="running" @click="start">
                        {{ trans('ui.upload.start') }}
                    </AppButton>
                </div>
            </div>
        </AppCard>

        <EmptyState
            v-if="items.length === 0"
            :title="trans('ui.upload.empty')"
            :description="trans('ui.upload.empty_description')"
        />

        <AppCard v-else>
            <ul class="divide-y divide-border">
                <li v-for="item in items" :key="item.id" class="flex flex-col gap-2 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-body text-foreground">{{ item.name }}</span>
                        <span class="flex items-center gap-2">
                            <span class="text-caption text-muted">{{ bytes(item.size) }}</span>
                            <!-- Through the shared badge, so an upload state
                                 is coloured by the same rules as every other
                                 status in the application. -->
                            <StatusBadge group="upload" :status="item.state.toUpperCase()" />
                        </span>
                    </div>

                    <ProgressBar
                        v-if="item.state === 'uploading' || item.state === 'verifying'"
                        :value="item.progress"
                        :max="100"
                        :label="trans(`ui.upload.${item.state}`)"
                    />

                    <!-- A code, translated here. The server never sends prose. -->
                    <p v-if="item.code !== null" class="text-small text-danger">
                        {{ trans(`ui.upload.refusal.${item.code}`) }}
                    </p>

                    <p v-if="item.duplicate" class="text-small text-muted">
                        {{ trans('ui.upload.duplicate') }}
                    </p>

                    <!-- UPL-003. Where this file goes next. A screen that
                         stored a master and then said nothing left a person
                         with a verified file and no idea what to do with it —
                         which was the literal state of the product, because
                         there was no next step to point at. -->
                    <a
                        v-if="item.candidateUuid !== null"
                        :href="`/ingestion/candidates/${item.candidateUuid}`"
                        class="text-small text-accent hover:underline"
                    >
                        {{ trans('ui.upload.review_candidate') }}
                    </a>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
