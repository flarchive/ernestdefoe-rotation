import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import { boards, type Boards, type Period } from '../../common/api';
import { t } from '../../common/format';
import { ArtistList, ListenerList, TrackList } from '../components/Lists';

declare const m: import('mithril').Static;

type Tab = 'tracks' | 'artists' | 'listeners';

const c = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-rotation.forum.charts.${key}`, params);

/** /rotation — the forum's songs, artists and listeners. */
export default class ChartsPage extends Page {
  tab: Tab = 'tracks';
  period: Period = 'week';
  rows: Boards = {};
  loading = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    app.setTitle(extractText(c('title')));
    this.load();
  }

  load() {
    this.loading = true;
    boards([this.tab], this.period, 50)
      .then((res) => (this.rows = res.data))
      .catch(() => (this.rows = {}))
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    const rows = (this.rows as any)[this.tab] as any[] | undefined;
    const list = { tracks: TrackList, artists: ArtistList, listeners: ListenerList }[this.tab];

    return (
      <PageStructure className="RotationChartsPage" sidebar={() => <IndexSidebar />}>
        <h2 className="RotationChartsPage-title">
          <i className="fas fa-compact-disc" aria-hidden="true" /> {c('title')}
        </h2>
        <div className="RotationChartsPage-controls">
          <div className="ButtonGroup">
            {(['tracks', 'artists', 'listeners'] as Tab[]).map((tab) => (
              <Button className={'Button' + (this.tab === tab ? ' active' : '')} onclick={() => this.select(tab, this.period)}>
                {c(tab)}
              </Button>
            ))}
          </div>
          <div className="ButtonGroup">
            {(['week', 'month', 'year', 'all'] as Period[]).map((period) => (
              <Button className={'Button Button--small' + (this.period === period ? ' active' : '')} onclick={() => this.select(this.tab, period)}>
                {t(`period.${period}`)}
              </Button>
            ))}
          </div>
        </div>
        {this.loading ? <LoadingIndicator /> : rows && rows.length ? list(rows as any) : <p className="RotationEmpty">{c('empty')}</p>}
        <p className="helpText RotationChartsPage-help">{c('help')}</p>
      </PageStructure>
    );
  }

  select(tab: Tab, period: Period) {
    if (tab === this.tab && period === this.period) return;
    this.tab = tab;
    this.period = period;
    this.load();
  }
}
