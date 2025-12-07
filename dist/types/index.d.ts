export type AnimationName = "fade" | "fade-up" | "fade-down" | "fade-left" | "fade-right" | "zoom-in" | "zoom-out" | "slide-up" | "slide-down";
export interface AnimatorOptions {
    /**
     * When true elements stay visible after their first appearance.
     */
    once: boolean;
    /**
     * Root margin passed to the IntersectionObserver.
     */
    rootMargin: string;
    /**
     * Threshold passed to the IntersectionObserver.
     */
    threshold: number;
    /**
     * Adds a MutationObserver to refresh the list when DOM changes (e.g. Livewire).
     */
    watchMutations: boolean;
    /**
     * Class added to elements once their styles are prepared.
     */
    readyClass: string;
    /**
     * Class added to elements when they become visible.
     */
    activeClass: string;
    /**
     * Attribute used to locate animatable elements.
     */
    attribute: string;
}
export declare class ScrollAnimator {
    private observer?;
    private mutationObserver?;
    private options;
    private elements;
    constructor(options?: Partial<AnimatorOptions>);
    /**
     * Initializes observation for elements in the provided container (document by default).
     */
    init(container?: ParentNode): void;
    /**
     * Re-scans a container for new elements, preserving existing observers.
     */
    refresh(container?: ParentNode): void;
    /**
     * Stops observing all elements and disconnects observers.
     */
    destroy(): void;
    private setupObserver;
    private attachMutationObserver;
    private disconnect;
    private collectElements;
    private prepareElement;
    private observe;
    private applyTiming;
    private reveal;
    private hide;
}
/**
 * Convenience factory to create a new animator instance.
 */
export declare function createAnimator(options?: Partial<AnimatorOptions>): ScrollAnimator;
export default ScrollAnimator;
