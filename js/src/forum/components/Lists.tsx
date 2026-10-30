import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import type { Boards, Person } from '../../common/api';
import { duration, t } from '../../common/format';

declare const m: import('mithril').Static;

/** A member as a link to their music tab. */
export function personLink(person: Person, children: any) {
  return (
    <Link className="RotationPerson" href={app.route('rotation.user', { username: person.slug })}>
      {children}
    </Link>
  );
}

function avatar(person: Person) {
  return person.avatarUrl ? (
    <img className="Avatar" src={person.avatarUrl} alt="" loading="lazy" />
  ) : (
    <span className="Avatar RotationAvatar">{(person.displayName || '?').charAt(0).toUpperCase()}</span>
  );
}

/** A song or artist name, linked to its Last.fm page (the link-back Last.fm asks for). */
function out(url: string | null | undefined, text: string) {
  return url ? (
    <a href={url} target="_blank" rel="noopener noreferrer nofollow">
      {text}
    </a>
  ) : (
    text
  );
}

export function NowPlayingList(rows: NonNullable<Boards['now']>) {
  return (
    <ul className="RotationList RotationList--now">
      {rows.map((row) => (
        <li key={row.user.id}>
          {personLink(row.user, avatar(row.user))}
          <div className="RotationList-body">
            {personLink(row.user, <span className="RotationList-name">{row.user.displayName}</span>)}
            <div className="RotationList-track">
              <span className="RotationEq" aria-hidden="true">
                <i />
                <i />
                <i />
              </span>
              {out(row.track.url, row.track.title)} <span className="RotationList-by">{row.track.artist}</span>
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

export function TrackList(rows: NonNullable<Boards['tracks']>) {
  return (
    <ol className="RotationList RotationList--ranked">
      {rows.map((row) => (
        <li key={row.rank}>
          <span className="RotationList-rank">{row.rank}</span>
          <div className="RotationList-body">
            <div className="RotationList-name">{out(row.url, row.title)}</div>
            <div className="RotationList-by">{row.artist}</div>
          </div>
          <span className="RotationList-count">{t('plays', { count: row.plays })}</span>
        </li>
      ))}
    </ol>
  );
}

export function ArtistList(rows: NonNullable<Boards['artists']>) {
  return (
    <ol className="RotationList RotationList--ranked">
      {rows.map((row) => (
        <li key={row.rank}>
          <span className="RotationList-rank">{row.rank}</span>
          <div className="RotationList-body">
            <div className="RotationList-name">{out(row.url, row.name)}</div>
          </div>
          <span className="RotationList-count">{t('plays', { count: row.plays })}</span>
        </li>
      ))}
    </ol>
  );
}

export function ListenerList(rows: NonNullable<Boards['listeners']>) {
  return (
    <ol className="RotationList RotationList--ranked">
      {rows.map((row) => (
        <li key={row.user.id}>
          <span className="RotationList-rank">{row.rank}</span>
          {personLink(row.user, avatar(row.user))}
          <div className="RotationList-body">
            {personLink(row.user, <span className="RotationList-name">{row.user.displayName}</span>)}
            {row.user.level !== null ? <div className="RotationList-by">{t('level', { level: row.user.level })}</div> : null}
          </div>
          <span className="RotationList-count">{duration(row.ms)}</span>
        </li>
      ))}
    </ol>
  );
}
