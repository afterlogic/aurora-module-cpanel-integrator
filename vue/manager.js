import settings from '../../CpanelIntegrator/vue/settings'

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
        title: 'CPANELINTEGRATOR.ADMIN_SETTINGS_TAB_LABEL',
        component () {
          return import('./components/CpanelAdminSettings')
        },
      },
    ]
  },
  getAdminTenantTabs () {
    return [
      {
        tabName: 'cpanel',
        paths: [
          'id/:id/cpanel',
          'search/:search/id/:id/cpanel',
          'page/:page/id/:id/cpanel',
          'search/:search/page/:page/id/:id/cpanel',
        ],
        title: 'CPANELINTEGRATOR.ADMIN_SETTINGS_TAB_LABEL',
        component () {
          return import('./components/CpanelAdminSettingsPerTenant')
        }
      },
    ]
  },
  getAdminUserTabs () {
    return [
      {
        tabName: 'cpanel-aliases',
        title: 'CPANELINTEGRATOR.LABEL_SETTINGS_TAB_ALIASES',
        paths: [
          'id/:id/cpanel-aliases',
          'search/:search/id/:id/cpanel-aliases',
          'page/:page/id/:id/cpanel-aliases',
          'search/:search/page/:page/id/:id/cpanel-aliases',
        ],
        component () {
          return import('./components/CpanelAliasesAdminSettingsPerUser')
        },
      }
    ]
  }
}
