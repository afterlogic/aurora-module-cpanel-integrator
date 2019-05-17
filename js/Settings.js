'use strict';

var
	_ = require('underscore'),
	
	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js')
;

module.exports = {
	ServerModuleName: '%ModuleName%',
	HashModuleName: 'cpanel',

	AllowAliases: false,

	/**
	 * Initializes settings from AppData object sections.
	 * 
	 * @param {Object} oAppData Object contained modules settings.
	 */
	init: function (oAppData)
	{
		var oAppDataSection = oAppData[this.ServerModuleName] || {};
		
		if (!_.isEmpty(oAppDataSection))
		{
			this.AllowAliases = Types.pBool(oAppDataSection.AllowAliases, this.AllowAliases);
		}
	}
};
