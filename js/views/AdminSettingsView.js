'use strict';

var
	_ = require('underscore'),
	ko = require('knockout'),
	
	Ajax = require('%PathToCoreWebclientModule%/js/Ajax.js'),
	ModulesManager = require('%PathToCoreWebclientModule%/js/ModulesManager.js'),
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js'),
	CAbstractSettingsFormView = ModulesManager.run('AdminPanelWebclient', 'getAbstractSettingsFormViewClass'),
	
	Settings = require('modules/%ModuleName%/js/Settings.js')
;

/**
* @constructor
*/
function СAdminSettingsView()
{
	CAbstractSettingsFormView.call(this, Settings.ServerModuleName);
	
	this.sFakePass = '     ';
	// this.iTenantId = null;
	this.sEntityType = '';
	this.iEntityId = null;
	this.showUseDomainSettings = ko.observable(false);
	
	/* Editable fields */
	this.host = ko.observable(Settings.CpanelHost);
	this.port = ko.observable(Settings.CpanelPort);
	this.user = ko.observable(Settings.CpanelUser);
	this.pass = ko.observable(Settings.CpanelHasPassword ? this.sFakePass : '');
	this.useDomainSettings = ko.observable(Settings.UseDomainSettings);
	/*-- Editable fields */
}

_.extendOwn(СAdminSettingsView.prototype, CAbstractSettingsFormView.prototype);

СAdminSettingsView.prototype.ViewTemplate = '%ModuleName%_AdminSettingsView';

СAdminSettingsView.prototype.getCurrentValues = function()
{
	return [
		this.host(),
		this.port(),
		this.user(),
		this.pass()
	];
};

СAdminSettingsView.prototype.revertGlobalValues = function()
{
	this.host(Settings.CpanelHost);
	this.port(Settings.CpanelPort);
	this.user(Settings.CpanelUser);
	this.pass(Settings.CpanelHasPassword ? this.sFakePass : '');
};

СAdminSettingsView.prototype.getParametersForSave = function ()
{
	var oParameters = {
		'CpanelHost': this.host(),
		'CpanelPort': this.port(),
		'CpanelUser': this.user(),
		'CpanelPassword': this.pass() !== this.sFakePass ? this.pass() : ''
	};
	// if (Types.isPositiveNumber(this.iTenantId)) // cPanel settings tab is shown for particular tenant
	// {
	// 	oParameters.TenantId = this.iTenantId;
	// }

	if (this.sEntityType === 'Tenant')
	{
		oParameters.TenantId = this.iEntityId;
		oParameters.UseDomainSettings = this.useDomainSettings();
	}
	else if (this.sEntityType === 'Domain')
	{
		oParameters.DomainId = this.iEntityId;
	}
	return oParameters;
};

/**
 * Applies saved values to the Settings object.
 * 
 * @param {Object} oParameters Parameters which were saved on the server side.
 */
//TODO remove dublicated method

// СAdminSettingsView.prototype.applySavedValues = function (oParameters)
// {
// 	if (!Types.isPositiveNumber(this.iTenantId))
// 	{
// 		Settings.update(oParameters);
// 	}
// };

СAdminSettingsView.prototype.setAccessLevel = function (sEntityType, iEntityId)
{
	this.visible(sEntityType === '' || sEntityType === 'Tenant' || sEntityType === 'Domain');

	this.iTenantId = iEntityId;

	this.sEntityType = sEntityType;
	this.iEntityId = iEntityId;

	this.showUseDomainSettings(sEntityType === 'Tenant');
};

СAdminSettingsView.prototype.onRouteChild = function (aParams)
{
	if (this.sEntityType === 'Tenant')
	{
		this.requestPerTenantSettings();
	}

	if (this.sEntityType === 'Domain')
	{
		this.requestPerDomainSettings();
	}
};

СAdminSettingsView.prototype.requestPerTenantSettings = function ()
{
	if (Types.isPositiveNumber(this.iTenantId))
	{
		this.host('');
		this.port('');
		this.user('');
		this.pass('');
		this.useDomainSettings(false);
		Ajax.send(Settings.ServerModuleName, 'GetSettings', { 'TenantId': this.iTenantId }, function (oResponse) {
			if (oResponse.Result)
			{
				this.host(oResponse.Result.CpanelHost);
				this.port(oResponse.Result.CpanelPort);
				this.user(oResponse.Result.CpanelUser);
				this.pass(oResponse.Result.CpanelHasPassword ? this.sFakePass : '');
				this.useDomainSettings(oResponse.Result.UseDomainSettings);
				this.updateSavedState();
			}
		}, this);
	}
	else
	{
		this.revertGlobalValues();
	}
};

СAdminSettingsView.prototype.requestPerDomainSettings = function ()
{
	if (Types.isPositiveNumber(this.iEntityId))
	{
		this.host('');
		this.port('');
		this.user('');
		this.pass('');
		Ajax.send(Settings.ServerModuleName, 'GetDomainSettings', { 'DomainId': this.iEntityId }, function (oResponse) {
			if (oResponse.Result)
			{
				this.host(oResponse.Result.CpanelHost);
				this.port(oResponse.Result.CpanelPort);
				this.user(oResponse.Result.CpanelUser);
				this.pass(oResponse.Result.CpanelHasPassword ? this.sFakePass : '');
				this.updateSavedState();
			}
		}, this);
	}
	else
	{
		this.revertGlobalValues();
	}
};

/**
 * Applies saved values to the Settings object.
 * 
 * @param {Object} oParameters Parameters which were saved on the server side.
 */
СAdminSettingsView.prototype.applySavedValues = function (oParameters)
{
	if (!oParameters.TenantId && !oParameters.DomainId)
	{
		Settings.updateAdmin(oParameters.CpanelHost, oParameters.CpanelPort, oParameters.CpanelUser);
	}
};

module.exports = new СAdminSettingsView();
