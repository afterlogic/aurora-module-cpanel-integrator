'use strict';

module.exports = function (oAppData) {
	var
		App = require('%PathToCoreWebclientModule%/js/App.js'),
				
		TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),
		
		ModulesManager = require('%PathToCoreWebclientModule%/js/ModulesManager.js'),

		Cache = null,
		Settings = require('modules/%ModuleName%/js/Settings.js')
	;
	
	Settings.init(oAppData);
	
	if (ModulesManager.isModuleAvailable(Settings.ServerModuleName) && ModulesManager.isModuleAvailable('MailDomains'))
	{
		Cache = require('modules/%ModuleName%/js/Cache.js');
		if (App.getUserRole() === Enums.UserRole.SuperAdmin || App.getUserRole() === Enums.UserRole.TenantAdmin)
		{
			return {
				/**
				 * Registers admin settings tabs before application start.
				 * 
				 * @param {Object} ModulesManager
				 */
				start: function (ModulesManager)
				{
					if (Settings.AllowAliases)
					{
						ModulesManager.run('AdminPanelWebclient', 'registerAdminPanelTab', [
							function(resolve) {
								require.ensure(
									['modules/%ModuleName%/js/views/AliasesPerUserAdminSettingsView.js'],
									function() {
										resolve(require('modules/%ModuleName%/js/views/AliasesPerUserAdminSettingsView.js'));
									},
									'admin-bundle'
								);
							},
							Settings.HashModuleName + '-aliases',
							TextUtils.i18n('%MODULENAME%/LABEL_SETTINGS_TAB_ALIASES')
						]);
					}
				}
			};
		}
	}
	
	return null;
};