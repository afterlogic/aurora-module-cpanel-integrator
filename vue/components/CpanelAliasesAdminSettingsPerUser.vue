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
              <q-input outlined dense bg-color="white" v-model="aliasName"/>
            </div>
            <div class="q-ml-sm q-mr-xs">
              <span class="text-h6"><b>@</b></span>
            </div>
            <div>
              <q-select outlined dense bg-color="white" v-model="selectedDomain" :options="domainsList"/>
            </div>
            <div class="col-3 q-mt-xs q-ml-md">
              <q-btn unelevated no-caps no-wrap dense class="q-ml-md q-px-sm" :disable="!aliasName.length" :ripple="false" color="primary"
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
      <q-linear-progress query />
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
import cache from "../../../MailDomains/vue/cache";

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
      selectedDomain: '',
      selectedAliases: [],
      aliasesList: [],
      domainsList: [],
      user: null
    }
  },
  computed: {
    currentTenantId () {
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
      this.getDomains()
      this.getSettings()
    },
    getDomains () {
      cache.getDomains(this.currentTenantId).then(({ domains, totalCount, tenantId }) => {
        if (tenantId === this.currentTenantId) {
          this.domainsList = domains.map(domain => {
            return {
              value: domain.id,
              label: domain.name
            }
          })
          if (this.domainsList.length > 0) {
            this.selectedDomain = this.domainsList[0]
          }
        }
      })
    },
    addNewAlias () {
      if (!this.saving) {
        this.saving = true
        const parameters = {
          UserId: this.user?.id,
          AliasName: this.aliasName,
          AliasDomain: this.selectedDomain.label,
          TenantId: this.currentTenantId,
        }
        webApi.sendRequest({
          moduleName: 'CpanelIntegrator',
          methodName: 'AddNewAlias',
          parameters,
        }).then(result => {
          this.saving = false
          if (result === true) {
            this.aliasName = ''
            this.populate()
          } else {
            notification.showError(this.$t('COREWEBCLIENT.ERROR_DATA_TRANSFER_FAILED'))
          }
        }, response => {
          this.saving = false
          notification.showError(errors.getTextFromResponse(response, this.$t('COREWEBCLIENT.ERROR_DATA_TRANSFER_FAILED')))
        })
      }
    },
    deleteAliasesList () {
      if (!this.deleting) {
        this.deleting = true
        if (this.selectedAliases.length) {
          const parameters = {
            UserId: this.user?.id,
            Aliases: this.selectedAliases,
            TenantId: this.tenantId,
          }
          webApi.sendRequest({
            moduleName: 'CpanelIntegrator',
            methodName: 'DeleteAlias',
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
          notification.showError(this.$t('CPANELINTEGRATOR.ERROR_EMPTY_ALIASES'))
        }
      }
    },
    getSettings() {
      this.loading = true
      const parameters = {
        UserId: this.user?.id,
        TenantId: this.currentTenantId
      }
      webApi.sendRequest({
        moduleName: 'CpanelIntegrator',
        methodName: 'GetAliases',
        parameters
      }).then(result => {
        this.loading = false
        if (types.pArray(result.Aliases)) {
          this.aliasesList = result.Aliases
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
