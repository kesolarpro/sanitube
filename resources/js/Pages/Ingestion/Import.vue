<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ProgressBar from '@/Components/Ui/ProgressBar.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { bytes } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { DepositItem, ImportCapability } from '@/Types/ingestion';

/**
 * Bringing a catalogue in.
 *
 * Separate from the single-file upload screen because the two produce
 * different things. Uploading makes one asset immediately, which is what
 * somebody wants for a cover. Importing deposits many files into the inbox and
 * then starts a **batch** — the only shape in which nine hundred masters come
 * in without an HTTP request having to survive it.
 *
 * **Two phases, and the boundary between them is the point.** Depositing is
 * the browser's job and lives here. Everything after — fetching, hashing,
 * verifying, de-duplicating, making a candidate, retrying — belongs to the
 * batch, runs in a queue, and survives this tab being closed. So this screen
 * deliberately stops at "the files are in"; the batch screens take over, and
 * they already know how to say IMPORTED, DUPLICATE, NEEDS_REVIEW and FAILED.
 *
 * **The waiting list is the server's, not this component's.** It is read from
 * storage on every render. A screen that kept its queue in JavaScript would
 * lose several hundred deposits to one accidental reload with no way to find
 * out what had actually landed. What genuinely cannot survive a reload is a
 * file that was picked and never uploaded — the browser holds the only handle
 * to it — and that is said plainly rather than papered over.
 *
 * **Concurrency is bounded and configured.** The right number is a property of
 * the host: an object store happily takes six, a shared cPanel account
 * relaying through PHP starts refusing connections long before that.
 */
/*
 * Named `capability` rather than `import`: a prop called `import` cannot be
 * read from a Vue template at all, because the expression parser reads the
 * bare word as the `import` meta-property. vue-tsc accepts it; the build does
 * not.
 */
const props = defineProps<{ capability: ImportCapability }>();

const page = usePage<SharedProps>();

const items = ref<DepositItem[]>([]);
const running = ref(false);
const cancelling = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const confirmingDiscard = ref(false);

let nextId = 0;

/** Held outside the reactive list: a `File` is not state to render. */
const handles = new Map<number, File>();

/** In-flight requests, so cancelling actually stops the bytes. */
const inFlight = new Map<number, XMLHttpRequest | AbortController>();

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.import ?? null;
});

const waiting = computed(() => props.capability.waiting.files);

const summary = computed(() => {
    const done = items.value.filter((i) => i.state === 'UPLOADED').length;
    const failed = items.value.filter((i) => i.state === 'FAILED').length;

    return { total: items.value.length, done, failed };
});

/** Global progress, weighted by bytes rather than by file count. */
const overall = computed(() => {
    const total = items.value.reduce((sum, i) => sum + i.size, 0);

    if (total === 0) {
        return 0;
    }

    const moved = items.value.reduce((sum, i) => sum + (i.size * i.progress) / 100, 0);

    return Math.round((moved / total) * 100);
});

const retryable = computed(() => items.value.filter((i) => i.state === 'FAILED').length);

/**
 * Whether an import may start, and why not.
 *
 * The batch limit is the server's and is stated here so the refusal arrives
 * before several hundred files have been uploaded, rather than after.
 */
const tooManyWaiting = computed(() => waiting.value.length > props.capability.max_batch);

const canStart = computed(() => waiting.value.length > 0 && !tooManyWaiting.value && !running.value);

function choose(): void {
    fileInput.value?.click();
}

function onPicked(event: Event): void {
    const input = event.target as HTMLInputElement;

    add(input.files);

    // Cleared so choosing the same files twice in a row still fires a change
    // event, which it otherwise would not.
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
        const id = nextId++;

        items.value.push({
            id,
            name: file.name,
            size: file.size,
            type: file.type,
            state: 'WAITING',
            progress: 0,
            code: null,
            reference: null,
        });

        handles.set(id, file);
    }
}

function clearPicked(): void {
    items.value = [];
    handles.clear();
}

/* ------------------------------------------------------------- depositing */

async function start(): Promise<void> {
    if (running.value) {
        return;
    }

    running.value = true;
    cancelling.value = false;

    try {
        await drain(items.value.filter((i) => i.state === 'WAITING' || i.state === 'FAILED'));
    } finally {
        running.value = false;
        cancelling.value = false;

        // Re-read the inbox from the server rather than appending locally.
        // What is waiting is a fact about storage, and this component is not
        // entitled to an opinion about it.
        router.reload({ only: ['capability'] });
    }
}

/**
 * A fixed-width pool rather than `Promise.all` over everything.
 *
 * Nine hundred concurrent requests is not faster; it is the point at which the
 * host starts refusing connections and the import fails in a way that looks
 * like the files were bad.
 */
async function drain(queue: DepositItem[]): Promise<void> {
    const pending = [...queue];
    const width = Math.max(1, props.capability.concurrency);

    const workers = Array.from({ length: Math.min(width, pending.length) }, async () => {
        for (;;) {
            const item = pending.shift();

            if (item === undefined || cancelling.value) {
                return;
            }

            await deposit(item);
        }
    });

    await Promise.all(workers);
}

async function deposit(item: DepositItem): Promise<void> {
    const file = handles.get(item.id);

    if (file === undefined) {
        // Picked in a previous page life. The handle is gone and no server can
        // return it — said plainly rather than retried forever.
        item.state = 'FAILED';
        item.code = 'FILE_HANDLE_LOST';

        return;
    }

    item.state = 'UPLOADING';
    item.progress = 0;
    item.code = null;

    try {
        item.reference = props.capability.direct ? await sendDirect(item, file) : await sendRelayed(item, file);
        item.state = 'UPLOADED';
        item.progress = 100;
    } catch (error) {
        item.state = cancelling.value ? 'CANCELLED' : 'FAILED';
        item.code = error instanceof DepositRefused ? error.code : 'NETWORK';
    } finally {
        inFlight.delete(item.id);
    }
}

/** A refusal the server named, as distinct from a connection that died. */
class DepositRefused extends Error {
    constructor(public readonly code: string) {
        super(code);
    }
}

function csrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/** Through the application. The path a shared account without object storage has. */
function sendRelayed(item: DepositItem, file: File): Promise<string> {
    const body = new FormData();

    body.append('file', file);

    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();

        inFlight.set(item.id, request);

        request.open('POST', '/ingestion/import/relay');
        request.setRequestHeader('X-CSRF-TOKEN', csrf());
        request.setRequestHeader('Accept', 'application/json');

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                // Capped below 100: the bytes being sent is not the same as
                // the server having written them.
                item.progress = Math.min(95, Math.round((event.loaded / event.total) * 100));
            }
        });

        request.addEventListener('load', () => {
            if (request.status >= 200 && request.status < 300) {
                resolve((JSON.parse(request.responseText) as { reference: string }).reference);

                return;
            }

            reject(new DepositRefused(readCode(request.responseText)));
        });

        request.addEventListener('error', () => reject(new DepositRefused('NETWORK')));
        request.addEventListener('abort', () => reject(new DepositRefused('CANCELLED')));

        request.send(body);
    });
}

function readCode(body: string): string {
    try {
        const parsed = JSON.parse(body) as { code?: unknown };

        return typeof parsed.code === 'string' ? parsed.code : 'DEPOSIT_FAILED';
    } catch {
        // Not JSON. The status is all there is.
        return 'DEPOSIT_FAILED';
    }
}

/**
 * Straight to storage. A capability for one key, then the bytes.
 *
 * One capability per file rather than one request per batch of capabilities:
 * a round of fifty minted at once would start expiring while the fiftieth file
 * was still uploading.
 */
async function sendDirect(item: DepositItem, file: File): Promise<string> {
    const minted = await fetch('/ingestion/import/capabilities', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ files: [{ name: file.name, type: file.type }] }),
    });

    if (!minted.ok) {
        throw new DepositRefused(readCode(await minted.text()));
    }

    const answer = (await minted.json()) as {
        files: { reference?: string; code?: string; upload?: { url: string; headers: Record<string, string> } }[];
    };

    const first = answer.files[0];

    if (first === undefined || first.reference === undefined || first.upload === undefined) {
        throw new DepositRefused(first?.code ?? 'DEPOSIT_FAILED');
    }

    item.progress = 5;

    const controller = new AbortController();
    inFlight.set(item.id, controller);

    const written = await fetch(first.upload.url, {
        method: 'PUT',
        headers: first.upload.headers,
        body: file,
        signal: controller.signal,
    });

    if (!written.ok) {
        throw new DepositRefused('DEPOSIT_FAILED');
    }

    return first.reference;
}

function cancel(): void {
    cancelling.value = true;

    for (const request of inFlight.values()) {
        request.abort();
    }

    inFlight.clear();
}

/* ---------------------------------------------------------------- the batch */

const starting = ref(false);

function startImport(): void {
    starting.value = true;

    router.post(
        '/ingestion/batches',
        {
            source: 'MANUAL_UPLOAD',
            references: waiting.value.map((file) => file.reference),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                starting.value = false;
            },
        },
    );
}

function discard(): void {
    confirmingDiscard.value = false;

    router.delete('/ingestion/import/inbox', { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.import.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.import.description') }}</p>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans(`ui.import.refusal.${refusal}`)" />

        <!-- Which path this installation is on. Stated rather than hidden: it
             decides whether nine hundred masters transit PHP. -->
        <AppAlert tone="info" :title="trans(capability.direct ? 'ui.import.direct_on' : 'ui.import.direct_off')">
            <p>{{ trans('ui.import.limit', { size: bytes(capability.maximum_bytes) }) }}</p>
            <p class="mt-1">
                {{ trans('ui.import.batching', { batch: String(capability.max_batch), at_once: String(capability.concurrency) }) }}
            </p>
        </AppAlert>

        <!-- 1. Pick the files. -->
        <AppCard>
            <template #header>{{ trans('ui.import.step_choose') }}</template>
            <template #description>{{ trans('ui.import.step_choose_description') }}</template>

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
                    :accept="capability.accepted_types.join(',')"
                    @change="onPicked"
                />
                <AppButton variant="primary" :disabled="running" @click="choose">
                    {{ trans('ui.import.choose') }}
                </AppButton>
                <p class="mt-2 text-small text-muted">{{ trans('ui.import.drop_hint') }}</p>
            </div>

            <div v-if="items.length > 0" class="mt-4 space-y-3">
                <ProgressBar
                    v-if="running"
                    :value="overall"
                    :max="100"
                    :label="trans('ui.import.overall')"
                />

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-small text-muted">
                        {{
                            trans('ui.import.summary', {
                                done: String(summary.done),
                                failed: String(summary.failed),
                                total: String(summary.total),
                            })
                        }}
                    </p>
                    <span class="flex flex-wrap gap-2">
                        <AppButton v-if="running" variant="ghost" @click="cancel">
                            {{ trans('ui.import.cancel') }}
                        </AppButton>
                        <AppButton v-if="!running && items.length > 0" variant="ghost" @click="clearPicked">
                            {{ trans('ui.import.clear') }}
                        </AppButton>
                        <AppButton variant="primary" :loading="running" @click="start">
                            {{ retryable > 0 && !running ? trans('ui.import.retry_failed') : trans('ui.import.deposit') }}
                        </AppButton>
                    </span>
                </div>

                <ul class="divide-y divide-border">
                    <li v-for="item in items" :key="item.id" class="flex flex-col gap-1.5 py-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="truncate text-body text-foreground">{{ item.name }}</span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span class="text-caption text-muted">{{ bytes(item.size) }}</span>
                                <StatusBadge group="deposit" :status="item.state" />
                            </span>
                        </div>

                        <ProgressBar
                            v-if="item.state === 'UPLOADING'"
                            :value="item.progress"
                            :max="100"
                            :label="trans('ui.import.state.UPLOADING')"
                        />

                        <!-- A code, translated here. The server never sends
                             prose: its sentences name storage keys. -->
                        <p v-if="item.code !== null" class="text-small text-danger">
                            {{ trans(`ui.import.refusal.${item.code}`) }}
                        </p>
                    </li>
                </ul>
            </div>
        </AppCard>

        <!-- 2. What is actually in the inbox. Read from storage, so a reload
             loses nothing that had already landed. -->
        <AppCard>
            <template #header>{{ trans('ui.import.step_waiting') }}</template>
            <template #description>{{ trans('ui.import.step_waiting_description') }}</template>

            <AppAlert
                v-if="!capability.waiting.available"
                tone="danger"
                :title="trans('ui.import.inbox_unreadable')"
            />

            <EmptyState
                v-else-if="waiting.length === 0"
                :title="trans('ui.import.inbox_empty')"
                :description="trans('ui.import.inbox_empty_description')"
            />

            <div v-else class="space-y-3">
                <AppAlert
                    v-if="tooManyWaiting"
                    tone="warning"
                    :title="trans('ui.import.too_many_waiting', {
                        waiting: String(waiting.length),
                        batch: String(capability.max_batch),
                    })"
                />

                <p class="text-small text-muted">
                    {{ trans('ui.import.waiting_count', { count: String(waiting.length) }) }}
                </p>

                <ul class="max-h-72 divide-y divide-border overflow-y-auto">
                    <li v-for="file in waiting" :key="file.reference" class="flex items-center justify-between gap-3 py-2">
                        <span class="truncate text-small text-foreground">{{ file.name }}</span>
                        <span class="shrink-0 text-caption text-muted">
                            {{ file.byte_size === null ? trans('ui.import.size_unknown') : bytes(file.byte_size) }}
                        </span>
                    </li>
                </ul>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <AppButton variant="ghost" @click="confirmingDiscard = true">
                        {{ trans('ui.import.discard') }}
                    </AppButton>
                    <AppButton variant="primary" :disabled="!canStart" :loading="starting" @click="startImport">
                        {{ trans('ui.import.start_batch') }}
                    </AppButton>
                </div>
            </div>
        </AppCard>

        <!-- 3. Where it goes next. The batch owns everything from here, and it
             survives this tab being closed. -->
        <AppCard>
            <template #header>{{ trans('ui.import.step_after') }}</template>
            <template #description>{{ trans('ui.import.step_after_description') }}</template>

            <div class="flex flex-wrap gap-3">
                <Link href="/ingestion/batches" class="text-small text-accent hover:underline">
                    {{ trans('ui.navigation.ingestion') }}
                </Link>
                <Link href="/ingestion/candidates" class="text-small text-accent hover:underline">
                    {{ trans('ui.navigation.candidates') }}
                </Link>
            </div>
        </AppCard>

        <ConfirmDialog
            :open="confirmingDiscard"
            :title="trans('ui.import.discard')"
            :description="trans('ui.import.discard_description')"
            :confirm-label="trans('ui.import.discard_confirm')"
            destructive
            @cancel="confirmingDiscard = false"
            @confirm="discard"
        />
    </div>
</template>
