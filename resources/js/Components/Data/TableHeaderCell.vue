<script setup lang="ts">
withDefaults(defineProps<{ align?: 'left' | 'right' }>(), { align: 'left' });
</script>

<template>
    <th
        scope="col"
        class="px-3 py-2 text-caption font-medium tracking-wide text-muted uppercase"
        :class="align === 'right' ? 'text-right' : 'text-left'"
    >
        <slot />
    </th>
</template>
