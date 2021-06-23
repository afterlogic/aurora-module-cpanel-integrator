<template>
  <q-scroll-area class="full-height full-width">
    <div class="q-pa-lg ">
      <div class="row q-mb-md">
        <div class="col text-h5" v-t="'CPANELINTEGRATOR.HEADING_SETTINGS_TAB'"></div>
      </div>
      <q-card flat bordered class="card-edit-settings">
        <q-card-section>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_HOST'"></div>
            <div class="col-4 q-ml-xl">
              <q-input outlined dense class="bg-white" v-model="cpanelHost" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_PORT'"></div>
            <div class="col-4 q-ml-xl">
              <q-input outlined dense class="bg-white" v-model="panelPort" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_USER'"></div>
            <div class="col-4 q-ml-xl">
              <q-input outlined dense class="bg-white" v-model="panelUser" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_PASS'"></div>
            <div class="col-4 q-ml-xl">
              <q-input outlined dense class="bg-white" v-model="password" ref="oldPassword" type="password" @keyup.enter="save"/>
            </div>
          </div>
        </q-card-section>
      </q-card>
      <div class="q-pa-md text-right">
        <q-btn unelevated no-caps dense class="q-px-sm" :ripple="false" color="primary" @click="save"
               :label="saving ? $t('COREWEBCLIENT.ACTION_SAVE_IN_PROGRESS') : $t('COREWEBCLIENT.ACTION_SAVE')">
        </q-btn>
      </div>
    </div>
    <UnsavedChangesDialog ref="unsavedChangesDialog"/>
  </q-scroll-area>
</template>

<script>
import settings from '../../../CpanelIntegrator/vue/settings'
import UnsavedChangesDialog from 'src/components/UnsavedChangesDialog'
import webApi from 'src/utils/web-api'
import notification from 'src/utils/notification'
import errors from 'src/utils/errors'
import _ from 'lodash'

const FAKE_PASS = '     '

export default {
  name: 'CpanelAdminSettings',
  data() {
    return {
      saving: false,
      cpanelHost: '',
      panelPort: '',
      panelUser: '',
      cpanelHasPassword: false,
      password: FAKE_PASS,
      fakePass: FAKE_PASS,
      savedPass: ''
    }
  },
  components: {
    UnsavedChangesDialog
  },
  mounted() {
    this.populate()
  },
  beforeRouteLeave(to, from, next) {
    if (this.hasChanges() && _.isFunction(this?.$refs?.unsavedChangesDialog?.openConfirmDiscardChangesDialog)) {
      this.$refs.unsavedChangesDialog.openConfirmDiscardChangesDialog(next)
    } else {
      next()
    }
  },
  methods: {
    populate () {
      const data = settings.getCpanelSettings()
      this.cpanelHost = data.cpanelHost
      this.panelPort = data.panelPort
      this.panelUser = data.panelUser
      this.savedPass = data.cpanelHasPassword ? FAKE_PASS : ''
      this.password = data.cpanelHasPassword ? FAKE_PASS : ''
    },
    hasChanges() {
      const data = settings.getCpanelSettings()
      return this.cpanelHost !== data.cpanelHost ||
      this.panelPort !== data.panelPort ||
      this.panelUser !== data.panelUser ||
      this.password !== this.savedPass
    },
    save() {
      if (!this.saving) {
        this.saving = true
        const parameters = {
          CpanelHost: this.cpanelHost,
          CpanelPort: this.panelPort,
          CpanelUser: this.panelUser,
        }
        if (this.fakePass !== FAKE_PASS) {
          parameters.CpanelPassword = this.password
        }
        webApi.sendRequest({
          moduleName: 'CpanelIntegrator',
          methodName: 'UpdateSettings',
          parameters,
        }).then(result => {
          this.saving = false
          if (result === true) {
            settings.saveCpanelSettings({
              cpanelHost: this.cpanelHost,
              panelPort: this.panelPort,
              panelUser: this.panelUser,
              cpanelHasPassword: this.password !== '',
            })
            this.savedPass = this.password
            notification.showReport(this.$t('COREWEBCLIENT.REPORT_SETTINGS_UPDATE_SUCCESS'))
          } else {
            notification.showError(this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED'))
          }
        }, response => {
          this.saving = false
          notification.showError(errors.getTextFromResponse(response, this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED')))
        })
      }
    }
  }
}
</script>

<style scoped>

</style>
