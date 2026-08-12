import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

type SiteStatus = Record<string, unknown> & {
  attack_mode_active?: boolean;
  attack_mode?: { enabled?: boolean };
  plan?: string;
  site_id?: string;
  preferred_endpoint?: string;
  current_month_checks?: number;
  allows?: number;
  blocks?: number;
};

type DashboardStatus = {
  status?: SiteStatus;
  stats?: Record<string, unknown>;
  endpoints?: Record<string, unknown>;
};

type ApiResult = DashboardStatus & {
  portal_url?: string;
  site?: SiteStatus;
  dashboard?: DashboardStatus;
  [key: string]: unknown;
};

type ApiEnvelope = { data?: ApiResult };
type Notice = { type: 'success' | 'error'; message: string };

export default class ForumFortressControls extends Component {
  private loadingAction: string | null = null;
  private status: DashboardStatus | null = null;
  private notice: Notice | null = null;

  private async request(path: string, body: Record<string, unknown> = {}, quiet = false): Promise<void> {
    if (this.loadingAction) return;

    this.loadingAction = path;
    if (!quiet) this.notice = null;

    let timeoutId: number | undefined;

    try {
      const method = path === '/forumfortress/status' ? 'GET' : 'POST';
      const apiRequest = app.request<ApiEnvelope>({
        method,
        url: app.forum.attribute('apiUrl') + path,
        ...(method === 'POST' ? { body } : {}),
        errorHandler: () => undefined,
      });
      const timeout = new Promise<never>((_, reject) => {
        const timeoutMs = path === '/forumfortress/sync' ? 30000 : 15000;
        timeoutId = window.setTimeout(() => reject(new Error(this.text('request_timeout'))), timeoutMs);
      });
      const response = await Promise.race([apiRequest, timeout]);
      const result = response.data ?? (response as ApiResult);

      this.consumeResult(path, result, quiet);
    } catch (error: unknown) {
      this.notice = { type: 'error', message: this.errorMessage(error) };
    } finally {
      if (timeoutId !== undefined) window.clearTimeout(timeoutId);
      this.loadingAction = null;
      m.redraw();
    }
  }

  private consumeResult(path: string, result: ApiResult, quiet: boolean): void {
    if (path === '/forumfortress/status') {
      this.status = result;
    } else if (path === '/forumfortress/test' && result.status) {
      this.status = {
        ...(this.status ?? this.cachedStatus() ?? {}),
        status: result.status,
        stats: result.stats ?? this.status?.stats ?? this.cachedStatus()?.stats ?? {},
      };
    } else if (path === '/forumfortress/sync' && result.dashboard) {
      this.status = result.dashboard;
    } else if (path === '/forumfortress/attack-mode' || path === '/forumfortress/attack-mode/end') {
      const current = this.status?.status ?? {};
      this.status = {
        ...(this.status ?? {}),
        status: {
          ...current,
          attack_mode_active: Boolean(result.attack_mode_active),
        },
      };
    }

    if (!quiet) this.notice = { type: 'success', message: this.successMessage(path) };
  }

  private errorMessage(error: unknown): string {
    const requestError = error as {
      response?: {
        error?: unknown;
        detail?: unknown;
        errors?: Array<{ detail?: unknown }>;
      };
      message?: unknown;
    };
    const response = requestError?.response ?? {};
    const detail = response.detail;
    const detailMessage = typeof detail === 'object' && detail !== null && 'message' in detail ? (detail as { message?: unknown }).message : undefined;
    const candidates = [response.error, detailMessage, detail, response.errors?.[0]?.detail, requestError?.message];

    for (const candidate of candidates) {
      if (typeof candidate === 'string' && candidate.trim()) return candidate;
    }

    return this.text('request_failed');
  }

  private successMessage(path: string): string {
    const keys: Record<string, string> = {
      '/forumfortress/test': 'test_success',
      '/forumfortress/register': 'register_success',
      '/forumfortress/attack-mode': 'attack_start_success',
      '/forumfortress/attack-mode/end': 'attack_end_success',
      '/forumfortress/sync': 'sync_success',
    };

    return this.text(keys[path] ?? 'action_success');
  }

  private actionButton(path: string, labelKey: string, icon: string, className = ''): Mithril.Children {
    return m(
      Button,
      {
        className: `Button ForumFortressButton ${className}`,
        icon,
        onclick: () => void this.request(path),
      },
      this.trans(labelKey)
    );
  }

  private portalButton(): Mithril.Children {
    return m(
      'a',
      {
        className: 'Button Button--primary ForumFortressButton ForumFortressButton--portal',
        href: app.forum.attribute('apiUrl') + '/forumfortress/portal-launch',
        target: '_blank',
        rel: 'noopener',
      },
      [m('i.fas.fa-external-link-alt', { 'aria-hidden': 'true' }), m('span', this.trans('portal_login'))]
    );
  }

  private logo(): Mithril.Children {
    return m('.ForumFortressMark', { 'aria-hidden': 'true' }, [m('i'), m('i'), m('i')]);
  }

  view(): Mithril.Children {
    const dashboard = this.status ?? this.cachedStatus();
    const site = dashboard?.status ?? {};
    const stats = dashboard?.stats ?? {};
    const endpoints = dashboard?.endpoints ?? {};
    const settings = app.data.settings as Record<string, string | undefined>;
    const attackMode = Boolean(site.attack_mode_active || site.attack_mode?.enabled);
    const preferredEndpoint =
      endpoints.preferred || site.preferred_endpoint || settings['forumfortress.preferred_endpoint'] || this.text('automatic_selection');
    const siteId = site.site_id || settings['forumfortress.site_id'] || this.text('not_available');
    const unavailableDetail = dashboard ? this.text('not_available') : this.text('refresh_to_view');
    const checks = stats.current_month_checks ?? site.current_month_checks ?? unavailableDetail;
    const allows = stats.allows ?? site.allows ?? unavailableDetail;
    const blocks = stats.blocks ?? site.blocks ?? unavailableDetail;
    const configured = Boolean(settings['forumfortress.site_id']);
    const connectionLabel = dashboard ? 'connected' : configured ? 'configured' : 'not_checked';

    return m('.ForumFortressPanel', [
      m('.ForumFortressCard.ForumFortressHero', [
        m('.ForumFortressHeroHeader', [
          this.logo(),
          m('.ForumFortressHeroCopy', [m('strong', 'Forum Fortress'), m('span', this.trans('tagline'))]),
          m(`.ForumFortressPill.${dashboard ? 'is-connected' : 'is-checking'}`, this.trans(connectionLabel)),
        ]),
        m('.ForumFortressActionGrid', [
          this.portalButton(),
          this.actionButton('/forumfortress/test', 'connection_test', 'fas fa-plug'),
          this.actionButton(
            attackMode ? '/forumfortress/attack-mode/end' : '/forumfortress/attack-mode',
            attackMode ? 'end_attack_mode' : 'enable_attack_mode',
            attackMode ? 'fas fa-shield-alt' : 'fas fa-bolt',
            attackMode ? '' : 'ForumFortressButton--attack'
          ),
          this.actionButton('/forumfortress/sync', 'synchronize_now', 'fas fa-sync-alt'),
        ]),
      ]),

      this.notice
        ? m(`.ForumFortressNotice.is-${this.notice.type}`, [
            m('i', { className: this.notice.type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle' }),
            m('span', this.notice.message),
            m(Button, {
              className: 'Button Button--icon Button--link ForumFortressNoticeDismiss',
              icon: 'fas fa-times',
              title: this.text('dismiss'),
              'aria-label': this.text('dismiss'),
              onclick: () => {
                this.notice = null;
              },
            }),
          ])
        : null,

      m('.ForumFortressCard.ForumFortressStatus', [
        m('.ForumFortressSectionHeader', [
          m('div', [m('strong', this.trans('site_status')), m('span', this.trans('status_summary'))]),
          m(
            Button,
            {
              className: 'Button Button--link ForumFortressRefresh',
              icon: 'fas fa-redo-alt',
              onclick: () => void this.request('/forumfortress/status'),
            },
            this.trans('refresh')
          ),
        ]),
        m('.ForumFortressStatusGrid', [
          this.metric(
            'protection',
            dashboard ? this.text('active') : configured ? this.text('configured') : this.text('not_checked'),
            dashboard || configured ? 'is-good' : ''
          ),
          this.metric('plan', site.plan || this.text('refresh_to_view')),
          this.metric('site_id', siteId),
          this.metric('preferred_endpoint', preferredEndpoint),
          this.metric('checks_this_month', checks),
          this.metric('decisions', `${allows} ${this.text('allowed')} / ${blocks} ${this.text('blocked')}`),
        ]),
      ]),

      m('details.ForumFortressCard.ForumFortressMaintenance', [
        m('summary', [
          m('span', [m('strong', this.trans('maintenance')), m('small', this.trans('maintenance_help'))]),
          m('i.fas.fa-chevron-down'),
        ]),
        m('.ForumFortressMaintenanceBody', [
          m('p', this.trans('registration_help')),
          m('.ForumFortressMaintenanceActions', [this.actionButton('/forumfortress/register', 'register_site', 'fas fa-user-plus')]),
        ]),
      ]),
    ]);
  }

  private metric(labelKey: string, value: unknown, className = ''): Mithril.Children {
    return m('div', { className: `ForumFortressMetric ${className}` }, [m('span', this.trans(labelKey)), m('strong', String(value))]);
  }

  private cachedStatus(): DashboardStatus | null {
    const raw = (app.data.settings as Record<string, string | undefined>)['forumfortress.dashboard_status'];

    if (!raw) return null;

    try {
      const parsed = JSON.parse(raw) as unknown;

      return typeof parsed === 'object' && parsed !== null && Object.keys(parsed).length > 0 ? (parsed as DashboardStatus) : null;
    } catch (_error: unknown) {
      return null;
    }
  }

  private trans(key: string): Mithril.Children {
    return app.translator.trans(`forumfortress-flarum.admin.dashboard.${key}`);
  }

  private text(key: string): string {
    return extractText(this.trans(key));
  }
}
