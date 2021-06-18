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
}
