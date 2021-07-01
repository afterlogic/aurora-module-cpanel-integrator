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
          return import('src/../../../CpanelIntegrator/vue/components/CpanelAdminSettings')
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
          return import('src/../../../CpanelIntegrator/vue/components/CpanelAdminSettingsPerTenant')
        }
      }
    ]
  },
}
