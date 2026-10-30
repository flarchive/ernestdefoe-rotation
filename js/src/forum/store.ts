import { boards, type BoardKind, type Boards, type Period } from '../common/api';

declare const m: import('mithril').Static;

/**
 * Board data for widgets, at module level.
 *
 * 🚨 Widget hosts re-create their widgets on every redraw, so a widget that
 * fetched in `oninit` would ask again forever. Widgets only say what they
 * want; the store asks once, for every widget on the page together (one
 * request per period and size), and keeps the answer for five minutes.
 */
const TTL = 5 * 60 * 1000;

const cache = new Map<string, { at: number; rows: any[] }>();
const asked = new Set<string>();
let queued: { kind: BoardKind; period: Period; limit: number }[] = [];
let scheduled = false;

const key = (kind: BoardKind, period: Period, limit: number) => `${kind}|${kind === 'now' ? '' : period}|${limit}`;

/** The rows, or undefined while they are on their way. */
export function want<K extends BoardKind>(kind: K, period: Period, limit: number): NonNullable<Boards[K]> | undefined {
  const k = key(kind, period, limit);
  const hit = cache.get(k);

  if ((!hit || Date.now() - hit.at > TTL) && !asked.has(k)) {
    asked.add(k);
    queued.push({ kind, period, limit });
    if (!scheduled) {
      scheduled = true;
      setTimeout(flush, 0);
    }
  }

  return hit?.rows as any;
}

function flush(): void {
  scheduled = false;
  const groups = new Map<string, { kinds: BoardKind[]; period: Period; limit: number }>();
  queued.splice(0).forEach(({ kind, period, limit }) => {
    const g = `${period}|${limit}`;
    if (!groups.has(g)) groups.set(g, { kinds: [], period, limit });
    groups.get(g)!.kinds.push(kind);
  });

  groups.forEach(({ kinds, period, limit }) => {
    boards(kinds, period, limit)
      .then((res) => kinds.forEach((kind) => cache.set(key(kind, period, limit), { at: Date.now(), rows: (res.data as any)[kind] || [] })))
      .catch(() => kinds.forEach((kind) => cache.set(key(kind, period, limit), { at: Date.now(), rows: [] })))
      .finally(() => {
        kinds.forEach((kind) => asked.delete(key(kind, period, limit)));
        m.redraw();
      });
  });
}
