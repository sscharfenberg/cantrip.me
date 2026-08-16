/**
 * Controllable stand-ins for the observer APIs jsdom does not implement.
 *
 * A plain no-op is enough to keep `onMounted` from throwing, but it makes the
 * behaviour those observers drive untestable — `useResponsiveColumns` would be
 * stuck at one column forever, and `useStickyNav` could never become stuck.
 * These record every instance so a spec can fire the callback itself:
 *
 * ```ts
 * const [columns] = withSetup(() => useResponsiveColumns(sections, config));
 * resizeObservers.at(-1)!.trigger({ inlineSize: 1200 });
 * ```
 *
 * `setup.ts` installs them as the globals and clears the registries before
 * every test, so no spec sees another's instances.
 */

/** One recorded `ResizeObserver`, plus a helper to fire its callback. */
export interface RecordedResizeObserver {
    targets: Element[];
    disconnected: boolean;
    /** Fire the observer callback with a single entry of the given box size. */
    trigger: (size: { inlineSize: number; blockSize?: number }) => void;
}

/** One recorded `IntersectionObserver`, plus a helper to fire its callback. */
export interface RecordedIntersectionObserver {
    targets: Element[];
    disconnected: boolean;
    options: IntersectionObserverInit | undefined;
    /** Fire the observer callback with one entry per given target. */
    trigger: (entries: { target: Element; isIntersecting: boolean }[]) => void;
}

/** Every `ResizeObserver` constructed during the current test, in order. */
export const resizeObservers: RecordedResizeObserver[] = [];

/** Every `IntersectionObserver` constructed during the current test, in order. */
export const intersectionObservers: RecordedIntersectionObserver[] = [];

/** Forget every recorded observer. Called from the global `beforeEach`. */
export function clearRecordedObservers(): void {
    resizeObservers.length = 0;
    intersectionObservers.length = 0;
}

/**
 * Refuse to fire a callback on an observer that is not actually watching
 * anything.
 *
 * Both cases would otherwise pass silently and mean nothing: a `trigger()`
 * after unmount invokes the callback with an empty entry list, and a
 * `trigger()` before the element ref was bound does the same. A spec written
 * around either would be green and prove nothing.
 */
const assertLive = (record: { targets: Element[]; disconnected: boolean }, kind: string): void => {
    if (record.disconnected) {
        throw new Error(`${kind}.trigger() called after disconnect() — the observer's component has unmounted.`);
    }
    if (record.targets.length === 0) {
        throw new Error(`${kind}.trigger() called while nothing is observed — was the element ref bound?`);
    }
};

export class FakeResizeObserver {
    private record: RecordedResizeObserver;

    constructor(callback: ResizeObserverCallback) {
        this.record = {
            targets: [],
            disconnected: false,
            trigger: ({ inlineSize, blockSize = 0 }) => {
                assertLive(this.record, "ResizeObserver");
                const entries = this.record.targets.map(target => ({
                    target,
                    contentBoxSize: [{ inlineSize, blockSize }],
                    borderBoxSize: [{ inlineSize, blockSize }],
                    devicePixelContentBoxSize: [{ inlineSize, blockSize }],
                    contentRect: { width: inlineSize, height: blockSize } as DOMRectReadOnly
                }));
                callback(entries as unknown as ResizeObserverEntry[], this as unknown as ResizeObserver);
            }
        };
        resizeObservers.push(this.record);
    }

    observe(target: Element): void {
        this.record.targets.push(target);
    }

    unobserve(target: Element): void {
        this.record.targets = this.record.targets.filter(t => t !== target);
    }

    disconnect(): void {
        this.record.targets = [];
        this.record.disconnected = true;
    }
}

export class FakeIntersectionObserver {
    private record: RecordedIntersectionObserver;

    constructor(callback: IntersectionObserverCallback, options?: IntersectionObserverInit) {
        this.record = {
            targets: [],
            disconnected: false,
            options,
            trigger: entries => {
                assertLive(this.record, "IntersectionObserver");
                callback(
                    entries.map(({ target, isIntersecting }) => ({
                        target,
                        isIntersecting,
                        intersectionRatio: isIntersecting ? 1 : 0,
                        time: 0
                    })) as unknown as IntersectionObserverEntry[],
                    this as unknown as IntersectionObserver
                );
            }
        };
        intersectionObservers.push(this.record);
    }

    observe(target: Element): void {
        this.record.targets.push(target);
    }

    unobserve(target: Element): void {
        this.record.targets = this.record.targets.filter(t => t !== target);
    }

    disconnect(): void {
        this.record.targets = [];
        this.record.disconnected = true;
    }

    takeRecords(): [] {
        return [];
    }
}
