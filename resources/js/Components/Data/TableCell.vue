<script setup lang="ts">
withDefaults(defineProps<{ align?: 'left' | 'right'; numeric?: boolean }>(), { align: 'left' });
</script>

<template>
    <td
        class="px-3 py-2.5 text-foreground"
        :class="[align === 'right' ? 'text-right' : 'text-left', numeric ? 'numeric' : '']"
    >
        <slot />
    </td>
</template>
