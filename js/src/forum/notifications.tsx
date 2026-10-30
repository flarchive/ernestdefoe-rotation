import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { duration } from '../common/format';

/** "Your week in music: 12 h 30 min, 240 plays. Top artist: …" */
export class RecapNotification extends Notification {
  icon() {
    return 'fas fa-compact-disc';
  }

  href() {
    const user = app.session.user;

    return user ? app.route('rotation.user', { username: user.slug() }) : app.route('index');
  }

  content() {
    const data = (this.attrs.notification.content() || {}) as any;

    return app.translator.trans('ernestdefoe-rotation.forum.notifications.recap', {
      time: duration(Number(data.ms) || 0),
      plays: Number(data.plays) || 0,
    });
  }

  excerpt() {
    const data = (this.attrs.notification.content() || {}) as any;

    return data.artist ? app.translator.trans('ernestdefoe-rotation.forum.notifications.recap_top', { artist: data.artist }) : null;
  }
}
