<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { trans } from '@/Support/i18n';

/**
 * Search a bounded server-side list and pick one row from it.
 *
 * Deliberately not a "load everything and filter in the browser" select. The
 * lists behind this are catalogues — an installation with a real back
 * catalogue has thousands of tracks, and shipping all of them to the browser
 * so a `<select>` can be typed into is how a picker becomes the slowest thing
 * on a page.
 *
 * The search is debounced rather than fired per keystroke, and every request
 * carries a sequence number: responses come back out of order often enough
 * that a slow "a" landing after a fast "abc" would replace the right results
 * with the wrong ones.
 *
 * The endpoint decides what is offered. This component never filters what came
 * back — a picker that hid a row the server considered valid would be a second
 * opinion about validity, in the least reliable place to keep one.
 */
const props = withDefaults(
    defineProps<{
        endpoint: string;
        label: string;
        placeholder?: string;
        /** Milliseconds of quiet before the search is sent. */
        debounce?: number;
    }>(),
    { placeholder: undefined, debounce: 250 },
);

const emit = defineEmits<{ (event: 'select', row: Record<string, unknown>): void }>();

const term = ref('');
const rows = ref<Record<string, unknown>[]>([]);
const searching = ref(false);
const failed = ref(false);

let timer: ReturnType<typeof setTimeout> | null = null;
let sequence = 0;

async function search(): Promise<void> {
    const mine = ++sequence;
    searching.value = true;
    failed.value = false;

    try {
        const url = `${props.endpoint}${props.endpoint.includes('?') ? '&' : '?'}q=${encodeURIComponent(term.value)}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (mine !== sequence) {
            return;
        }

        if (!response.ok) {
            // A refusal is not an empty catalogue. Showing "no results" for a
            // 403 tells somebody their track is missing when what happened is
            // that they were not allowed to look.
            failed.value = true;
            rows.value = [];

            return;
        }

        const body = (await response.json()) as { rows?: Record<string, unknown>[] };

        if (mine !== sequence) {
            return;
        }

        rows.value = body.rows ?? [];
    } catch {
        if (mine === sequence) {
            failed.value = true;
            rows.value = [];
        }
    } finally {
        if (mine === sequence) {
            searching.value = false;
        }
    }
}

watch(term, () => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => {
        void search();
    }, props.debounce);
});

onBeforeUnmount(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    // Nothing that lands after this component is gone may be applied.
    sequence++;
});

function choose(row: Record<string, unknown>): void {
    emit('select', row);
}

defineExpose({ search });
</script>

<template>
    <div class="space-y-2">
        <FormField :label="label">
            <TextInput v-model="term" :placeholder="placeholder ?? trans('ui.actions.search')" />
        </FormField>

        <p v-if="searching" class="text-caption text-muted">{{ trans('ui.actions.searching') }}</p>

        <p v-else-if="failed" class="text-caption text-danger">{{ trans('ui.releases.picker_failed') }}</p>

        <p v-else-if="rows.length === 0" class="text-caption text-muted">{{ trans('ui.releases.picker_empty') }}</p>

        <ul v-else class="max-h-64 divide-y divide-border overflow-y-auto rounded-control border border-border">
            <li v-for="(row, index) in rows" :key="String(row.uuid ?? index)">
                <button
                    type="button"
                    class="w-full px-3 py-2 text-left text-small text-foreground hover:bg-surface-sunken"
                    @click="choose(row)"
                >
                    <slot name="row" :row="row">{{ row.uuid }}</slot>
                </button>
            </li>
        </ul>
    </div>
</template>
