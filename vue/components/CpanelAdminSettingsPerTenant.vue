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
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="cpanelHost" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_PORT'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="panelPort" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_USER'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="panelUser" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_PASS'"></div>
            <div class="col-5">
              <q-input outlined dense class="bg-white" v-model="password" ref="oldPassword" type="password" @keyup.enter="save"/>
            </div>
          </div>
        </q-card-section>
      </q-card>
      <div class="q-pt-md text-right">
        <q-btn unelevated no-caps dense class="q-px-sm" :ripple="false" color="primary" @click="save"
               :label="saving ? $t('COREWEBCLIENT.ACTION_SAVE_IN_PROGRESS') : $t('COREWEBCLIENT.ACTION_SAVE')">
        </q-btn>
      </div>
    </div>
    <q-inner-loading style="justify-content: flex-start;" :showing="loading || saving">
      <q-linear-progress query class="q-mt-sm" />
    </q-inner-loading>
    <UnsavedChangesDialog ref="unsavedChangesDialog"/>
  </q-scroll-area>
</template>

<script>
import UnsavedChangesDialog from 'src/components/UnsavedChangesDialog'
import webApi from 'src/utils/web-api'
import notification from 'src/utils/notification'
import errors from 'src/utils/errors'
import cache from 'src/cache'
import _ from 'lodash'

const FAKE_PASS = '     '

export default {
  name: 'CpanelAdminSettingsPerTenant',
  components: {
    UnsavedChangesDialog
  },
  computed: {
    tenantId () {
      return Number(this.$route?.params?.id)
    }
  },
  data() {
    return {
      saving: false,
      loading: false,
      cpanelHost: '',
      panelPort: '',
      panelUser: '',
      cpanelHasPassword: false,
      password: FAKE_PASS,
      savedPass: FAKE_PASS
    }
  },
  beforeRouteLeave (to, from, next) {
    if (this.hasChanges() && _.isFunction(this?.$refs?.unsavedChangesDialog?.openConfirmDiscardChangesDialog)) {
      this.$refs.unsavedChangesDialog.openConfirmDiscardChangesDialog(next)
    } else {
      next()
    }
  },
  mounted() {
    this.populate()
  },
  methods: {
    hasChanges () {
      const cpanelHost = _.isFunction(this.tenant?.getData) ? this.tenant?.getData('CpanelIntegrator::CpanelHost') : ''
      const panelPort = _.isFunction(this.tenant?.getData) ? this.tenant?.getData('CpanelIntegrator::CpanelPort') : ''
      const panelUser = _.isFunction(this.tenant?.getData) ? this.tenant?.getData('CpanelIntegrator::CpanelUser') : ''
      return this.cpanelHost !== cpanelHost ||
          this.panelPort !== panelPort ||
          this.panelUser !== panelUser ||
          this.password !== this.savedPass
    },
    populate () {
      this.loading = true
      cache.getTenant(this.tenantId).then(({ tenant }) => {
        if (tenant.completeData['CpanelIntegrator::CpanelHost'] !== undefined) {
          this.loading = false
          this.tenant = tenant
          this.cpanelHost = tenant.completeData['CpanelIntegrator::CpanelHost']
          this.panelPort = tenant.completeData['CpanelIntegrator::CpanelPort']
          this.panelUser = tenant.completeData['CpanelIntegrator::CpanelUser']
          this.cpanelHasPassword = tenant.completeData['CpanelIntegrator::CpanelHasPassword']
        } else {
          this.getSettings()
        }
      })
    },
    save() {
      if (!this.saving) {
        this.saving = true
        const parameters = {
          CpanelHost: this.cpanelHost,
          CpanelPort: this.panelPort,
          CpanelUser: this.panelUser,
          TenantId: this.tenantId
        }
        if (this.password !== FAKE_PASS) {
          parameters.CpanelPassword = this.password
        }
        webApi.sendRequest({
          moduleName: 'CpanelIntegrator',
          methodName: 'UpdateSettings',
          parameters,
        }).then(result => {
          this.saving = false
          if (result === true) {
            this.savedPass = this.password
            cache.getTenant(parameters.TenantId, true).then(({ tenant }) => {
              tenant.setCompleteData({
                'CpanelIntegrator::CpanelHost': parameters.CpanelHost,
                'CpanelIntegrator::CpanelPort': parameters.CpanelPort,
                'CpanelIntegrator::CpanelUser': parameters.CpanelUser,
                'CpanelIntegrator::CpanelHasPassword': this.password !== ''
              })
              this.populate()
            })
            notification.showReport(this.$t('COREWEBCLIENT.REPORT_SETTINGS_UPDATE_SUCCESS'))
          } else {
            notification.showError(this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED'))
          }
        }, response => {
          this.saving = false
          notification.showError(errors.getTextFromResponse(response, this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED')))
        })
      }
    },
    getSettings () {
      this.loading = true
      const parameters = {
        TenantId: this.tenantId,
      }
      webApi.sendRequest({
        moduleName: 'CpanelIntegrator',
        methodName: 'GetSettings',
        parameters
      }).then(result => {
        if (result) {
          this.loading = false
          cache.getTenant(parameters.TenantId, true).then(({ tenant }) => {
            tenant.setCompleteData({
              'CpanelIntegrator::CpanelHost': result.CpanelHost,
              'CpanelIntegrator::CpanelPort': result.CpanelPort,
              'CpanelIntegrator::CpanelUser': result.CpanelUser,
              'CpanelIntegrator::CpanelHasPassword': result.CpanelHasPassword
            })
            this.populate()
          })
        }
      }, response => {
        notification.showError(errors.getTextFromResponse(response))
      })
    },
  },
}
</script>

<style scoped>

</style>
