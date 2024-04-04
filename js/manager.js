'use strict';

module.exports = function (appData) {
	const
		ko = require('knockout'),
		App = require('%PathToCoreWebclientModule%/js/App.js'),

		TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),
		Settings = require('modules/%ModuleName%/js/Settings.js')
	;
	Settings.init(appData);

	if (!App.isUserNormalOrTenant()) {
		return null;
	}

	return {
		start: function (ModulesManager) {
			if (!ModulesManager.isModuleEnabled('MailWebclient')) {
				return;
			}

			const AccountImapPrefixSettingsView = require('modules/%ModuleName%/js/views/AccountImapPrefixSettingsView.js')
			App.subscribeEvent('MailWebclient::ConstructView::after', function (oParams) {
				if ('CAccountsSettingsPaneView' === oParams.Name)
				{
					const AccountSettingsView = oParams.View;

					const ImapFolderPrefix = ko.observable(true);
					AccountSettingsView.aAccountTabs.push({
						name: 'imap-folder-prefix',
						title: TextUtils.i18n('%MODULENAME%/LABEL_FOLDER_PREFIX_SETTINGS_TAB'),
						view: AccountImapPrefixSettingsView,
						visible: ImapFolderPrefix
					})
					AccountSettingsView.editedIdentity.valueHasMutated();
				}
			});
		}
	};
};