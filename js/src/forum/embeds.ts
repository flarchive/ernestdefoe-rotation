import app from 'flarum/forum/app';

/**
 * Music links in posts become players.
 *
 * 🚨 Every player is built HERE from the parts of the link that passed an
 * anchored pattern: a fixed https host per service, and an id that can only
 * hold letters, digits and a few separators. Nothing a service returns is
 * ever inserted, and no address from a post reaches an iframe as it was
 * written. Works the same whichever editor wrote the post (Flarum's own,
 * Markdown or Scribe): they all store a link, and the link is what is read.
 *
 * Only a bare link — its text is its own address — becomes a player. A link
 * the writer worded ("listen here") stays a link.
 */

interface Player {
  src: string;
  /** Pixel height, or 0 for a 16:9 video frame. */
  height: number;
}

type Rule = [string, RegExp, (m: RegExpMatchArray) => Player | null];

const enc = encodeURIComponent;

const RULES: Rule[] = [
  [
    'spotify',
    /^https:\/\/open\.spotify\.com\/(?:intl-[a-z]{2}(?:-[a-z]{2})?\/)?(track|album|playlist|artist|episode|show)\/([A-Za-z0-9]{22})(?:[/?#].*)?$/,
    (m) => ({ src: `https://open.spotify.com/embed/${m[1]}/${m[2]}`, height: m[1] === 'track' || m[1] === 'episode' ? 152 : 352 }),
  ],
  [
    'apple',
    /^https:\/\/music\.apple\.com\/([a-z]{2})\/(album|playlist|song|music-video)\/(?:([A-Za-z0-9%._~-]{1,200})\/)?([A-Za-z0-9.-]{1,64})(?:\?(?:.*&)?i=(\d{1,15}))?(?:[&#].*)?$/,
    (m) =>
      m[2] === 'music-video'
        ? null
        : {
            src: `https://embed.music.apple.com/${m[1]}/${m[2]}/${m[3] ? m[3] + '/' : ''}${m[4]}${m[5] ? '?i=' + m[5] : ''}`,
            height: m[2] === 'song' || m[5] ? 175 : 450,
          },
  ],
  [
    'youtube',
    /^https:\/\/(?:www\.|m\.|music\.)?youtube\.com\/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]{11})(?:[&#].*)?$/,
    (m) => ({ src: `https://www.youtube-nocookie.com/embed/${m[1]}`, height: 0 }),
  ],
  ['youtube', /^https:\/\/youtu\.be\/([A-Za-z0-9_-]{11})(?:[?#].*)?$/, (m) => ({ src: `https://www.youtube-nocookie.com/embed/${m[1]}`, height: 0 })],
  [
    'soundcloud',
    /^https:\/\/(?:www\.|m\.)?soundcloud\.com\/([A-Za-z0-9_-]{1,100})\/(sets\/)?([A-Za-z0-9_-]{1,200})\/?(?:[?#].*)?$/,
    (m) => ({
      src: `https://w.soundcloud.com/player/?url=${enc(`https://soundcloud.com/${m[1]}/${m[2] || ''}${m[3]}`)}&visual=false&show_comments=false`,
      height: m[2] ? 450 : 166,
    }),
  ],
  [
    'deezer',
    /^https:\/\/(?:www\.)?deezer\.com\/(?:[a-z]{2}\/)?(track|album|playlist|artist)\/(\d{1,20})(?:[/?#].*)?$/,
    (m) => ({ src: `https://widget.deezer.com/widget/auto/${m[1]}/${m[2]}`, height: m[1] === 'track' ? 150 : 300 }),
  ],
  [
    'tidal',
    /^https:\/\/(?:listen\.)?tidal\.com\/(?:browse\/)?(track|album|playlist|video)\/([A-Za-z0-9-]{1,64})(?:[/?#].*)?$/,
    (m) => ({ src: `https://embed.tidal.com/${m[1]}s/${m[2]}`, height: m[1] === 'video' ? 0 : m[1] === 'track' ? 120 : 400 }),
  ],
  [
    'audiomack',
    /^https:\/\/audiomack\.com\/([A-Za-z0-9_-]{1,100})\/(song|album|playlist)\/([A-Za-z0-9_-]{1,200})\/?(?:[?#].*)?$/,
    (m) => ({ src: `https://audiomack.com/embed/${m[2]}/${m[1]}/${m[3]}`, height: m[2] === 'song' ? 252 : 400 }),
  ],
  [
    'mixcloud',
    /^https:\/\/(?:www\.|m\.)?mixcloud\.com\/([A-Za-z0-9_-]{1,100})\/([A-Za-z0-9_%-]{1,200})\/?(?:[?#].*)?$/,
    (m) => ({ src: `https://player-widget.mixcloud.com/widget/iframe/?hide_cover=1&feed=${enc(`/${m[1]}/${m[2]}/`)}`, height: 120 }),
  ],
];

const BANDCAMP = /^https:\/\/([a-z0-9-]{1,63})\.bandcamp\.com\/(album|track)\/([a-z0-9-]{1,200})\/?(?:[?#].*)?$/i;

/** Bandcamp links → their looked-up player ids; one request per page of posts. */
const bandcamp = new Map<string, { kind: string; id: string } | null>();
let pending: { url: string; anchor: HTMLAnchorElement }[] = [];
let timer: number | undefined;

function enabled(): string[] {
  return app.forum.attribute<string[]>('rotationEmbeds') || [];
}

function bare(anchor: HTMLAnchorElement): boolean {
  const norm = (s: string) =>
    s
      .trim()
      .replace(/^https?:\/\//i, '')
      .replace(/\/$/, '')
      .toLowerCase();

  return norm(anchor.textContent || '') === norm(anchor.href);
}

function frame(provider: string, player: Player): HTMLElement {
  const box = document.createElement('span');
  box.className = `RotationPlayer RotationPlayer--${provider}` + (player.height ? '' : ' RotationPlayer--video');

  const iframe = document.createElement('iframe');
  iframe.src = player.src;
  iframe.loading = 'lazy';
  iframe.title = provider;
  iframe.setAttribute('frameborder', '0');
  iframe.setAttribute('allow', 'autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture');
  iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-presentation');
  // YouTube refuses to play with no referrer at all (its error 153).
  iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
  if (player.height) iframe.style.height = `${player.height}px`;

  box.appendChild(iframe);

  return box;
}

/**
 * Put the player where the link is. A link alone on its line is replaced by
 * it; one inside a sentence keeps its place and the player follows it.
 *
 * 🚨 Always INSIDE the link's own block, never beside it: the post body is
 * Mithril's trusted HTML, and a node added between its top-level nodes would
 * be left behind when the post is edited.
 */
function place(anchor: HTMLAnchorElement, player: HTMLElement): void {
  const block = anchor.parentElement;
  if (!block) return;

  anchor.dataset.rotation = '1';
  if ((block.textContent || '').trim() === (anchor.textContent || '').trim()) {
    anchor.replaceWith(player);
  } else {
    anchor.after(player);
  }
}

function lookUpBandcamp(): void {
  const batch = pending.splice(0, 30);
  timer = undefined;
  const urls = [...new Set(batch.map((p) => p.url))].filter((u) => !bandcamp.has(u));

  const done = () =>
    batch.forEach(({ url, anchor }) => {
      const ref = bandcamp.get(url);
      if (ref && anchor.isConnected && !anchor.dataset.rotation) {
        const kind = ref.kind === 'track' ? 'track' : 'album';
        const id = /^\d{1,20}$/.test(ref.id) ? ref.id : null;
        if (id) {
          place(
            anchor,
            frame('bandcamp', {
              src: `https://bandcamp.com/EmbeddedPlayer/${kind}=${id}/size=large/bgcol=ffffff/linkcol=0687f5/tracklist=false/artwork=small/transparent=true/`,
              height: 120,
            })
          );
        }
      }
    });

  if (!urls.length) return done();

  app
    .request<{ data: Record<string, { kind: string; id: string }> }>({
      method: 'GET',
      url: app.forum.attribute<string>('apiUrl') + '/rotation/embeds',
      params: { urls },
    })
    .then((res) => urls.forEach((u) => bandcamp.set(u, res.data[u] || null)))
    .catch(() => urls.forEach((u) => bandcamp.set(u, null)))
    .finally(() => {
      done();
      if (pending.length) timer = window.setTimeout(lookUpBandcamp, 0);
    });
}

/** Turn the bare music links in a rendered post into players. */
export function embedPlayers(root: HTMLElement): void {
  const on = enabled();
  if (!on.length) return;

  root.querySelectorAll<HTMLAnchorElement>('a[href^="https://"]:not([data-rotation])').forEach((anchor) => {
    if (!bare(anchor) || anchor.closest('.RotationPlayer, blockquote, code, pre')) return;
    const href = anchor.href;

    for (const [provider, pattern, build] of RULES) {
      const m = on.includes(provider) ? href.match(pattern) : null;
      const player = m && build(m);
      if (player) {
        place(anchor, frame(provider, player));
        return;
      }
    }

    if (on.includes('bandcamp') && BANDCAMP.test(href)) {
      const url = href.replace(/[?#].*$/, '');
      pending.push({ url, anchor });
      timer ??= window.setTimeout(lookUpBandcamp, 50);
    }
  });
}
