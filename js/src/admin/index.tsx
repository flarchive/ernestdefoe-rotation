import app from 'flarum/admin/app';
import { registerEditorWidgets } from '../common/fofWidgets';
import BandsEditor from './BandsEditor';

declare const m: import('mithril').Static;

const SERVICES = ['spotify', 'apple', 'youtube', 'soundcloud', 'deezer', 'tidal', 'bandcamp', 'audiomack', 'mixcloud'];

app.initializers.add('ernestdefoe-rotation', () => {
  const s = (key: string) => `ernestdefoe-rotation.${key}`;
  const a = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-rotation.admin.${key}`, params);

  app.registry
    .for('ernestdefoe-rotation')
    .registerSetting(() => (
      <div className="Form-group">
        <h3>{a('lastfm.title')}</h3>
        <p className="helpText">
          {a('lastfm.help', { a: <a href="https://www.last.fm/api/account/create" target="_blank" rel="noopener noreferrer" /> })}
        </p>
      </div>
    ))
    .registerSetting({ setting: s('lastfm_key'), type: 'text', label: a('lastfm.key') })
    .registerSetting({ setting: s('lastfm_secret'), type: 'password', label: a('lastfm.secret') })
    .registerSetting(function (this: any) {
      return <BandsEditor setting={this.setting(s('bands'))} />;
    })
    .registerSetting({
      setting: s('average_minutes'),
      type: 'number',
      min: 0.5,
      max: 20,
      step: 0.1,
      label: a('settings.average'),
      help: a('settings.average_help'),
    })
    .registerSetting({ setting: s('daily_cap_hours'), type: 'number', min: 1, max: 24, label: a('settings.cap'), help: a('settings.cap_help') })
    .registerSetting({
      setting: s('retention_days'),
      type: 'number',
      min: 14,
      max: 3650,
      label: a('settings.retention'),
      help: a('settings.retention_help'),
    })
    .registerSetting({
      setting: s('default_visibility'),
      type: 'select',
      label: a('settings.default_visibility'),
      options: { everyone: a('settings.everyone'), members: a('settings.members'), me: a('settings.me') },
      default: 'everyone',
    })
    .registerSetting({ setting: s('post_levels'), type: 'boolean', label: a('settings.post_levels') })
    .registerSetting({ setting: s('sidebar_now_playing'), type: 'boolean', label: a('settings.sidebar'), help: a('settings.sidebar_help') })
    .registerSetting(function (this: any) {
      const stream = this.setting(s('embeds'));
      let on: string[];
      try {
        on = JSON.parse(stream() || 'null') || SERVICES;
      } catch (e) {
        on = SERVICES;
      }

      return (
        <div className="Form-group RotationServices">
          <label>{a('embeds.title')}</label>
          <p className="helpText">{a('embeds.help')}</p>
          {SERVICES.map((key) => (
            <label className="checkbox">
              <input
                type="checkbox"
                checked={on.includes(key)}
                onchange={(e: Event) => {
                  const checked = (e.target as HTMLInputElement).checked;
                  stream(JSON.stringify(SERVICES.filter((k) => (k === key ? checked : on.includes(k)))));
                }}
              />{' '}
              {a(`embeds.${key}`)}
            </label>
          ))}
        </div>
      );
    })
    .registerSetting({ setting: s('rate'), type: 'number', min: 0.2, max: 4, step: 0.1, label: a('settings.rate'), help: a('settings.rate_help') })
    .registerSetting({
      setting: s('global_cap'),
      type: 'number',
      min: 10,
      max: 3000,
      label: a('settings.global_cap'),
      help: a('settings.global_cap_help'),
    })
    .registerPermission({ icon: 'fab fa-lastfm', label: a('permissions.connect'), permission: 'rotation.connect' }, 'reply')
    .registerPermission(
      { icon: 'fas fa-compact-disc', label: a('permissions.leaderboards'), permission: 'rotation.viewLeaderboards', allowGuest: true },
      'view'
    );

  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-rotation', [
    { key: 'rotationNowPlaying', icon: 'fas fa-headphones', title: () => a('widgets.now_playing') },
    { key: 'rotationTopTracks', icon: 'fas fa-music', title: () => a('widgets.top_tracks') },
    { key: 'rotationTopArtists', icon: 'fas fa-microphone-lines', title: () => a('widgets.top_artists') },
    { key: 'rotationTopListeners', icon: 'fas fa-ranking-star', title: () => a('widgets.top_listeners') },
  ]);
});
