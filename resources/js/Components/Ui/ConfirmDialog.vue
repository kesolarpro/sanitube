<script setup lang="ts">
import AppModal from './AppModal.vue';
import AppButton from './AppButton.vue';
import { trans } from '@/Support/i18n';

/**
 * "Are you sure?" — for the cases where the answer matters.
 *
 * `destructive` is not styling. SaniTube has one genuinely irreversible action
 * — handing a release to a distributor — and several that are merely hard to
 * undo. This dialog exists so that the irreversible one cannot be a single
 * click, and so the confirm button says what will happen rather than "OK".
 */
defineProps<{ open: boolean; title: string; description?: string; confirmLabel: string; destructive?: boolean; busy?: boolean }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <AppModal :open="open" :title="title" :description="description" @close="emit('cancel')">
        <slot />

        <template #footer>
            <AppButton variant="ghost" @click="emit('cancel')">{{ trans('ui.actions.cancel') }}</AppButton>
            <AppButton :variant="destructive ? 'danger' : 'primary'" :loading="busy" @click="emit('confirm')">
                {{ confirmLabel }}
            </AppButton>
        </template>
    </AppModal>
</template>
