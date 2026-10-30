import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import IndexPage from 'flarum/forum/components/IndexPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserCard from 'flarum/forum/components/UserCard';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import { levelName, t } from '../common/format';
import { placed } from '../common/fofWidgets';
import { embedPlayers } from './embeds';
import { RecapNotification } from './notifications';
import { registerWidgets, RotationWidget } from './widgets';

declare const m: import('mithril').Static;

app.initializers.add('ernestdefoe-rotation', () => {
  // 🚨 Both pages are their own chunks: nothing in them loads on other pages.
  app.routes['rotation.charts'] = { path: '/rotation', component: () => import('./pages/ChartsPage') };
  app.routes['rotation.user'] = { path: '/u/:username/music', component: () => import('./pages/MusicPage') };

  app.notificationComponents.rotationRecap = RecapNotification;

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('rotationRecap', {
      name: 'rotationRecap',
      icon: 'fas fa-compact-disc',
      label: app.translator.trans('ernestdefoe-rotation.forum.settings.notify_recap'),
    });
  });

  extend(UserPage.prototype, 'navItems', function (this: any, items: ItemList<any>) {
    if (!this.user) return;
    items.add(
      'rotation',
      LinkButton.component({ href: app.route('rotation.user', { username: this.user.slug() }), icon: 'fas fa-headphones' }, t('profile_tab')),
      65
    );
  });

  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('canViewRotationLeaderboards')) return;
    items.add('rotation', LinkButton.component({ href: app.route('rotation.charts'), icon: 'fas fa-compact-disc' }, t('charts_nav')), 8);
  });

  // The stock sidebar shows who is listening, on the discussion list only,
  // unless the admin switched it off or placed the widget in FoF's layout.
  // 🚨 Placed, not installed: FoF installed with the widget unplaced still
  // gets the sidebar box. Nothing is drawn while nobody is listening.
  extend(IndexSidebar.prototype, 'items', function (items: ItemList<any>) {
    if (!app.forum.attribute('rotationSidebar') || placed(app, 'ernestdefoe-rotation', 'rotationNowPlaying') || !app.current?.matches(IndexPage))
      return;
    items.add('rotationNowPlaying', m(RotationWidget, { kind: 'now', heading: true }), -20);
  });

  // Music links become players: on first render, and again after an edit.
  const players = function (this: any) {
    if (this.element && !this.isEditing()) {
      const body = this.element.querySelector('.Post-body');
      if (body) embedPlayers(body);
    }
  };
  extend(CommentPost.prototype, 'oncreate', players);
  extend(CommentPost.prototype, 'onupdate', players);

  // The author's level beside their name: a column of the user row, already loaded.
  extend(CommentPost.prototype, 'headerItems', function (this: any, items: ItemList<any>) {
    const level = app.forum.attribute('rotationPostLevels') ? this.attrs.post.user()?.attribute?.('rotationLevel') : null;
    if (level === null || level === undefined) return;
    items.add('rotationLevel', badge(level), 90);
  });

  extend(UserCard.prototype, 'infoItems', function (this: any, items: ItemList<any>) {
    const level = this.attrs.user?.attribute?.('rotationLevel');
    if (level === null || level === undefined) return;
    items.add('rotationLevel', badge(level));
  });

  // After sibling extensions (Bespoke, FoF widgets) have set up their registries.
  setTimeout(() => registerWidgets(), 0);
});

function badge(level: number) {
  const name = levelName(level);

  return (
    <span className="RotationLevel" title={name || undefined}>
      <i className="fas fa-headphones" aria-hidden="true" /> {t('level', { level })}
      {name ? <span className="RotationLevel-name"> · {name}</span> : null}
    </span>
  );
}
