import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import dynamicallyLoadLib from '../utils/dynamicallyLoadLib';
import SupplementCheckinModal from '../modal/SupplementCheckinModal';
import dayjs from 'dayjs';
import type Mithril from 'mithril';

declare const FullCalendar: any;

export default class CheckinHistoryPage extends UserPage {
  calendar: any = null;
  historyData: any = null;
  loadingHistory: boolean = false;

  oninit(vnode: Mithril.Vnode<any, this>): void {
    super.oninit(vnode);

    this.loadUser(m.route.param('username'));
  }

  canViewHistory(): boolean {
    if (!this.user) {
      return false;
    }
    const isSelf = !!(app.session.user && app.session.user.id() === this.user.id());
    const canViewOthers = Boolean(app.forum.attribute('canQueryOthersHistory'));

    return isSelf || canViewOthers;
  }

  show(user: any): void {
    super.show(user);
  }

  content(): Mithril.Children {
    if (!this.canViewHistory()) {
      return (
        <div className="CheckinHistoryUserPage CheckinHistoryUserPage--noPermission Placeholder">
          <p>{app.translator.trans('mattoid-daily-check-in-history.forum.page.permission-denied')}</p>
        </div>
      );
    }

    return (
      <div className="CheckinHistoryUserPage">
        <div
          id="calendar"
          oncreate={(vnode: Mithril.VnodeDOM) => {
            this.renderCalendar(vnode.dom as HTMLElement);
          }}
        />

        {this.loadingHistory && (
          <div className="DiscussionList">
            <div className="DiscussionList-loadMore">
              <LoadingIndicator size="medium" />
            </div>
          </div>
        )}
      </div>
    );
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>): void {
    super.onremove(vnode);

    if (this.calendar) {
      this.calendar.destroy();
      this.calendar = null;
    }
  }

  async getData(info: { start: Date; end: Date }): Promise<any[]> {
    if (!this.canViewHistory()) {
      return [];
    }

    this.loadingHistory = true;
    m.redraw();

    const username = this.user ? this.user.slug() : m.route.param('username');
    const userId = this.user ? this.user.id() : null;

    try {
      const response = await app.request<any>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/checkin/history`,
        params: {
          start: info.start.toISOString(),
          end: info.end.toISOString(),
          username,
          userId,
        },
      });

      this.historyData = response;
      this.loadingHistory = false;
      m.redraw();

      if (response && Array.isArray(response.data)) {
        return response.data.map((item: any) => item.attributes);
      }
      return [];
    } catch (e) {
      this.loadingHistory = false;
      m.redraw();
      return [];
    }
  }

  async renderCalendar(element?: HTMLElement): Promise<void> {
    if (!this.canViewHistory()) {
      return;
    }

    await dynamicallyLoadLib('fullcalendar');
    await dynamicallyLoadLib('fullcalendarLocales');

    const calendarEl = element || document.getElementById('calendar');
    if (!calendarEl) {
      return;
    }

    if (this.calendar) {
      this.calendar.destroy();
      this.calendar = null;
    }

    const openModal = this.openCreateModal.bind(this);

    this.calendar = new FullCalendar.Calendar(calendarEl, {
      locale: app.translator.getLocale(),
      allDayText: extractText(app.translator.trans('mattoid-daily-check-in-history.forum.page.today')),
      initialView: 'dayGridMonth',
      dateClick: (info: any) => {
        openModal(info);
      },
      events: (info: any, successCb: any, failureCb: any) => this.getData(info),
    });
    this.calendar.render();
  }

  async openCreateModal(info: { dateStr: string }): Promise<boolean | void> {
    // Only the user themselves can supplement check-in on their own calendar
    if (!app.session.user || !this.user || app.session.user.id() !== this.user.id()) {
      return false;
    }

    if (dayjs(info.dateStr).isAfter(dayjs(), 'day')) {
      return false;
    }

    const list = (this.historyData && this.historyData.data) || [];
    for (const item of list) {
      if (item.attributes && item.attributes.start === info.dateStr) {
        return false;
      }
    }

    app.modal.show(SupplementCheckinModal, {
      info,
      callback: () => {
        if (this.calendar) {
          this.calendar.refetchEvents();
        }
      },
    });
  }
}
