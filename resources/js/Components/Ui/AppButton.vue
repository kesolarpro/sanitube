<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * The one button.
 *
 * Renders as <button>, <a> or Inertia's <Link> depending on what it is *for*,
 * which is the part that matters for accessibility: a link styled as a button
 * still has to be a link, or it will not open in a new tab and will not be
 * announced as navigation.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'sm' | 'md';
        href?: string;
        external?: boolean;
        type?: 'button' | 'submit';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    { variant: 'secondary', size: 'md', type: 'button' },
);

const component = computed(() => (props.href ? (props.external ? 'a' : Link) : 'button'));

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-control font-medium whitespace-nowrap',
    'transition-colors duration-(--motion-fast) ease-(--motion-ease)',
    // Disabled state is expressed on the element, never by removing the
    // element: a button that vanishes when unavailable is a layout that jumps.
    'disabled:pointer-events-none disabled:opacity-50',
    props.block ? 'w-full' : '',
    props.size === 'sm' ? 'h-8 px-3 text-small' : 'h-9 px-4 text-body',
    {
        primary: 'bg-accent text-accent-foreground hover:bg-accent-hover',
        secondary: 'bg-surface text-foreground border border-border hover:bg-surface-sunken',
        ghost: 'text-muted hover:bg-surface-sunken hover:text-foreground',
        danger: 'bg-danger text-white hover:opacity-90',
    }[props.variant],
]);
</script>

<template>
    <component
        :is="component"
        :class="classes"
        :href="href"
        :type="href ? undefined : type"
        :disabled="href ? undefined : disabled || loading"
        :aria-busy="loading || undefined"
        :rel="external ? 'noopener noreferrer' : undefined"
        :target="external ? '_blank' : undefined"
    >
        <svg
            v-if="loading"
            class="size-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z" />
        </svg>
        <slot />
    </component>
</template>
