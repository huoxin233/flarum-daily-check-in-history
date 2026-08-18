import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

export interface SupplementCheckinModalAttrs extends IInternalModalAttrs {
  info: {
    dateStr: string;
    [key: string]: any;
  };
  callback?: () => void;
}

export default class SupplementCheckinModal extends Modal<SupplementCheckinModalAttrs> {
  loading: boolean = false;

  title(): Mithril.Children {
    return app.translator.trans('mattoid-daily-check-in-history.forum.modal.checkin');
  }

  className(): string {
    return 'SupplementCheckinModal Modal--small';
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body" style={{ textAlign: 'center' }}>
        <div className="Form-group">
          <label className="label">
            {app.translator.trans('mattoid-daily-check-in-history.forum.modal.supplement-checkin-desc', {
              dateStr: this.attrs.info.dateStr,
            })}
          </label>
        </div>
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary" loading={this.loading}>
            {app.translator.trans('mattoid-daily-check-in-history.forum.modal.submit')}
          </Button>
        </div>
      </div>
    );
  }

  async onsubmit(e: Event): Promise<void> {
    e.preventDefault();
    this.loading = true;

    try {
      await app.request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/supplement/checkin',
        body: {
          date: this.attrs.info.dateStr,
        },
      });

      if (this.attrs.callback) {
        this.attrs.callback();
      }
      this.hide();
    } catch (err) {
      this.loading = false;
      m.redraw();
    }
  }
}
