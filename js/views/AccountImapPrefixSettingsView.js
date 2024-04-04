'use strict';

var
	_ = require('underscore'),
	ko = require('knockout'),

	TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js'),
	Ajax = require('%PathToCoreWebclientModule%/js/Ajax.js'),

	Api = require('%PathToCoreWebclientModule%/js/Api.js'),
	ModulesManager = require('%PathToCoreWebclientModule%/js/ModulesManager.js'),
	Screens = require('%PathToCoreWebclientModule%/js/Screens.js'),

	CAbstractSettingsFormView = ModulesManager.run('SettingsWebclient', 'getAbstractSettingsFormViewClass'),

	AccountList = require('modules/MailWebclient/js/AccountList.js'),

	Settings = require('modules/%ModuleName%/js/Settings.js')
;

/**
 * @constructor
 */ 
function AccountImapPrefixSettingsView()
{
	CAbstractSettingsFormView.call(this, '%ModuleName%')

	this.prefix = ko.observable('')
}

_.extendOwn(AccountImapPrefixSettingsView.prototype, CAbstractSettingsFormView.prototype);

AccountImapPrefixSettingsView.prototype.ViewTemplate = '%ModuleName%_AccountImapPrefixSettingsView';

AccountImapPrefixSettingsView.prototype.onShow = function ()
{
	this.populate()
};

AccountImapPrefixSettingsView.prototype.getCurrentValues = function ()
{
	return [ this.prefix() ]
}

AccountImapPrefixSettingsView.prototype.getParametersForSave = function ()
{
	const oAccount = AccountList.getEdited()
	let params = {}

	if (oAccount) {
		params = {
			'AccountId': oAccount.id(),
			'Prefix': this.prefix(),
		}
	}

	return params
}

AccountImapPrefixSettingsView.prototype.save = function ()
{
	this.isSaving(true)

	Ajax.send(Settings.ServerModuleName, 'UpdateAccountSettings', this.getParametersForSave(), this.onUpdateSettingsResponse, this)
}

/**
 * @param {Object} oResponse
 * @param {Object} oRequest
 */
AccountImapPrefixSettingsView.prototype.onUpdateSettingsResponse = function (oResponse, oRequest)
{
	this.isSaving(false)

	if (oResponse.Result === false) {
		Api.showErrorByCode(oResponse, TextUtils.i18n('COREWEBCLIENT/ERROR_SAVING_SETTINGS_FAILED'))
	} else {
		this.updateSavedState()
		Screens.showReport(TextUtils.i18n('COREWEBCLIENT/REPORT_SETTINGS_UPDATE_SUCCESS'))
	}
}

AccountImapPrefixSettingsView.prototype.populate = function()
{
	const oAccount = AccountList.getEdited()

	if (oAccount) {
		Ajax.send(Settings.ServerModuleName, 'GetAccountSettings', {'AccountId': oAccount.id()}, this.onGetSettingsResponse, this)
	}
	
	this.updateSavedState()
}

/**
 * @param {Object} oResponse
 * @param {Object} oRequest
 */
AccountImapPrefixSettingsView.prototype.onGetSettingsResponse = function (oResponse, oRequest)
{
	const oResult = oResponse && oResponse.Result

	if (oResult) {
		this.prefix(Types.pString(oResult.Prefix));

		this.updateSavedState();
	}
}

module.exports = new AccountImapPrefixSettingsView();