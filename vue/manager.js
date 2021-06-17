import settings from '../../CpanelIntegrator/vue/settings'

export default {
  init (appData) {
    settings.init(appData)
  },
  getAdminSystemTabs () {
    return [
      {
        name: 'cpanel',
        title: 'CPANELINTEGRATOR.ADMIN_SETTINGS_TAB_LABEL',
        component () {
          return import('src/../../../CpanelIntegrator/vue/components/CpanelAdminSettings')
        },
      },
    ]
  },
}
