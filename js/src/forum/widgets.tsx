import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type { BoardKind, Period } from '../common/api';
import { t } from '../common/format';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';
import { want } from './store';
import { ArtistList, ListenerList, NowPlayingList, TrackList } from './components/Lists';

declare const m: import('mithril').Static;

const EXT = 'ernestdefoe-rotation';

interface Def {
  kind: BoardKind;
  /** fof/forum-widgets-core key, and Bespoke type `rotation-<kind>` */
  key: string;
  icon: string;
  title: string;
}

export const WIDGETS: Def[] = [
  { kind: 'now', key: 'rotationNowPlaying', icon: 'fas fa-headphones', title: 'now_playing' },
  { kind: 'tracks', key: 'rotationTopTracks', icon: 'fas fa-music', title: 'top_tracks' },
  { kind: 'artists', key: 'rotationTopArtists', icon: 'fas fa-microphone-lines', title: 'top_artists' },
  { kind: 'listeners', key: 'rotationTopListeners', icon: 'fas fa-ranking-star', title: 'top_listeners' },
];

const LISTS: Record<BoardKind, (rows: any) => any> = { now: NowPlayingList, tracks: TrackList, artists: ArtistList, listeners: ListenerList };

const boardsAllowed = () => !!app.forum.attribute('canViewRotationLeaderboards');

function allowed(kind: BoardKind): boolean {
  return kind === 'now' || boardsAllowed();
}

/** The rows a widget shows, asking for them the first time. */
function rows(kind: BoardKind, period: Period, limit: number): any[] | undefined {
  return allowed(kind) ? want(kind, period, limit) : [];
}

interface BodyAttrs {
  kind: BoardKind;
  period?: Period;
  limit?: number;
  /** Draw the heading (Bespoke and the stock sidebar); FoF draws its own. */
  heading?: boolean;
}

/** One widget's body. Nothing at all while there is nothing to show. */
export class RotationWidget extends Component<BodyAttrs> {
  view() {
    const { kind, period = 'week', limit = 5, heading } = this.attrs;
    const list = rows(kind, period, limit);
    if (!list || !list.length) return null;

    const def = WIDGETS.find((w) => w.kind === kind)!;

    return (
      <div className={`RotationWidget RotationWidget--${kind}`}>
        {heading ? (
          <h4 className="RotationWidget-title">
            <i className={`icon ${def.icon}`} aria-hidden="true" /> {t(`widget.${def.title}`)}
          </h4>
        ) : null}
        {LISTS[kind](list)}
        {kind !== 'now' && boardsAllowed() ? (
          <a
            className="RotationWidget-more"
            href={app.route('rotation.charts')}
            onclick={(e: MouseEvent) => (e.preventDefault(), m.route.set(app.route('rotation.charts')))}
          >
            {t('widget.see_all')}
          </a>
        ) : null}
      </div>
    );
  }
}

/**
 * Register with every widget host that is present — Bespoke and
 * fof/forum-widgets-core — resolved at runtime, never imported, so the bundle
 * is the same whether either is installed.
 */
export function registerWidgets(): void {
  const g = globalThis as any;

  if (g.flarum?.extensions && 'ernestdefoe-bespoke' in g.flarum.extensions) {
    const periods = [
      { value: 'week', label: extractText(t('period.week')) },
      { value: 'month', label: extractText(t('period.month')) },
      { value: 'year', label: extractText(t('period.year')) },
      { value: 'all', label: extractText(t('period.all')) },
    ];
    const counts = ['5', '10'].map((v) => ({ value: v, label: v }));

    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    WIDGETS.forEach((def) => {
      g.BespokeWidgetQueue.push({
        type: `rotation-${def.kind}`,
        // 🚨 Translation KEYS: Bespoke resolves them.
        label: `ernestdefoe-rotation.lib.widget.${def.title}`,
        icon: def.icon,
        zones: ['sidebar', 'above-list', 'below-list', 'footer'],
        schema: [
          ...(def.kind === 'now'
            ? []
            : [{ key: 'period', type: 'select', label: 'ernestdefoe-rotation.lib.widget.period', default: 'week', options: periods }]),
          { key: 'count', type: 'select', label: 'ernestdefoe-rotation.lib.widget.count', default: '5', options: counts },
        ],
        component: {
          view: (vnode: any) =>
            m(RotationWidget, {
              kind: def.kind,
              period: vnode.attrs.settings?.period || 'week',
              limit: Number(vnode.attrs.settings?.count) === 10 ? 10 : 5,
              heading: true,
            }),
        },
      });
    });
  }

  if (hasFofWidgets() && (app as any).widgets) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget') as { new (...args: any[]): any } | undefined;
    if (!Widgets || !WidgetBase) return;

    const add = new Widgets();
    WIDGETS.forEach((def, position) => {
      class FofWidget extends WidgetBase {
        className() {
          return `RotationFofWidget RotationFofWidget--${def.kind}`;
        }
        icon() {
          return def.icon;
        }
        title() {
          return t(`widget.${def.title}`);
        }
        content() {
          return m(RotationWidget, { kind: def.kind });
        }
      }

      add.add({
        key: def.key,
        component: FofWidget,
        // 🚨 Opt-in: only where the admin placed it in FoF's layout editor,
        // and only with something to show — FoF draws a box for any widget
        // it is given. Asking here is what fetches the rows.
        isDisabled: () => !placed(app, EXT, def.key) || !rows(def.kind, 'week', 5)?.length,
        isUnique: true,
        placement: 'end',
        position,
      });
    });
    add.extend(app, EXT);

    // Registered after FoF drew its sections; a placed widget appears now.
    m.redraw();
  }
}
