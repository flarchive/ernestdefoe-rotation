import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import Sortable from './sortable';

declare const m: import('mithril').Static;

interface Row {
  from: string;
  to: string;
  level: string;
  name: string;
  key: string;
}

/** The same bands the server uses until the admin saves their own (Settings::DEFAULT_BANDS). */
const DEFAULTS = [
  [0, 10, 1, 'Tuning in'],
  [10, 50, 2, 'Regular'],
  [50, 150, 3, 'Devoted'],
  [150, 400, 4, 'Crate digger'],
  [400, 1000, 5, 'Audiophile'],
  [1000, null, 6, 'Legend'],
] as const;

const a = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-rotation.admin.bands.${key}`, params);

let seq = 0;

/**
 * "From X to Y hours of listening = level N", as many rows as the admin wants,
 * dragged into order: the first row an amount falls in wins. Writes the JSON
 * setting the page saves with its own Save button.
 */
export default class BandsEditor extends Component<{ setting: (value?: string) => string }> {
  rows: Row[] = [];
  sortable!: Sortable<Row>;

  oninit(vnode: any) {
    super.oninit(vnode);

    let parsed: any[] | null = null;
    try {
      parsed = JSON.parse(this.attrs.setting() || 'null');
    } catch (e) {
      parsed = null;
    }
    const list = Array.isArray(parsed) ? parsed : DEFAULTS.map(([from, to, level, name]) => ({ from, to, level, name }));

    this.rows = list.map((b: any) => ({
      from: String(b.from ?? 0),
      to: b.to === null || b.to === undefined ? '' : String(b.to),
      level: String(b.level ?? ''),
      name: String(b.name ?? ''),
      key: String(++seq),
    }));
    this.sortable = new Sortable(
      () => this.rows,
      () => this.write(),
      extractText(a('row'))
    );
  }

  write() {
    this.attrs.setting(
      JSON.stringify(
        this.rows.map((r) => ({
          from: Number(r.from) || 0,
          to: r.to.trim() === '' ? null : Number(r.to),
          level: Number(r.level) || 0,
          name: r.name.trim(),
        }))
      )
    );
  }

  view() {
    const field = (row: Row, prop: keyof Row, attrs: Record<string, unknown>) => (
      <input
        className="FormControl"
        value={row[prop]}
        oninput={(e: InputEvent) => {
          row[prop] = (e.target as HTMLInputElement).value;
          this.write();
        }}
        {...attrs}
      />
    );

    return (
      <div className="Form-group RotationBands">
        <label>{a('title')}</label>
        <p className="helpText">{a('help')}</p>
        <div className="RotationBands-list" data-sortable>
          {this.rows.map((row, i) => (
            <div key={row.key} className={'RotationBands-row' + (this.sortable.dragging === i ? ' is-dragging' : '')}>
              <span className="RotationBands-handle" {...this.sortable.handle(i)} title={extractText(a('drag'))}>
                <i className="fas fa-grip-vertical" aria-hidden="true" />
              </span>
              <span>{a('from')}</span>
              {field(row, 'from', { type: 'number', min: 0, step: 'any', 'aria-label': extractText(a('from')) })}
              <span>{a('to')}</span>
              {field(row, 'to', { type: 'number', min: 0, step: 'any', placeholder: extractText(a('up')), 'aria-label': extractText(a('to')) })}
              <span>{a('hours_level')}</span>
              {field(row, 'level', { type: 'number', min: 0, step: 1, 'aria-label': extractText(a('level')) })}
              {field(row, 'name', { type: 'text', maxlength: 40, placeholder: extractText(a('name')), 'aria-label': extractText(a('name')) })}
              <Button
                className="Button Button--icon Button--link"
                icon="fas fa-xmark"
                aria-label={extractText(a('remove'))}
                onclick={() => {
                  this.rows.splice(i, 1);
                  this.write();
                }}
              />
            </div>
          ))}
        </div>
        <Button
          className="Button"
          icon="fas fa-plus"
          onclick={() => {
            const last = this.rows[this.rows.length - 1];
            const from = last ? last.to || String((Number(last.from) || 0) + 100) : '0';
            this.rows.push({ from, to: '', level: String((Number(last?.level) || 0) + 1), name: '', key: String(++seq) });
            this.write();
          }}
        >
          {a('add')}
        </Button>
      </div>
    );
  }
}
