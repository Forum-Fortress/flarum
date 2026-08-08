import app from 'flarum/admin/app';
import ForumFortressPage from './components/ForumFortressPage';

type SettingsRegistrar = {
  registerSetting(setting: Record<string, unknown>): unknown;
  registerPage(page: typeof ForumFortressPage): unknown;
};

type LegacyAdminApp = {
  extensionData?: {
    for(extension: string): SettingsRegistrar;
  };
  registry?: {
    for(extension: string): SettingsRegistrar;
  };
};

const extensionId = 'forumfortress-flarum';

app.initializers.add(extensionId, () => {
  const adminApp = app as unknown as LegacyAdminApp;
  const registrar = adminApp.extensionData?.for(extensionId) ?? adminApp.registry?.for(extensionId);

  if (!registrar) {
    return;
  }

  registrar.registerSetting({
    setting: 'forumfortress.enabled',
    label: app.translator.trans('forumfortress-flarum.admin.settings.enabled_label'),
    help: app.translator.trans('forumfortress-flarum.admin.settings.enabled_help'),
    type: 'boolean',
  });
  registrar.registerSetting({
    setting: 'forumfortress.api_base_url',
    label: app.translator.trans('forumfortress-flarum.admin.settings.api_base_url_label'),
    type: 'url',
    placeholder: 'https://api.ffapi.net',
  });
  registrar.registerSetting({
    setting: 'forumfortress.control_base_url',
    label: app.translator.trans('forumfortress-flarum.admin.settings.control_base_url_label'),
    type: 'url',
    placeholder: 'https://control.ffapi.net',
  });
  registrar.registerSetting({
    setting: 'forumfortress.api_key',
    label: app.translator.trans('forumfortress-flarum.admin.settings.api_key_label'),
    help: app.translator.trans('forumfortress-flarum.admin.settings.api_key_help'),
    type: 'password',
  });
  registrar.registerSetting({
    setting: 'forumfortress.registration_email',
    label: app.translator.trans('forumfortress-flarum.admin.settings.registration_email_label'),
    help: app.translator.trans('forumfortress-flarum.admin.settings.registration_email_help'),
    type: 'email',
  });
  registrar.registerSetting({
    setting: 'forumfortress.preferred_endpoint',
    label: app.translator.trans('forumfortress-flarum.admin.settings.preferred_endpoint_label'),
    help: app.translator.trans('forumfortress-flarum.admin.settings.preferred_endpoint_help'),
    type: 'url',
  });
  registrar.registerSetting({
    setting: 'forumfortress.timeout',
    label: app.translator.trans('forumfortress-flarum.admin.settings.timeout_label'),
    type: 'number',
    min: 1,
    max: 30,
  });
  registrar.registerSetting({
    setting: 'forumfortress.fail_open',
    label: app.translator.trans('forumfortress-flarum.admin.settings.fail_open_label'),
    help: app.translator.trans('forumfortress-flarum.admin.settings.fail_open_help'),
    type: 'boolean',
  });
  registrar.registerSetting({
    setting: 'forumfortress.send_ham',
    label: app.translator.trans('forumfortress-flarum.admin.settings.send_ham_label'),
    type: 'boolean',
  });
  registrar.registerSetting({
    setting: 'forumfortress.block_reject_action',
    label: app.translator.trans('forumfortress-flarum.admin.settings.block_reject_action_label'),
    type: 'select',
    options: {
      reject: app.translator.trans('forumfortress-flarum.admin.settings.block_reject_action_reject'),
      spam_clean: app.translator.trans('forumfortress-flarum.admin.settings.block_reject_action_spam_clean'),
    },
  });
  registrar.registerSetting({
    setting: 'forumfortress.debug_log',
    label: app.translator.trans('forumfortress-flarum.admin.settings.debug_log_label'),
    type: 'boolean',
  });
  registrar.registerPage(ForumFortressPage);
});
