import moduleManager from 'src/modules-manager'

import settings from './settings'
import store from 'src/store'
import eventBus from 'src/event-bus'

import CpanelAdminSettingsPerTenant from './components/CpanelAdminSettingsPerTenant'
import CpanelAliasesAdminSettingsPerUser from './components/CpanelAliasesAdminSettingsPerUser'

const _handleUserDeletion = ({ moduleName, methodName, parameters }) => {
  if (moduleName === 'Core' && methodName === 'DeleteUsers') {
    parameters.DeletionConfirmedByAdmin = true
  }
}

export default {
  moduleName: 'CpanelIntegrator',

  requiredModules: [],

  init (appData) {
    settings.init(appData)
  },

  initSubscriptions (appData) {
    eventBus.$off('webApi::Request', _handleUserDeletion)
    eventBus.$on('webApi::Request', _handleUserDeletion)
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
