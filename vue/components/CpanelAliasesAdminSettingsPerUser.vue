<template>
  <q-scroll-area class="full-height full-width">
    <div class="q-pa-lg ">
      <div class="row q-mb-md">
        <div class="col text-h5" v-t="'CPANELINTEGRATOR.HEADING_SETTINGS_TAB_ALIASES'"></div>
      </div>
      <q-card flat bordered class="card-edit-settings">
        <q-card-section>
          <div class="row q-mb-md">
            <div class="col-2 q-mt-sm" v-t="'CPANELINTEGRATOR.LABEL_ALIAS'"></div>
            <div class="col-3">
              <q-input outlined dense class="bg-white" v-model="aliasName"/>
            </div>
            <div class="q-ml-sm q-mr-xs">
              <span class="text-h6"><b>@</b></span>
            </div>
            <div>
              <q-select outlined dense class="bg-white" v-model="aliasDomain" :options="domainsList"/>
            </div>
            <div class="col-3 q-mt-xs q-ml-md">
              <q-btn unelevated no-caps no-wrap dense class="q-ml-md q-px-sm" :ripple="false" color="primary"
                     :label="$t('CPANELINTEGRATOR.ACTION_ADD_NEW_ALIAS')"
                     @click="addNewAlias"/>
            </div>
          </div>
          <div class="row q-mb-md">
            <div class="col-2"/>
            <div class="col-5">
              <select size="9" class="select" multiple v-model="selectedAliases">
                <option v-for="alias in aliasesList" :key="alias" :value="alias">{{ alias }}</option>
              </select>
            </div>
            <div class="col-3 q-mt-xs q-ml-md" style="position: relative">
              <div style="position: absolute; bottom: 3px;">
                <q-btn unelevated no-caps no-wrap dense class="q-ml-md q-px-sm" :ripple="false" color="primary"
                       :label="$t('CPANELINTEGRATOR.ACTION_DELETE_ALIASES')"
                       @click="deleteAliasesList"/>
              </div>
            </div>
          </div>
        </q-card-section>
      </q-card>
    </div>
    <q-inner-loading style="justify-content: flex-start;" :showing="loading || saving || deleting">
      <q-linear-progress query class="q-mt-sm" />
    </q-inner-loading>
    <UnsavedChangesDialog ref="unsavedChangesDialog"/>
  </q-scroll-area>
</template>

<script>
import UnsavedChangesDialog from 'src/components/UnsavedChangesDialog'

import webApi from 'src/utils/web-api'
import errors from 'src/utils/errors'
import notification from 'src/utils/notification'

import types from 'src/utils/types'

export default {
  name: 'CpanelAliasesAdminSettingsPerUser',
  components: {
    UnsavedChangesDialog
  },
  mounted () {
    this.parseRoute()
  },
  data () {
    return {
      loading: false,
      saving: false,
      deleting: false,
      aliasName: '',
      aliasDomain: '',
      selectedAliases: [],
      aliasesList: [],
      domainsList: ['1', '2', '3'],
      user: null
    }
  },
  computed: {
    tenantId () {
      return this.$store.getters['tenants/getCurrentTenantId']
    }
  },
  methods: {
    parseRoute () {
      const userId = types.pPositiveInt(this.$route?.params?.id)
      if (this.user?.id !== userId) {
        this.user = {
          id: userId,
        }
        this.populate()
      }
    },
    populate () {
      this.getSettings()
    },
    addNewAlias () {
      if (!this.saving) {
        if (this.aliasName.length) {
          this.saving = true
          const parameters = {
            UserId: this.user?.id,
            AliasName: this.aliasName,
            AliasDomain: this.aliasDomain,
            TenantId: this.tenantId,
          }
          webApi.sendRequest({
            moduleName: 'MtaConnector',
            methodName: 'AddNewAlias',
            parameters,
          }).then(result => {
            this.saving = false
            if (result === true) {
              this.aliasName = ''
              this.populate()
              notification.showReport(this.$t('COREWEBCLIENT.REPORT_SETTINGS_UPDATE_SUCCESS'))
            } else {
              notification.showError(this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED'))
            }
          }, response => {
            this.saving = false
            notification.showError(errors.getTextFromResponse(response, this.$t('COREWEBCLIENT.ERROR_SAVING_SETTINGS_FAILED')))
          })
        } else {
          notification.showError(this.$t('COREUSERGROUPSLIMITS.ERROR_EMPTY_RESERVED_NAME'))
        }
      }
    },
    deleteAliasesList () {
      if (!this.deleting) {
        this.deleting = true
        if (this.selectedAliases.length) {
          const parameters = {
            ReservedNames: this.selectedAliases
          }
          webApi.sendRequest({
            moduleName: 'CoreUserGroupsLimits',
            methodName: 'DeleteReservedNames',
            parameters,
          }).then(result => {
            this.deleting = false
            if (result === true) {
              this.populate()
            }
          }, response => {
            this.deleting = false
            notification.showError(errors.getTextFromResponse(response))
          })
        } else {
          this.deleting = false
          notification.showError(this.$t('COREUSERGROUPSLIMITS.ERROR_EMPTY_RESERVED_NAMES'))
        }
      }
    },
    getSettings () {
      this.loading = true
      webApi.sendRequest({
        moduleName: 'CoreUserGroupsLimits',
        methodName: 'GetReservedNames',
        Parameters: {},
      }).then(result => {
        this.loading = false
        if (result) {
          this.reservedList = types.pArray(result)
        }
      },
      response => {
        this.loading = false
        notification.showError(errors.getTextFromResponse(response))
      })
    }
  }
}
</script>

<style scoped>
.select {
  padding: 7px 9px 6px;
  border: 1px solid #cccccc;
  width: 100%;
  overflow-y: scroll;
  border-radius: 4px;
}
</style>
