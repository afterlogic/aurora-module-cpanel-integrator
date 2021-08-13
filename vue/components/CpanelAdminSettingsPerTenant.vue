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
              <q-input outlined dense bg-color="white" v-model="cpanelHost" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_PORT'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" v-model="cpanelPort" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_USER'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" v-model="cpanelUser" @keyup.enter="save"/>
            </div>
          </div>
          <div class="row">
            <div class="col-2 q-my-sm" v-t="'CPANELINTEGRATOR.LABEL_CPANEL_PASS'"></div>
            <div class="col-5">
              <q-input outlined dense bg-color="white" type="password" autocomplete="new-password"
                       v-model="password" @keyup.enter="save"/>
            </div>
          </div>
        </q-card-section>
      </q-card>
      <div class="q-pt-md text-right">
        <q-btn unelevated no-caps dense class="q-px-sm" :ripple="false" color="primary" @click="save"
               :label="$t('COREWEBCLIENT.ACTION_SAVE')">
        </q-btn>
      </div>
    </div>
    <q-inner-loading style="justify-content: flex-start;" :showing="loading || saving">
      <q-linear-progress query />
    </q-inner-loading>
  </q-scroll-area>
</template>

<script>
import errors from 'src/utils/errors'
import notification from 'src/utils/notification'
import types from 'src/utils/types'
import webApi from 'src/utils/web-api'

const FAKE_PASS = '     '

export default {
  name: 'CpanelAdminSettingsPerTenant',

  data() {
    return {
      saving: false,
      loading: false,
      cpanelHost: '',
      cpanelPort: '',
      cpanelUser: '',
      cpanelHasPassword: false,
      password: FAKE_PASS,
      savedPass: FAKE_PASS
    }
  },

  computed: {
    tenantId () {
      return this.$store.getters['tenants/getCurrentTenantId']
    },

    allTenants () {
      return this.$store.getters['tenants/getTenants']
    },
  },

  watch: {
    allTenants () {
      this.populate()
    },
  },

  beforeRouteLeave (to, from, next) {
    this.doBeforeRouteLeave(to, from, next)
  },

  mounted() {
    this.loading = false
    this.saving = false
    this.populate()
  },

  methods: {
    /**
     * Method is used in doBeforeRouteLeave mixin
     */
    hasChanges () {
      const tenantCompleteData = types.pObject(this.tenant?.completeData)
      const cpanelPort = tenantCompleteData['CpanelIntegrator::CpanelPort']
      return this.cpanelHost !== tenantCompleteData['CpanelIntegrator::CpanelHost'] ||
          types.pInt(this.cpanelPort) !== cpanelPort ||
          this.cpanelUser !== tenantCompleteData['CpanelIntegrator::CpanelUser'] ||
          this.password !== this.savedPass
    },

    /**
     * Method is used in doBeforeRouteLeave mixin,
     * do not use async methods - just simple and plain reverting of values
     * !! hasChanges method must return true after executing revertChanges method
     */
    revertChanges () {
      const tenantCompleteData = types.pObject(this.tenant?.completeData)
      this.cpanelHost = tenantCompleteData['CpanelIntegrator::CpanelHost']
      this.cpanelPort = tenantCompleteData['CpanelIntegrator::CpanelPort']
      this.cpanelUser = tenantCompleteData['CpanelIntegrator::CpanelUser']
      this.password = this.savedPass
    },

    populate () {
      const tenant = this.$store.getters['tenants/getTenant'](this.tenantId)
      if (tenant) {
        if (tenant.completeData['CpanelIntegrator::CpanelHost'] !== undefined) {
          this.tenant = tenant
          this.cpanelHost = tenant.completeData['CpanelIntegrator::CpanelHost']
          this.cpanelPort = tenant.completeData['CpanelIntegrator::CpanelPort']
          this.cpanelUser = tenant.completeData['CpanelIntegrator::CpanelUser']
          this.cpanelHasPassword = tenant.completeData['CpanelIntegrator::CpanelHasPassword']
        } else {
          this.getSettings()
        }
      }
    },

    save () {
      if (!this.saving) {
        this.saving = true
        const parameters = {
          CpanelHost: this.cpanelHost,
          CpanelPort: types.pInt(this.cpanelPort),
          CpanelUser: this.cpanelUser,
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
            const data = {
              'CpanelIntegrator::CpanelHost': parameters.CpanelHost,
              'CpanelIntegrator::CpanelPort': parameters.CpanelPort,
              'CpanelIntegrator::CpanelUser': parameters.CpanelUser,
              'CpanelIntegrator::CpanelHasPassword': this.password !== ''
            }
            this.$store.commit('tenants/setTenantCompleteData', { id: this.tenantId, data })
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
        this.loading = false
        if (result) {
          const data = {
            'CpanelIntegrator::CpanelHost': types.pString(result.CpanelHost),
            'CpanelIntegrator::CpanelPort': types.pInt(result.CpanelPort),
            'CpanelIntegrator::CpanelUser': types.pString(result.CpanelUser),
            'CpanelIntegrator::CpanelHasPassword': types.pBool(result.CpanelHasPassword),
          }
          this.$store.commit('tenants/setTenantCompleteData', { id: this.tenantId, data })
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
