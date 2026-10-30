import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import { connectUrl, disconnect, profile, savePrefs, type Prefs, type Profile, type Track, type Artist } from '../../common/api';
import { duration, t } from '../../common/format';

declare const m: import('mithril').Static;

const p = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-rotation.forum.profile.${key}`, params);

/**
 * Profile → Music: now playing, listening time and level, tops and recent
 * plays — and, for the member themself, connecting Last.fm and every privacy
 * choice. Its own chunk and one request, only when opened.
 */
export default class MusicPage extends UserPage {
  data: Profile | null = null;
  range: 'month' | 'overall' = 'month';
  saving = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));

    // Back from Last.fm: say how it went, once.
    const result = m.route.param('rotation');
    if (result) {
      const ok = result === 'connected';
      app.alerts.show(
        { type: ok ? 'success' : 'error' },
        p(`result.${['connected', 'taken', 'denied', 'expired'].includes(result) ? result : 'failed'}`)
      );
      window.history.replaceState(null, '', window.location.pathname);
    }
  }

  show(user: any) {
    super.show(user);
    app.setTitle(extractText(p('title')));
    this.load();
  }

  load() {
    profile(this.user!.id()!)
      .then((res) => (this.data = res.data))
      .catch(() => (this.data = null))
      .finally(() => m.redraw());
  }

  content() {
    const d = this.data;
    if (!d) return <LoadingIndicator />;

    return <div className="RotationMusic">{this.body(d)}</div>;
  }

  body(d: Profile) {
    if (d.self && !d.connected) return this.connect(d);
    if (!d.connected) return <p className="RotationEmpty">{p('not_connected', { name: this.user!.displayName() })}</p>;
    if (!d.visible) {
      return [d.self ? this.settings(d) : null, <p className="RotationEmpty">{p('private', { name: this.user!.displayName() })}</p>];
    }

    return [this.status(d), this.now(d), this.stats(d), this.tops(d), this.recent(d), this.credit(d), d.self ? this.settings(d) : null];
  }

  connect(d: Profile) {
    return (
      <div className="RotationConnect">
        <i className="fas fa-compact-disc RotationConnect-icon" aria-hidden="true" />
        <h3>{p('connect.title')}</h3>
        <p>{p('connect.text')}</p>
        {!d.configured ? (
          <p className="helpText">{p('connect.not_configured')}</p>
        ) : d.canConnect ? (
          <a className="Button Button--primary" href={connectUrl()}>
            <i className="fab fa-lastfm icon" aria-hidden="true" /> {p('connect.button')}
          </a>
        ) : null}
        <p className="helpText">{p('connect.help')}</p>
      </div>
    );
  }

  status(d: Profile) {
    const a = d.account!;
    if (a.status !== 'ok') return <div className="Alert RotationNotice">{p(`status.${a.status}`)}</div>;
    if (a.pending && d.self) return <div className="Alert RotationNotice">{p('status.pending')}</div>;

    return null;
  }

  now(d: Profile) {
    const n = d.nowPlaying;
    const last = d.recent?.[0];
    if (!n && !last) return null;

    return (
      <div className={'RotationNow' + (n ? ' is-live' : '')}>
        <div className="RotationNow-label">
          {n ? (
            <span className="RotationEq" aria-hidden="true">
              <i />
              <i />
              <i />
            </span>
          ) : (
            <i className="fas fa-clock-rotate-left" aria-hidden="true" />
          )}{' '}
          {n ? p('now_playing') : p('last_played', { ago: humanTime(new Date(last!.playedAt!)) })}
        </div>
        <div className="RotationNow-title">{link(n ? n.url : last!.url, n ? n.title : last!.title)}</div>
        <div className="RotationNow-artist">{n ? n.artist : last!.artist}</div>
      </div>
    );
  }

  stats(d: Profile) {
    const s = d.stats!;
    const level = s.level;
    const next = s.next;
    const hours = s.totalMs / 3600000;
    const progress = level && next ? Math.max(0, Math.min(1, (hours - level.from) / (next.from - level.from))) : 1;

    return (
      <div className="RotationStats">
        {stat(p('stats.total'), duration(s.totalMs))}
        {stat(p('stats.week'), duration(s.weekMs))}
        {stat(p('stats.plays'), s.totalPlays.toLocaleString())}
        {level ? (
          <div className="RotationStat RotationStat--level">
            <div className="RotationStat-label">{p('stats.level')}</div>
            <div className="RotationStat-value">
              {t('level', { level: level.level })}
              {level.name ? <span className="RotationStat-name">{level.name}</span> : null}
            </div>
            {next ? (
              <>
                <div className="RotationLevelBar" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(progress * 100)}>
                  <span style={{ width: `${progress * 100}%` }} />
                </div>
                <div className="RotationStat-hint">{p('stats.next', { hours: Math.max(1, Math.ceil(next.from - hours)), level: next.level })}</div>
              </>
            ) : null}
          </div>
        ) : null}
        <p className="RotationStats-help helpText">{p('stats.estimate')}</p>
      </div>
    );
  }

  tops(d: Profile) {
    const tops = d.tops?.[this.range];
    if (!d.tops) return null;

    return (
      <section className="RotationSection">
        <div className="RotationSection-head">
          <h3>{p('tops.title')}</h3>
          <div className="ButtonGroup">
            {(['month', 'overall'] as const).map((r) => (
              <Button className={'Button Button--small' + (this.range === r ? ' active' : '')} onclick={() => (this.range = r)}>
                {p(`tops.${r}`)}
              </Button>
            ))}
          </div>
        </div>
        <div className="RotationTops">
          <div>
            <h4>{p('tops.tracks')}</h4>
            {ranked((tops?.tracks || []) as Track[], (x: Track) => [link(x.url, x.title), <span className="RotationList-by"> {x.artist}</span>])}
          </div>
          <div>
            <h4>{p('tops.artists')}</h4>
            {ranked((tops?.artists || []) as Artist[], (x: Artist) => link(x.url, x.name))}
          </div>
        </div>
      </section>
    );
  }

  recent(d: Profile) {
    if (!d.recent?.length) return null;

    return (
      <section className="RotationSection">
        <h3>{p('recent')}</h3>
        <ul className="RotationRecent">
          {d.recent.map((r) => (
            <li>
              <span className="RotationRecent-track">
                {link(r.url, r.title)} <span className="RotationList-by">{r.artist}</span>
              </span>
              <span className="RotationRecent-time">{humanTime(new Date(r.playedAt!))}</span>
            </li>
          ))}
        </ul>
      </section>
    );
  }

  credit(d: Profile) {
    const a = d.account!;

    return (
      <p className="RotationCredit">
        <i className="fab fa-lastfm" aria-hidden="true" /> {p('credit')}{' '}
        {a.url ? (
          <a href={a.url} target="_blank" rel="noopener noreferrer nofollow">
            {a.name}
          </a>
        ) : null}
      </p>
    );
  }

  settings(d: Profile) {
    const prefs = d.prefs;
    if (!prefs) return null;

    const save = (change: Partial<Prefs>) => {
      Object.assign(prefs, change);
      this.saving = true;
      savePrefs(change)
        .then(() => this.load())
        .finally(() => {
          this.saving = false;
          m.redraw();
        });
    };

    return (
      <section className="RotationSection RotationSettings">
        <h3>{p('settings.title')}</h3>
        <div className="Form-group">
          <label>{p('settings.visibility')}</label>
          <Select
            value={prefs.visibility}
            disabled={this.saving}
            options={{ everyone: p('settings.everyone'), members: p('settings.members'), me: p('settings.me') }}
            onchange={(v: Prefs['visibility']) => save({ visibility: v })}
          />
        </div>
        <Switch state={prefs.showNowPlaying} disabled={this.saving} onchange={(v: boolean) => save({ showNowPlaying: v })}>
          {p('settings.now_playing')}
        </Switch>
        <Switch state={prefs.leaderboards} disabled={this.saving} onchange={(v: boolean) => save({ leaderboards: v })}>
          {p('settings.leaderboards')}
        </Switch>
        <p className="helpText">{p('settings.leaderboards_help')}</p>
        <p className="helpText">
          {p('settings.recap', {
            a: <a href={app.route('settings')} onclick={(e: MouseEvent) => (e.preventDefault(), m.route.set(app.route('settings')))} />,
          })}
        </p>
        <Button
          className="Button Button--danger"
          icon="fas fa-link-slash"
          disabled={this.saving}
          onclick={() => {
            if (!confirm(extractText(p('settings.disconnect_confirm')))) return;
            this.saving = true;
            disconnect()
              .then(() => this.load())
              .finally(() => {
                this.saving = false;
                m.redraw();
              });
          }}
        >
          {p('settings.disconnect')}
        </Button>
      </section>
    );
  }
}

function link(url: string | null | undefined, text: string) {
  return url ? (
    <a href={url} target="_blank" rel="noopener noreferrer nofollow">
      {text}
    </a>
  ) : (
    text
  );
}

function stat(label: any, value: any) {
  return (
    <div className="RotationStat">
      <div className="RotationStat-label">{label}</div>
      <div className="RotationStat-value">{value}</div>
    </div>
  );
}

function ranked<T extends { plays?: number }>(items: T[], render: (x: T) => any) {
  if (!items.length) return <p className="RotationEmpty">{p('tops.empty')}</p>;

  return (
    <ol className="RotationList RotationList--ranked">
      {items.map((x, i) => (
        <li>
          <span className="RotationList-rank">{i + 1}</span>
          <div className="RotationList-body">
            <div className="RotationList-name">{render(x)}</div>
          </div>
          {x.plays ? <span className="RotationList-count">{t('plays', { count: x.plays })}</span> : null}
        </li>
      ))}
    </ol>
  );
}
