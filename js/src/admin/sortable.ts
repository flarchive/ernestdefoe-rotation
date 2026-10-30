/**
 * Drag-to-reorder for a vertical list, by a handle, with pointer events so a
 * mouse, a pen and a finger all work. The array is reordered in place as the
 * row moves; `onEnd` runs once when it is dropped. Arrow keys on the handle
 * move a row one place.
 *
 * While a drag is on, the list carries `is-sorting`, which the stylesheet
 * uses to fold every question to its first line: tall cards are hard to
 * drag past each other.
 */
export default class Sortable<T> {
  dragging: number | null = null;

  constructor(
    private items: () => T[],
    private onEnd: () => void,
    /** What a screen reader calls a row that can be moved, in the reader's language. */
    private roleDescription: string
  ) {}

  handle(index: number): Record<string, unknown> {
    return {
      'data-sort-handle': index,
      tabindex: 0,
      role: 'button',
      'aria-roledescription': this.roleDescription,
      onpointerdown: (e: PointerEvent) => this.start(e, index),
      onkeydown: (e: KeyboardEvent) => {
        const step = ({ ArrowUp: -1, ArrowDown: 1 } as Record<string, number>)[e.key];
        const list = this.items();
        const to = index + (step || 0);
        if (!step || to < 0 || to >= list.length) return;
        e.preventDefault();
        list.splice(to, 0, ...list.splice(index, 1));
        this.onEnd();
        const root = (e.target as HTMLElement).closest('[data-sortable]');
        setTimeout(() => (root?.querySelector(`[data-sort-handle="${to}"]`) as HTMLElement | null)?.focus(), 0);
      },
    };
  }

  private start(e: PointerEvent, index: number): void {
    if (e.button !== 0) return;
    const root = (e.currentTarget as HTMLElement).closest('[data-sortable]') as HTMLElement | null;
    if (!root) return;
    e.preventDefault();

    this.dragging = index;
    const pointer = e.pointerId;
    root.classList.add('is-sorting');

    const rows = () => Array.from(root.children) as HTMLElement[];

    const move = (ev: PointerEvent) => {
      if (ev.pointerId !== pointer || this.dragging === null) return;

      // Its place is after every other row whose middle the pointer is below.
      let to = 0;
      rows().forEach((row, i) => {
        const r = row.getBoundingClientRect();
        if (i !== this.dragging && ev.clientY > r.top + r.height / 2) to++;
      });

      if (to !== this.dragging) {
        const list = this.items();
        list.splice(to, 0, ...list.splice(this.dragging, 1));
        this.dragging = to;
        // Synchronously, so the next move measures rows in their new order.
        m.redraw.sync();
      }
    };

    // On the window, not the handle: a keyed redraw that moves the dragged
    // row in the DOM drops pointer capture on it.
    const end = (ev: PointerEvent) => {
      if (ev.pointerId !== pointer) return;
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', end);
      window.removeEventListener('pointercancel', end);
      root.classList.remove('is-sorting');
      this.dragging = null;
      this.onEnd();
      m.redraw();
    };

    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
    m.redraw();
  }
}
