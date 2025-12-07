export type AnimationName =
  | "fade"
  | "fade-up"
  | "fade-down"
  | "fade-left"
  | "fade-right"
  | "zoom-in"
  | "zoom-out"
  | "slide-up"
  | "slide-down";

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

const DEFAULT_OPTIONS: AnimatorOptions = {
  once: true,
  rootMargin: "0px 0px -10% 0px",
  threshold: 0.25,
  watchMutations: true,
  readyClass: "aos-ready",
  activeClass: "aos-in",
  attribute: "data-aos",
};

export class ScrollAnimator {
  private observer?: IntersectionObserver;
  private mutationObserver?: MutationObserver;
  private options: AnimatorOptions;
  private elements = new Set<HTMLElement>();

  constructor(options: Partial<AnimatorOptions> = {}) {
    this.options = { ...DEFAULT_OPTIONS, ...options };
  }

  /**
   * Initializes observation for elements in the provided container (document by default).
   */
  init(container: ParentNode = document): void {
    this.disconnect();
    this.setupObserver();
    this.collectElements(container);
    this.attachMutationObserver(container);
  }

  /**
   * Re-scans a container for new elements, preserving existing observers.
   */
  refresh(container: ParentNode = document): void {
    this.collectElements(container);
  }

  /**
   * Stops observing all elements and disconnects observers.
   */
  destroy(): void {
    this.disconnect();
    this.elements.clear();
  }

  private setupObserver(): void {
    if (typeof window === "undefined") return;

    this.observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          const el = entry.target as HTMLElement;
          if (entry.isIntersecting || entry.intersectionRatio > 0) {
            this.reveal(el);
            if (this.options.once) this.observer?.unobserve(el);
          } else if (!this.options.once) {
            this.hide(el);
          }
        });
      },
      {
        rootMargin: this.options.rootMargin,
        threshold: this.options.threshold,
      },
    );
  }

  private attachMutationObserver(container: ParentNode): void {
    if (!this.options.watchMutations || typeof MutationObserver === "undefined") {
      return;
    }

    this.mutationObserver?.disconnect();
    this.mutationObserver = new MutationObserver((records) => {
      const addedNodes: HTMLElement[] = [];

      records.forEach((record) => {
        record.addedNodes.forEach((node) => {
          if (node instanceof HTMLElement) {
            if (node.hasAttribute(this.options.attribute)) {
              addedNodes.push(node);
            }

            node.querySelectorAll(`[${this.options.attribute}]`).forEach((child) => {
              if (child instanceof HTMLElement) addedNodes.push(child);
            });
          }
        });
      });

      if (addedNodes.length) {
        addedNodes.forEach((node) => this.prepareElement(node));
      }
    });

    this.mutationObserver.observe(container, { childList: true, subtree: true });
  }

  private disconnect(): void {
    this.observer?.disconnect();
    this.mutationObserver?.disconnect();
  }

  private collectElements(container: ParentNode): void {
    if (typeof document === "undefined") return;

    const elements = Array.from(
      container.querySelectorAll<HTMLElement>(`[${this.options.attribute}]`),
    );

    elements.forEach((element) => this.prepareElement(element));
  }

  private prepareElement(element: HTMLElement): void {
    if (this.elements.has(element)) return;
    if (!element.hasAttribute(this.options.attribute)) return;

    element.classList.add(this.options.readyClass);
    this.applyTiming(element);
    this.observe(element);
  }

  private observe(element: HTMLElement): void {
    if (!this.observer) return;
    this.elements.add(element);
    this.observer.observe(element);
  }

  private applyTiming(element: HTMLElement): void {
    const duration = element.getAttribute("data-aos-duration");
    const delay = element.getAttribute("data-aos-delay");
    const easing = element.getAttribute("data-aos-easing");

    if (duration) {
      element.style.setProperty("--aos-duration", `${duration}ms`);
    }
    if (delay) {
      element.style.setProperty("--aos-delay", `${delay}ms`);
    }
    if (easing) {
      element.style.setProperty("--aos-easing", easing);
    }
  }

  private reveal(element: HTMLElement): void {
    element.classList.add(this.options.activeClass);
  }

  private hide(element: HTMLElement): void {
    element.classList.remove(this.options.activeClass);
  }
}

/**
 * Convenience factory to create a new animator instance.
 */
export function createAnimator(options: Partial<AnimatorOptions> = {}): ScrollAnimator {
  return new ScrollAnimator(options);
}

// Auto-init when running in the browser to reduce setup for script tag usage.
if (typeof document !== "undefined") {
  const autoAnimator = new ScrollAnimator();
  autoAnimator.init();
  // Expose globally for imperative refreshes from tools like Livewire.
  // @ts-expect-error - attaching to window for IIFE builds.
  if (typeof window !== "undefined") window.AOSLite = autoAnimator;
}

export default ScrollAnimator;
