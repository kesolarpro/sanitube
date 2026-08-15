/**
 * Gaps in jsdom that the components legitimately rely on.
 *
 * `matchMedia` is not implemented at all. The theme composable asks the system
 * for its colour-scheme preference, which is correct behaviour — so this fills
 * the gap rather than the component working around a test environment.
 *
 * A plain function, not `vi.fn()`: this file is a setup file, so it runs once,
 * while `restoreMocks` resets every `vi.fn()` implementation before each test.
 * A mocked `matchMedia` would return `undefined` from the second test onward.
 */
if (typeof window.matchMedia !== 'function') {
    window.matchMedia = (query: string): MediaQueryList =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
            dispatchEvent: () => false,
        }) as unknown as MediaQueryList;
}

/**
 * The dialog and the drawer are `Teleport`ed to `document.body`, so they are
 * outside the mounted wrapper's tree and `wrapper.find()` cannot see them.
 * Events therefore go to the real node, and they bubble — which is how they
 * reach the handler on the surface's container.
 */
export function pressKeyInDialog(key: string, shiftKey = false): void {
    const dialog = document.querySelector('[role="dialog"]');

    if (dialog === null) {
        throw new Error('No dialog is open.');
    }

    dialog.dispatchEvent(new KeyboardEvent('keydown', { key, shiftKey, bubbles: true }));
}

export function openDialog(): HTMLElement | null {
    return document.querySelector<HTMLElement>('[role="dialog"]');
}

/** Settle the composable's `await nextTick()` before asserting on focus. */
export function flush(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve));
}
