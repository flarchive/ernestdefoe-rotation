import app from 'flarum/common/app';

export type Period = 'week' | 'month' | 'year' | 'all';
export type Visibility = 'everyone' | 'members' | 'me';

export interface Person {
  id: number;
  username: string;
  displayName: string;
  avatarUrl: string | null;
  slug: string;
  level: number | null;
}

export interface Track {
  title: string;
  artist: string;
  url: string | null;
  playedAt?: string;
  plays?: number;
}

export interface Artist {
  name: string;
  url: string | null;
  plays?: number;
}

export interface Prefs {
  visibility: Visibility;
  showNowPlaying: boolean;
  leaderboards: boolean;
}

export interface Band {
  level: number;
  name: string;
  from: number;
  to?: number | null;
}

export interface Profile {
  self: boolean;
  connected: boolean;
  visible: boolean;
  configured?: boolean;
  canConnect?: boolean;
  prefs?: Prefs | null;
  account?: { provider: string; name: string; url: string | null; status: 'ok' | 'private' | 'missing'; pending: boolean } | null;
  nowPlaying?: { title: string; artist: string; url: string | null } | null;
  recent?: Track[];
  tops?: Record<'month' | 'overall', { tracks: Track[]; artists: Artist[] }> | null;
  stats?: {
    totalMs: number;
    totalPlays: number;
    weekMs: number;
    weekPlays: number;
    level: Band | null;
    next: Band | null;
  };
}

export interface Boards {
  now?: { user: Person; track: { title: string; artist: string; url: string | null } }[];
  tracks?: (Track & { rank: number; plays: number })[];
  artists?: (Artist & { rank: number; plays: number })[];
  listeners?: { rank: number; user: Person; ms: number; plays: number }[];
}

export type BoardKind = keyof Boards;

const api = (path: string) => app.forum.attribute<string>('apiUrl') + path;

export const profile = (userId: string | number) => app.request<{ data: Profile }>({ method: 'GET', url: api(`/rotation/users/${userId}`) });

export const savePrefs = (prefs: Partial<Prefs>) => app.request<void>({ method: 'PATCH', url: api('/rotation/me'), body: { data: prefs } });

export const disconnect = () => app.request<void>({ method: 'DELETE', url: api('/rotation/me') });

export const boards = (kinds: BoardKind[], period: Period, limit: number) =>
  app.request<{ data: Boards }>({ method: 'GET', url: api('/rotation/charts'), params: { kinds: kinds.join(','), period, limit } });

export const connectUrl = () => app.forum.attribute<string>('baseUrl') + '/rotation/connect/lastfm';
