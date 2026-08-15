<script setup lang="ts">
defineProps<{ id?: string; describedBy?: string; invalid?: boolean; rows?: number; placeholder?: string; disabled?: boolean }>();

const model = defineModel<string | null>();
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        :rows="rows ?? 4"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedBy"
        class="w-full rounded-control border bg-surface px-3 py-2 text-body text-foreground placeholder:text-subtle disabled:opacity-50"
        :class="invalid ? 'border-danger' : 'border-border'"
    />
</template>
