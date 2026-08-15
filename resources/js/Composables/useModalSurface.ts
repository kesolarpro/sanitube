import { nextTick, onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * The behaviour that makes `role="dialog" aria-modal="true"` true.
 *
 * Declaring those attributes tells assistive technology that the rest of the
 * page is unreachable. Nothing enforces that on its own, so a dialog that
 * declares them without implementing them is worse than one that declares
 * nothing: a screen reader announces a modal, and the user tabs straight out of
 * it into a page they cannot see.
 *
 * Both surfaces that make the claim — AppModal and the layout's mobile drawer —
 * share this one implementation. Two focus traps would drift apart, and the
 * second one is always the one nobody tests.
 *
 * What it does, in order:
 *
 *   - remembers what had focus, so it can be given back;
 *   - locks background scroll and marks the application root `inert`;
 *   - moves focus to the first control inside the surface;
 *   - wraps Tab and Shift+Tab at the ends rather than letting them escape;
 *   - closes on Escape;
 *   - restores focus, scroll and interactivity on close *and* on unmount.
 */

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

/*
 * Module-level, and deliberately so. Two surfaces can be open at once — a
 * confirmation dialog raised from inside the drawer — and a per-component
 * boolean would let whichever closed first hand scrolling back to a page that
 * is still covered. Counting means the background is released exactly once,
 * by the last surface to close.
 *
 * The saved overflow is the value from before the *first* lock, not `''`:
 * writing an empty string would quietly discard a page-level overflow set for
 * some other reason.
 */
let openSurfaces = 0;
let overflowBeforeFirstLock = '';

function applicationRoot(): HTMLElement | null {
    return document.getElementById('app');
}

function lockBackground(): void {
    if (openSurfaces === 0) {
        overflowBeforeFirstLock = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        // `inert` is what actually removes the page behind from the tab order
        // and the accessibility tree. The focus trap below is the belt; this is
        // the braces, and it also covers pointer and screen-reader access that
        // a keydown handler cannot.
        applicationRoot()?.setAttribute('inert', '');
    }

    openSurfaces += 1;
}

function unlockBackground(): void {
    if (openSurfaces === 0) {
        return;
    }

    openSurfaces -= 1;

    if (openSurfaces === 0) {
        document.body.style.overflow = overflowBeforeFirstLock;
        applicationRoot()?.removeAttribute('inert');
    }
}

/**
 * Test seam. The counter is module state, so a test that unmounts a component
 * mid-open would otherwise leak a lock into the next test and make failures
 * depend on file order.
 */
export function resetModalSurfaces(): void {
    openSurfaces = 0;
    overflowBeforeFirstLock = '';
    document.body.style.overflow = '';
    applicationRoot()?.removeAttribute('inert');
}

export function backgroundIsLocked(): boolean {
    return openSurfaces > 0;
}

export function useModalSurface(
    isOpen: () => boolean,
    container: Ref<HTMLElement | null>,
    close: () => void,
): { onKeydown: (event: KeyboardEvent) => void } {
    let previouslyFocused: HTMLElement | null = null;
    let holdsLock = false;

    function focusables(): HTMLElement[] {
        return container.value === null
            ? []
            : Array.from(container.value.querySelectorAll<HTMLElement>(FOCUSABLE));
    }

    function release(): void {
        if (!holdsLock) {
            return;
        }

        holdsLock = false;
        unlockBackground();
    }

    watch(isOpen, async (open) => {
        if (open) {
            if (holdsLock) {
                return;
            }

            previouslyFocused = document.activeElement as HTMLElement | null;
            lockBackground();
            holdsLock = true;

            await nextTick();
            (focusables()[0] ?? container.value)?.focus();

            return;
        }

        release();

        // Back where they were, not at the top of the document.
        previouslyFocused?.focus();
        previouslyFocused = null;
    }, { immediate: true });

    onBeforeUnmount(() => {
        // A surface unmounted while open — a route change, a parent v-if — must
        // not leave the page permanently unscrollable and inert. This is the
        // stale-lock case, and it is silent when it happens.
        release();
        previouslyFocused?.focus();
        previouslyFocused = null;
    });

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            event.stopPropagation();
            close();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const items = focusables();
        const first = items[0];
        const last = items[items.length - 1];

        if (!first || !last) {
            // Nothing focusable inside: keep focus on the surface itself rather
            // than letting Tab fall through to the page behind.
            event.preventDefault();
            container.value?.focus();

            return;
        }

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    return { onKeydown };
}
