import app from "flarum/admin/app";
import type Mithril from "mithril";
import ForumFortressPage from "./components/ForumFortressPage";

type SettingsRegistrar = {
  registerSetting(
    setting: Record<string, unknown> | ((this: ForumFortressPage) => Mithril.Children)
  ): unknown;
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

const extensionId = "forumfortress-flarum";

app.initializers.add(extensionId, () => {
  const adminApp = app as unknown as LegacyAdminApp;
  const registrar =
    adminApp.extensionData?.for(extensionId) ??
    adminApp.registry?.for(extensionId);

  if (!registrar) {
    return;
  }

  registrar.registerSetting({
    setting: "forumfortress.enabled",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.enabled_label"
    ),
    help: app.translator.trans(
      "forumfortress-flarum.admin.settings.enabled_help"
    ),
    type: "boolean",
  });
  registrar.registerSetting({
    setting: "forumfortress.repeat_offender_suspend",
    label: app.translator.trans("forumfortress-flarum.admin.settings.repeat_offender_label"),
    help: app.translator.trans(
      app.data.settings["forumfortress.repeat_offender_available"] === "1"
        ? "forumfortress-flarum.admin.settings.repeat_offender_help"
        : "forumfortress-flarum.admin.settings.repeat_offender_unavailable"
    ),
    type: "boolean",
    disabled: app.data.settings["forumfortress.repeat_offender_available"] !== "1",
  });
  registrar.registerSetting({
    setting: "forumfortress.api_region",
    label: app.translator.trans("forumfortress-flarum.admin.settings.api_region_label"),
    help: app.translator.trans("forumfortress-flarum.admin.settings.api_region_help"),
    type: "select",
    options: {
      global: app.translator.trans("forumfortress-flarum.admin.settings.region_global"),
      uk: app.translator.trans("forumfortress-flarum.admin.settings.region_uk"),
      eu: app.translator.trans("forumfortress-flarum.admin.settings.region_eu"),
      us: app.translator.trans("forumfortress-flarum.admin.settings.region_us"),
    },
  });
  registrar.registerSetting({
    setting: "forumfortress.allow_global_fallback",
    label: app.translator.trans("forumfortress-flarum.admin.settings.allow_global_fallback_label"),
    help: app.translator.trans("forumfortress-flarum.admin.settings.allow_global_fallback_help"),
    type: "boolean",
  });
  registrar.registerSetting({
    setting: "forumfortress.api_key",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.api_key_label"
    ),
    help: app.translator.trans(
      "forumfortress-flarum.admin.settings.api_key_help"
    ),
    type: "password",
  });
  registrar.registerSetting({
    setting: "forumfortress.registration_email",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.registration_email_label"
    ),
    help: app.translator.trans(
      "forumfortress-flarum.admin.settings.registration_email_help"
    ),
    type: "email",
  });
  registrar.registerSetting({
    setting: "forumfortress.timeout",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.timeout_label"
    ),
    type: "number",
    min: 1,
    max: 30,
  });
  registrar.registerSetting({
    setting: "forumfortress.fail_open",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.fail_open_label"
    ),
    help: app.translator.trans(
      "forumfortress-flarum.admin.settings.fail_open_help"
    ),
    type: "boolean",
  });
  registrar.registerSetting({
    setting: "forumfortress.block_reject_action",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.block_reject_action_label"
    ),
    type: "select",
    options: {
      reject: app.translator.trans(
        "forumfortress-flarum.admin.settings.block_reject_action_reject"
      ),
      spam_clean: app.translator.trans(
        "forumfortress-flarum.admin.settings.block_reject_action_spam_clean"
      ),
    },
  });
  registrar.registerSetting({
    setting: "forumfortress.debug_log",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.debug_log_label"
    ),
    type: "boolean",
  });
  registrar.registerSetting(() =>
    m("h3", app.translator.trans(
      "forumfortress-flarum.admin.settings.advanced_proxy_settings"
    ))
  );
  registrar.registerSetting(function (this: ForumFortressPage) {
    const address = app.data.settings["forumfortress.proxy_address_hint"];
    const header = app.data.settings["forumfortress.proxy_header_hint"];
    const trustedProxies = this.setting("forumfortress.trusted_proxies");
    const addresses = (trustedProxies() || "").split(",").map((value) => value.trim()).filter(Boolean);

    if (!address || !header || addresses.includes(address)) {
      return null;
    }

    return m(".Form-group", [
      m("p", app.translator.trans(
        "forumfortress-flarum.admin.settings.proxy_hint",
        { address, header }
      )),
      m("button.Button", {
        type: "button",
        onclick: () => {
          trustedProxies([...addresses, address].join(", "));
          if (addresses.length === 0) {
            this.setting("forumfortress.forwarded_ip_header")(header);
          }
        },
      }, app.translator.trans(
        "forumfortress-flarum.admin.settings.add_detected_proxy"
      )),
    ]);
  });
  registrar.registerSetting({
    setting: "forumfortress.trusted_proxies",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.trusted_proxies_label"
    ),
    help: app.translator.trans(
      "forumfortress-flarum.admin.settings.trusted_proxies_help"
    ),
    type: "text",
  });
  registrar.registerSetting({
    setting: "forumfortress.forwarded_ip_header",
    label: app.translator.trans(
      "forumfortress-flarum.admin.settings.forwarded_ip_header_label"
    ),
    help: app.translator.trans(
      "forumfortress-flarum.admin.settings.forwarded_ip_header_help"
    ),
    type: "select",
    options: {
      "X-Forwarded-For": "X-Forwarded-For",
      Forwarded: "Forwarded",
      "X-Real-IP": "X-Real-IP",
      "CF-Connecting-IP": "CF-Connecting-IP",
      "True-Client-IP": "True-Client-IP",
    },
  });
  registrar.registerPage(ForumFortressPage);
});
