import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-rotation.lib.${key}`, params);

/** "12 h 30 min", "45 min" */
export function duration(ms: number): string {
  const minutes = Math.floor(ms / 60000);
  const hours = Math.floor(minutes / 60);

  return hours > 0
    ? extractText(t('time.hours_minutes', { hours: hours.toLocaleString(), minutes: minutes % 60 }))
    : extractText(t('time.minutes', { minutes }));
}

/** The admin's name for a level, when it has one. */
export function levelName(level: number): string {
  const names = app.forum.attribute<Record<string, string>>('rotationLevelNames') || {};

  return names[String(level)] || '';
}
