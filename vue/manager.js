import moduleManager from 'src/modules-manager'

import settings from './settings'
import store from 'src/store'

import CpanelAdminSettingsPerTenant from './components/CpanelAdminSettingsPerTenant'
import CpanelAliasesAdminSettingsPerUser from './components/CpanelAliasesAdminSettingsPerUser'

export default {
  moduleName: 'CpanelIntegrator',

  requiredModules: [],

  init (appData) {
    settings.init(appData)
  },

  getAdminSystemTabs () {
    return [
      {
        tabName: 'cpanel',
        tabTitle: 'CPANELINTEGRATOR.ADMIN_SETTINGS_TAB_LABEL',
        tabRouteChildren: [
          { path: 'cpanel', component: () => import('./components/CpanelAdminSettings') },
        ],
      },
    ]
  },

  getAdminTenantTabs () {
    return [
      {
        tabName: 'cpanel',
        tabTitle: 'CPANELINTEGRATOR.ADMIN_SETTINGS_TAB_LABEL',
        tabRouteChildren: [
          { path: 'id/:id/cpanel', component: CpanelAdminSettingsPerTenant },
          { path: 'search/:search/id/:id/cpanel', component: CpanelAdminSettingsPerTenant },
          { path: 'page/:page/id/:id/cpanel', component: CpanelAdminSettingsPerTenant },
          { path: 'search/:search/page/:page/id/:id/cpanel', component: CpanelAdminSettingsPerTenant },
        ],
      },
    ]
  },

  getAdminUserTabs() {
    const isUserSuperAdmin = store.getters['user/isUserSuperAdmin']
    if (moduleManager.isModuleAvailable('MailDomains') && isUserSuperAdmin) {
      return [
        {
          tabName: 'cpanel-aliases',
          tabTitle: 'CPANELINTEGRATOR.LABEL_SETTINGS_TAB_ALIASES',
          tabRouteChildren: [
            { path: 'id/:id/cpanel-aliases', component: CpanelAliasesAdminSettingsPerUser },
            { path: 'search/:search/id/:id/cpanel-aliases', component: CpanelAliasesAdminSettingsPerUser },
            { path: 'page/:page/id/:id/cpanel-aliases', component: CpanelAliasesAdminSettingsPerUser },
            { path: 'search/:search/page/:page/id/:id/cpanel-aliases', component: CpanelAliasesAdminSettingsPerUser },
          ],
        }
      ]
    }
    return []
  },
}
