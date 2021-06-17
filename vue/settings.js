import _ from 'lodash'

import typesUtils from 'src/utils/types'

class CpanelSettings {
  constructor (appData) {
    const cPanelWebclientData = typesUtils.pObject(appData.CpanelIntegrator)
    if (!_.isEmpty(cPanelWebclientData)) {
      this.allowAliases = cPanelWebclientData.AllowAliases
      this.allowCreateDeleteAccountOnCpanel = cPanelWebclientData.AllowCreateDeleteAccountOnCpanel
      this.cpanelHasPassword = cPanelWebclientData.CpanelHasPassword
      this.cpanelHost = cPanelWebclientData.CpanelHost
      this.panelPort = cPanelWebclientData.CpanelPort
      this.panelUser = cPanelWebclientData.CpanelUser
    }
  }

  saveCpanelSettings ({ cpanelHasPassword, cpanelHost, panelPort, panelUser }) {
    this.cpanelHasPassword = cpanelHasPassword
    this.cpanelHost = cpanelHost
    this.panelPort = panelPort
    this.panelUser = panelUser
  }
}

let settings = null

export default {
  init (appData) {
    settings = new CpanelSettings(appData)
  },
  saveCpanelSettings (data) {
    settings.saveCpanelSettings(data)
  },
  getCpanelSettings () {
    return {
      allowAliases: settings.allowAliases,
      allowCreateDeleteAccountOnCpanel: settings.allowCreateDeleteAccountOnCpanel,
      cpanelHasPassword: settings.cpanelHasPassword,
      cpanelHost: settings.cpanelHost,
      panelPort: settings.panelPort,
      panelUser: settings.panelUser
    }
  },

}
