<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\CpanelIntegrator;

use Aurora\Modules\Core\Classes\Tenant;
use Aurora\Modules\Core\Module as CoreModule;
use Aurora\Modules\Mail\Module as MailModule;
use Aurora\Modules\MailDomains\Classes\Domain;
use Aurora\System\Exceptions\ApiException;
use Aurora\System\Notifications;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    public const UAPI = '3';
    private $oCpanel = [];

    protected $aManagers = [
        'Aliases' => null
    ];

    public $oMailModule = null;

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    public function init()
    {
        $this->aErrors = [
            Enums\ErrorCodes::DomainOutsideTenant		=> $this->i18N('ERROR_DOMAIN_OUTSIDE_TENANT'),
            Enums\ErrorCodes::AliaMatchesExistingEmail	=> $this->i18N('ERROR_CREATE_ALIAS'),
            Enums\ErrorCodes::AliasAlreadyExists		=> $this->i18N('ERROR_ALIAS_ALREADY_EXISTS'),
        ];
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($this->oModuleSettings->AllowCreateDeleteAccountOnCpanel && $oAuthenticatedUser &&
                ($oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)) {
            // Subscription shouldn't work for Anonymous because Signup subscription will work
            // Subscription shouldn't work for Normal user because CPanel account should be created only for first user account
            $this->subscribeEvent('Mail::CreateAccount::before', array($this, 'onBeforeCreateAccount'));
            $this->subscribeEvent('Mail::BeforeDeleteAccount', array($this, 'onBeforeDeleteAccount'));
        }
        $this->subscribeEvent('MailSignup::Signup::before', [$this, 'onAfterSignup']);
        $this->subscribeEvent('Mail::Account::ToResponseArray', array($this, 'onMailAccountToResponseArray'));
        $this->subscribeEvent('ChangeAccountPassword', array($this, 'onChangeAccountPassword'));
        $this->subscribeEvent('Mail::UpdateForward::before', array($this, 'onBeforeUpdateForward'));
        $this->subscribeEvent('Mail::GetForward::before', array($this, 'onBeforeGetForward'));
        $this->subscribeEvent('Mail::GetAutoresponder::before', array($this, 'onBeforeGetAutoresponder'));
        $this->subscribeEvent('Mail::UpdateAutoresponder::before', array($this, 'onBeforeUpdateAutoresponder'));
        $this->subscribeEvent('Mail::GetFilters::before', array($this, 'onBeforeGetFilters'));
        $this->subscribeEvent('Mail::UpdateFilters::before', array($this, 'onBeforeUpdateFilters'));
        $this->subscribeEvent('Mail::UpdateQuota', array($this, 'onUpdateQuota'));
        $this->subscribeEvent('Mail::SaveMessage::before', array($this, 'onBeforeSendOrSaveMessage'));
        $this->subscribeEvent('Mail::SendMessage::before', array($this, 'onBeforeSendOrSaveMessage'));

        $this->subscribeEvent('Mail::IsEmailAllowedForCreation::after', array($this, 'onAfterIsEmailAllowedForCreation'));
    }

    /**
     * @param string $sManager
     * @return object
     */
    public function getManager($sManager)
    {
        if ($this->aManagers[$sManager] === null) {
            $sManagerClass = Module::getNamespace() . "\\Managers\\" . $sManager;
            $this->aManagers[$sManager] = new $sManagerClass($this);
        }

        return $this->aManagers[$sManager];
    }

    /**
     * Connects to cPanel. Uses main credentials if $iTenantId has not been passed, otherwise - tenant credentials to cPanel.
     * @param int $iTenantId
     * @return object
     */
    protected function getCpanel($iTenantId = 0)
    {
        if (!isset($this->oCpanel[$iTenantId])) {
            $sHost = $this->oModuleSettings->CpanelHost;
            $sPort = $this->oModuleSettings->CpanelPort;
            $sUser = $this->oModuleSettings->CpanelUser;
            $sPassword = $this->oModuleSettings->CpanelPassword;
            if ($sPassword && !\Aurora\System\Utils::IsEncryptedValue($sPassword)) {
                $bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
                $this->Decorator()->UpdateSettings($sHost, $sPort, $sUser, \Aurora\System\Utils::EncryptValue($sPassword), null);
                $bPrevState = \Aurora\System\Api::skipCheckUserRole($bPrevState);
            } else {
                $sPassword = \Aurora\System\Utils::DecryptValue($sPassword);
            }

            $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
            if (\Aurora\System\Api::GetSettings()->GetValue('EnableMultiTenant') && $iTenantId !== 0 && $oAuthenticatedUser && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin) {
                $oSettings = $this->oModuleSettings;
                $oTenant = \Aurora\System\Api::getTenantById($iTenantId);
                $sHost = $oSettings->GetTenantValue($oTenant->Name, 'CpanelHost', '');
                $sPort = $oSettings->GetTenantValue($oTenant->Name, 'CpanelPort', '');
                $sUser = $oSettings->GetTenantValue($oTenant->Name, 'CpanelUser', '');
                $sPassword = $oSettings->GetTenantValue($oTenant->Name, 'CpanelPassword', '');
                if ($sPassword && !\Aurora\System\Utils::IsEncryptedValue($sPassword)) {
                    if ($oSettings->IsTenantSettingsExists($oTenant->Name)) {
                        $this->Decorator()->UpdateSettings($sHost, $sPort, $sUser, \Aurora\System\Utils::EncryptValue($sPassword), $iTenantId);
                    }
                } else {
                    $sPassword = \Aurora\System\Utils::DecryptValue($sPassword);
                }
            }

            $this->oCpanel[$iTenantId] = new \Gufy\CpanelPhp\Cpanel([
                'host' => "https://" . $sHost . ":" . $sPort,
                'username' => $sUser,
                'auth_type' => 'password',
                'password' => $sPassword,
            ]);

            $this->oCpanel[$iTenantId]->setTimeout(30);
        }

        return $this->oCpanel[$iTenantId];
    }

    /**
     * Executes cPanel command, logs parameters and the result.
     * @param object $oCpanel
     * @param string $sModule
     * @param string $sFunction
     * @param array $aParams
     * @return string
     */
    protected function executeCpanelAction($oCpanel, $sModule, $sFunction, $aParams)
    {
        // There are no params in log because they can contain private data (password for example)
        \Aurora\System\Api::Log('cPanel execute action. Module ' . $sModule . '. Function ' . $sFunction);
        $sResult = $oCpanel->execute_action(self::UAPI, $sModule, $sFunction, $oCpanel->getUsername(), $aParams);
        \Aurora\System\Api::Log($sResult);
        return $sResult;
    }

    /**
     * Creates account with credentials specified in registration form
     *
     * @param array $aArgs New account credentials.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterSignup($aArgs, &$mResult)
    {
        if (isset($aArgs['Login']) && isset($aArgs['Password']) && !empty(trim($aArgs['Password'])) && !empty(trim($aArgs['Login']))) {
            $sLogin = trim($aArgs['Login']);
            $sPassword = trim($aArgs['Password']);
            $sFriendlyName = isset($aArgs['Name']) ? trim($aArgs['Name']) : '';
            $bSignMe = isset($aArgs['SignMe']) ? (bool) $aArgs['SignMe'] : false;
            $bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
            $iUserId = \Aurora\Modules\Core\Module::Decorator()->CreateUser(0, $sLogin);
            $oUser = \Aurora\System\Api::getUserById((int) $iUserId);
            $oCpanel = null;
            if ($oUser instanceof \Aurora\Modules\Core\Models\User) {
                try {
                    $oCpanel = $this->getCpanel($oUser->IdTenant);
                } catch(\Exception $oException) {
                }

                if ($oCpanel) {
                    $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oUser->PublicId);
                    if (!empty($sDomain) && $this->isDomainSupported($sDomain)) {
                        $iQuota = (int) $this->oModuleSettings->UserDefaultQuotaMB;
                        try {
                            $sCpanelResponse = $this->executeCpanelAction(
                                $oCpanel,
                                'Email',
                                'add_pop',
                                [
                                    'email' => $sLogin,
                                    'password' => $sPassword,
                                    'quota'	=> $iQuota,
                                    'domain' => $sDomain
                                ]
                            );
                            $aParseResult = self::parseResponse($sCpanelResponse, false);
                        } catch(\Exception $oException) {
                            throw new ApiException(0, $oException, $oException->getMessage());
                        }
                        if (is_array($aParseResult) && isset($aParseResult['Data']) && !empty($aParseResult['Data'])) {
                            try {
                                $oAccount = \Aurora\Modules\Mail\Module::Decorator()->CreateAccount($oUser->Id, $sFriendlyName, $sLogin, $sLogin, $sPassword);
                                if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount) {
                                    $iTime = $bSignMe ? 0 : time();
                                    $sAuthToken = \Aurora\System\Api::UserSession()->Set(
                                        [
                                            'token'		=> 'auth',
                                            'sign-me'		=> $bSignMe,
                                            'id'			=> $oAccount->IdUser,
                                            'account'		=> $oAccount->Id,
                                            'account_type'	=> $oAccount->getName()
                                        ],
                                        $iTime
                                    );
                                    $mResult = [\Aurora\System\Application::AUTH_TOKEN_KEY => $sAuthToken];
                                }
                            } catch (\Exception $oException) {
                                if ($oException instanceof \Aurora\Modules\Mail\Exceptions\Exception &&
                                    $oException->getCode() === \Aurora\Modules\Mail\Enums\ErrorCodes::CannotLoginCredentialsIncorrect) {
                                    \Aurora\Modules\Core\Module::Decorator()->DeleteUser($oUser->Id);
                                }
                                throw $oException;
                            }
                        } elseif (is_array($aParseResult) && isset($aParseResult['Error'])) {
                            //If Account wasn't created - delete user
                            $bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
                            \Aurora\Modules\Core\Module::Decorator()->DeleteUser($oUser->Id);
                            \Aurora\System\Api::skipCheckUserRole($bPrevState);
                            throw new \Exception($aParseResult['Error']);
                        }
                    }
                }
            }
            \Aurora\System\Api::skipCheckUserRole($bPrevState);
        }

        return true; // break subscriptions to prevent account creation in other modules
    }

    /**
     * Creates cPanel account before mail account will be created in the system.
     * @param array $aArgs
     * @param mixed $mResult
     * @throws \Exception
     */
    public function onBeforeCreateAccount($aArgs, &$mResult)
    {
        $iUserId = $aArgs['UserId'];
        $oUser = \Aurora\System\Api::getUserById($iUserId);
        $sAccountEmail = $aArgs['Email'];
        if ($this->isAliasExists($sAccountEmail, $oUser->IdTenant)) {
            throw new \Exception($this->i18N('ERROR_EMAIL_MATCHES_ALIAS'));
        }
        if ($oUser instanceof \Aurora\Modules\Core\Models\User && $sAccountEmail === $oUser->PublicId) {
            $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sAccountEmail);
            if (!empty($sDomain) && $this->isDomainSupported($sDomain)) {
                $aTenantQuota = \Aurora\Modules\Mail\Module::Decorator()->GetEntitySpaceLimits('Tenant', $oUser->Id, $oUser->IdTenant);
                $iQuota = $aTenantQuota ? $aTenantQuota['UserSpaceLimitMb'] : (int) $this->oModuleSettings->UserDefaultQuotaMB;
                $oCpanel = $this->getCpanel($oUser->IdTenant);
                $sCpanelResponse = $this->executeCpanelAction(
                    $oCpanel,
                    'Email',
                    'add_pop',
                    [
                        'email' => $aArgs['IncomingLogin'],
                        'password' => $aArgs['IncomingPassword'],
                        'quota' => $iQuota,
                        'domain' => $sDomain
                    ]
                );
                $aResult = self::parseResponse($sCpanelResponse, false);
                if ($aResult['Status'] === false && isset($aResult['Error']) && !empty($aResult['Error']) && strrpos(strtolower($aResult['Error']), 'exists') === false) {
                    throw new \Exception($aResult['Error']);
                }
            }
        }
    }

    /**
     * Deletes cPanel account, its aliases, forward, autoresponder and filters.
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onBeforeDeleteAccount($aArgs, &$mResult)
    {
        $oAccount = $aArgs['Account'];
        $oUser = $aArgs['User'];
        if ($oUser instanceof \Aurora\Modules\Core\Models\User
                && $oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)
                && $oAccount->Email === $oUser->PublicId
        ) {
            $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
            if ($sDomain !== '') {
                $aAliases = self::Decorator()->GetAliases($oUser->Id);
                if (isset($aAliases['Aliases']) && is_array($aAliases['Aliases']) && count($aAliases['Aliases']) > 0) {
                    self::Decorator()->DeleteAliases($oUser->Id, $aAliases['Aliases']);
                }

                $aForwardArgs = [
                    'AccountID' => $oAccount->Id,
                    'Enable' => false,
                    'Email' => '',
                ];
                $mForwardResult = false;
                $this->onBeforeUpdateForward($aForwardArgs, $mForwardResult);

                $this->deleteAutoresponder($oAccount->Email, $oUser->IdTenant);

                //Checking if an account exists on CPanel
                $oCpanel = $this->getCpanel($oUser->IdTenant);
                if ($oCpanel) {
                    $sCpanelResponse = $this->executeCpanelAction(
                        $oCpanel,
                        'Email',
                        'list_pops',
                        ['regex' => $oAccount->Email]
                    );
                    $aParseResult = self::parseResponse($sCpanelResponse);
                    //Trying to delete an Account only if it exists on CPanel
                    if (
                        $aParseResult
                        && isset($aParseResult['Data'])
                        && is_array($aParseResult['Data'])
                        && count($aParseResult['Data']) > 0
                        && $this->oModuleSettings->AllowCreateDeleteAccountOnCpanel
                    ) {
                        $sCpanelResponse = $this->executeCpanelAction(
                            $oCpanel,
                            'Email',
                            'delete_pop',
                            [
                                'email' => $oAccount->Email,
                                'domain' => $sDomain,
                            ]
                        );
                        self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
                    }
                }
            }
        }
    }

    /**
     * Updates mail quota on cPanel.
     * @param array $aArgs
     * @param mixed $mResult
     */
    public function onUpdateQuota($aArgs, &$mResult)
    {
        $iTenantId = $aArgs['TenantId'];
        $sEmail = $aArgs['Email'];
        $iQuota = $aArgs['QuotaMb'];
        $oCpanel = $this->getCpanel($iTenantId);
        $sLogin = \MailSo\Base\Utils::GetAccountNameFromEmail($sEmail);
        $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sEmail);
        if ($this->isDomainSupported($sDomain)) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'edit_pop_quota',
                [
                    'email' => $sLogin,
                    'domain' => $sDomain,
                    'quota' => $iQuota
                ]
            );
            $mResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
        }
    }

    public function onBeforeSendOrSaveMessage(&$aArgs, &$mResult)
    {
        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAlias = $this->getManager('Aliases')->getAlias((int) $aArgs['AliasID']);
        if ($oAlias instanceof \Aurora\Modules\CpanelIntegrator\Models\Alias
            && $oUser instanceof \Aurora\Modules\Core\Models\User
            && $oAlias->IdUser === $oUser->Id
        ) {
            $aArgs['Alias'] = $oAlias;
        }
    }

    /**
     * Adds to account response array information about if it is allowed to change the password for this account.
     * @param array $aArguments
     * @param mixed $mResult
     */
    public function onMailAccountToResponseArray($aArguments, &$mResult)
    {
        $oAccount = $aArguments['Account'];

        if ($oAccount && $this->isAccountServerSupported($oAccount)) {
            if (!isset($mResult['Extend']) || !is_array($mResult['Extend'])) {
                $mResult['Extend'] = [];
            }
            $mResult['Extend']['AllowChangePasswordOnMailServer'] = true;

            if (class_exists('\Aurora\Modules\Mail\Module')) {
                $oMailModule = \Aurora\Modules\Mail\Module::getInstance();

                $mResult['AllowFilters'] = $oMailModule->oModuleSettings->AllowFilters;
                $mResult['AllowForward'] = $oMailModule->oModuleSettings->AllowForward;
                $mResult['AllowAutoresponder'] = $oMailModule->oModuleSettings->AllowAutoresponder;
            }

            $oUser = \Aurora\Api::getUserById($oAccount->IdUser);
            if ($oAccount->Email === $oUser->PublicId) {
                try {
                    $aAliases = self::Decorator()->GetAliases($oAccount->IdUser);
                    $mResult['Extend']['Aliases'] = is_array($aAliases) && isset($aAliases['Aliases']) ? $aAliases['Aliases'] : [];
                } catch (\Exception $oException) {
                }
            }
        }
    }

    /**
     * Tries to change password for account if it is allowed.
     * @param array $aArguments
     * @param mixed $mResult
     */
    public function onChangeAccountPassword($aArguments, &$mResult)
    {
        $bPasswordChanged = false;
        $bBreakSubscriptions = false;

        $oAccount = $aArguments['Account'];
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)
                && ($oAccount->getPassword() === $aArguments['CurrentPassword'])) {
            $bPasswordChanged = $this->changePassword($oAccount, $aArguments['NewPassword']);
            $bBreakSubscriptions = true; // break if Cpanel plugin tries to change password in this account.
        }

        if (is_array($mResult)) {
            $mResult['AccountPasswordChanged'] = $mResult['AccountPasswordChanged'] || $bPasswordChanged;
        }

        return $bBreakSubscriptions;
    }

    /**
     * Updates forward for specified account.
     * @param array $aArgs
     * @param mixed $mResult
     * @return boolean
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onBeforeUpdateForward($aArgs, &$mResult)
    {
        if (!isset($aArgs['AccountID']) || !isset($aArgs['Enable']) || !isset($aArgs['Email'])
                || $aArgs['Enable'] && (empty(trim($aArgs['Email'])) || !filter_var(trim($aArgs['Email']), FILTER_VALIDATE_EMAIL))) {
            throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
        }

        $sToEmail = trim($aArgs['Email']);

        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAccount = MailModule::getInstance()->GetAccount($aArgs['AccountID']);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount && $this->isAccountServerSupported($oAccount)) {
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
            // check if account belongs to authenticated user
            && ($oAccount->IdUser === $oAuthenticatedUser->Id
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)) {
                $oCpanel = null;
                $oUser = \Aurora\Api::getUserById($oAccount->IdUser);
                if ($oUser instanceof \Aurora\Modules\Core\Models\User) {
                    $oCpanel = $this->getCpanel($oUser->IdTenant);
                }
                if ($oCpanel) {
                    $sFromEmail = $oAccount->Email;
                    $sFromDomain = \MailSo\Base\Utils::GetDomainFromEmail($sFromEmail);

                    // check if there is forwarder on cPanel
                    $aResult = $this->getForwarder($sFromDomain, $sFromEmail, $oUser->IdTenant);
                    $bForwarderExists = is_array($aResult) && isset($aResult['Email']) && !empty($aResult['Email']);
                    $sOldToEmail = $bForwarderExists ? $aResult['Email'] : '';
                    // "Enable" indicates if forwarder should exist on cPanel
                    if ($aArgs['Enable']) {
                        if ($bForwarderExists) {
                            $bSameForwarderExists = $bForwarderExists && $sOldToEmail === $sToEmail;
                            if ($bSameForwarderExists) {
                                $mResult = true;
                            } elseif ($this->deleteForwarder($sFromEmail, $sOldToEmail, $oUser->IdTenant)) {
                                $mResult = $this->createForwarder($sFromDomain, $sFromEmail, $sToEmail, $oUser->IdTenant);
                            }
                        } else {
                            $mResult = $this->createForwarder($sFromDomain, $sFromEmail, $sToEmail, $oUser->IdTenant);
                        }
                    } else {
                        if ($bForwarderExists) {
                            $mResult = $this->deleteForwarder($sFromEmail, $sOldToEmail, $oUser->IdTenant);
                        } else {
                            $mResult = true;
                        }
                    }
                }
            }

            return true; // breaking subscriptions to prevent update in parent module
        }

        return false;
    }

    /**
     * Obtains forward data for specified account.
     * @param array $aArgs
     * @param mixed $mResult
     * @return boolean
     */
    public function onBeforeGetForward($aArgs, &$mResult)
    {
        $mResult = false;

        if (isset($aArgs['AccountID'])) {
            $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
            $oAccount = \Aurora\Modules\Mail\Module::getInstance()->GetAccount($aArgs['AccountID']);
            if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                    && $this->isAccountServerSupported($oAccount)) {
                if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
                    // check if account belongs to authenticated user
                    && $oAccount->IdUser === $oAuthenticatedUser->Id) {
                    $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
                    $aResult = $this->getForwarder($sDomain, $oAccount->Email, $oAuthenticatedUser->IdTenant);

                    if (is_array($aResult)) {
                        if (isset($aResult['Email'])) {
                            $mResult = [
                                'Enable' => true,
                                'Email' => $aResult['Email']
                            ];
                        } else {
                            $mResult = [
                                'Enable' => false,
                                'Email' => ''
                            ];
                        }
                    }
                }
                return true; // breaking subscriptions to prevent update in parent module
            }
        }

        return false;
    }

    /**
     * Obtains autoresponder for specified account.
     * @param array $aArgs
     * @param mixed $mResult
     * @return boolean
     */
    public function onBeforeGetAutoresponder($aArgs, &$mResult)
    {
        $mResult = false;

        if (isset($aArgs['AccountID'])) {
            $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
            $oAccount = MailModule::getInstance()->GetAccount($aArgs['AccountID']);
            if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                    && $this->isAccountServerSupported($oAccount)) {
                if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
                    // check if account belongs to authenticated user
                    && $oAccount->IdUser === $oAuthenticatedUser->Id) {
                    $mAutoresponder = $this->getAutoresponder($oAccount->Email, $oAuthenticatedUser->IdTenant);

                    if (is_array($mAutoresponder) && isset($mAutoresponder['Enable'])) {
                        $mResult = [
                            'Enable' => $mAutoresponder['Enable'],
                            'Subject' => $mAutoresponder['Subject'],
                            'Message' => $mAutoresponder['Message']
                        ];
                    }
                }
                return true; // breaking subscriptions to prevent update in parent module
            }
        }

        return false;
    }

    /**
     * Updates autoresponder data.
     * @param array $aArgs
     * @param mixed $mResult
     * @return boolean
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onBeforeUpdateAutoresponder($aArgs, &$mResult)
    {
        if (!isset($aArgs['AccountID']) || !isset($aArgs['Enable']) || !isset($aArgs['Subject']) || !isset($aArgs['Message'])
                || $aArgs['Enable'] && (empty(trim($aArgs['Subject'])) || empty(trim($aArgs['Message'])))) {
            throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
        }

        $mResult = false;

        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAccount = MailModule::getInstance()->GetAccount($aArgs['AccountID']);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)) {
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
                // check if account belongs to authenticated user
                && $oAccount->IdUser === $oAuthenticatedUser->Id) {
                $sSubject = trim($aArgs['Subject']);
                $sMessage = trim($aArgs['Message']);
                $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
                $mResult = $this->updateAutoresponder($sDomain, $oAccount->Email, $sSubject, $sMessage, $aArgs['Enable'], $oAuthenticatedUser->IdTenant);
            }

            return true; // breaking subscriptions to prevent update in parent module
        }

        return false;
    }

    /**
     * Obtains filters for specified account.
     * @param array $aArgs
     * @param mixed $mResult
     * @return boolean
     */
    public function onBeforeGetFilters($aArgs, &$mResult)
    {
        if (!isset($aArgs['AccountID'])) {
            throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
        }

        $oAccount = \Aurora\Modules\Mail\Module::getInstance()->GetAccount($aArgs['AccountID']);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)) {
            $mResult = [];

            $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
            // check if account belongs to authenticated user
            && $oAccount->IdUser === $oAuthenticatedUser->Id) {
                $oCpanel = $this->getCpanel($oAuthenticatedUser->IdTenant);
                if ($oCpanel) {
                    $mResult = $this->getFilters($oAccount, $oAuthenticatedUser->IdTenant);
                }
            }

            return true; // breaking subscriptions to prevent update in parent module
        }

        return false;
    }

    /**
     * Updates filters.
     * @param array $aArgs
     * @param mixed $mResult
     * @return boolean
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function onBeforeUpdateFilters($aArgs, &$mResult)
    {
        if (!isset($aArgs['AccountID']) || !isset($aArgs['Filters']) || !is_array($aArgs['Filters'])) {
            throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
        }

        $oAccount = \Aurora\Modules\Mail\Module::getInstance()->GetAccount($aArgs['AccountID']);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)) {
            $mResult = false;

            $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
                // check if account belongs to authenticated user
                && $oAccount->IdUser === $oAuthenticatedUser->Id) {
                $oCpanel = $this->getCpanel($oAuthenticatedUser->IdTenant);
                if ($oCpanel && $this->removeSupportedFilters($oAccount, $oAuthenticatedUser->IdTenant)) {
                    if (count($aArgs['Filters']) === 0) {
                        $mResult = true;
                    } else {
                        foreach ($aArgs['Filters'] as $aWebmailFilter) {
                            $aFilterProperty = self::convertWebmailFIlterToCPanelFIlter($aWebmailFilter, $oAccount);
                            //create filter
                            $sCpanelResponse = $this->executeCpanelAction(
                                $oCpanel,
                                'Email',
                                'store_filter',
                                $aFilterProperty
                            );
                            $aCreationResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
                            //disable filter if needed
                            if (!$aCreationResult['Status']) {
                                $mResult = false;
                                break;
                            }
                            if (!$aWebmailFilter['Enable']) {
                                $sCpanelResponse = $this->executeCpanelAction(
                                    $oCpanel,
                                    'Email',
                                    'disable_filter',
                                    [
                                        'account' => $oAccount->Email,
                                        'filtername' => $aFilterProperty['filtername']
                                    ]
                                );
                                self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
                            }
                            $mResult = true;
                        }
                    }
                }
            }

            return true; // breaking subscriptions to prevent update in parent module
        }

        return false;
    }

    public function onAfterIsEmailAllowedForCreation($aArgs, &$mResult)
    {
        if ($mResult && isset($aArgs['Email'])) {
            $iTenantId = 0;
            $oUser = \Aurora\Modules\Core\Module::Decorator()->GetUserByPublicId($aArgs['Email']);
            if ($oUser) {
                $iTenantId = $oUser->IdTenant;
            }
            $mResult = !$this->isAliasExists($aArgs['Email'], $iTenantId);
        }
    }

    /**
     * Checks if the CpanelIntegrator module can work with the specified domain.
     * @param string $sDomain
     * @return bool
     */
    protected function isDomainSupported($sDomain)
    {
        $bSupported = in_array('*', $this->oModuleSettings->SupportedServers);

        if (!$bSupported) {
            $aGetMailServerResult = MailModule::Decorator()->GetMailServerByDomain($sDomain, /*AllowWildcardDomain*/true);
            if (!empty($aGetMailServerResult) && isset($aGetMailServerResult['Server']) && $aGetMailServerResult['Server'] instanceof \Aurora\Modules\Mail\Models\Server) {
                $bSupported = in_array($aGetMailServerResult['Server']->IncomingServer, $this->oModuleSettings->SupportedServers);
            }
        }

        return $bSupported;
    }

    /**
     * Checks if the CpanelIntegrator module can work with the server of the specified account.
     * @param \Aurora\Modules\Mail\Models\MailAccount $oAccount
     * @return bool
     */
    protected function isAccountServerSupported($oAccount)
    {
        $bSupported = in_array('*', $this->oModuleSettings->SupportedServers);

        if (!$bSupported) {
            $oServer = $oAccount->getServer();
            if ($oServer instanceof \Aurora\Modules\Mail\Models\Server) {
                $bSupported = in_array($oServer->IncomingServer, $this->oModuleSettings->SupportedServers);
            }
        }

        return $bSupported;
    }

    /**
     * Tries to change password for account.
     * @param \Aurora\Modules\Mail\Models\MailAccount $oAccount
     * @param string $sPassword
     * @return boolean
     * @throws \Aurora\System\Exceptions\ApiException
     */
    protected function changePassword($oAccount, $sPassword)
    {
        $bResult = false;

        if (0 < strlen($oAccount->IncomingPassword) && $oAccount->IncomingPassword !== $sPassword) {
            $cpanel_host = $this->oModuleSettings->CpanelHost;
            $cpanel_user = $this->oModuleSettings->CpanelUser;
            $cpanel_pass = $this->oModuleSettings->CpanelPassword;
            $cpanel_user0 = null;
            if ($cpanel_pass && !\Aurora\System\Utils::IsEncryptedValue($cpanel_pass)) {
                $bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
                $this->Decorator()->UpdateSettings($cpanel_host, $this->oModuleSettings->CpanelPort, $cpanel_user, \Aurora\System\Utils::EncryptValue($cpanel_pass), null);
                $bPrevState = \Aurora\System\Api::skipCheckUserRole($bPrevState);
            } else {
                $cpanel_pass = \Aurora\System\Utils::DecryptValue($cpanel_pass);
            }

            $oSettings = &\Aurora\System\Api::GetSettings();
            $oUser = \Aurora\System\Api::getUserById($oAccount->IdUser);
            $oTenant = $oUser instanceof \Aurora\Modules\Core\Models\User ? \Aurora\System\Api::getTenantById($oUser->IdTenant) : null;
            if ($oSettings->GetValue('EnableMultiTenant') && $oTenant instanceof \Aurora\Modules\Core\Models\Tenant) {
                $cpanel_host = $this->oModuleSettings->GetTenantValue($oTenant->Name, 'CpanelHost', '');
                $cpanel_user = $this->oModuleSettings->GetTenantValue($oTenant->Name, 'CpanelUser', '');
                $cpanel_pass = $this->oModuleSettings->GetTenantValue($oTenant->Name, 'CpanelPassword', '');
                if ($cpanel_pass && !\Aurora\System\Utils::IsEncryptedValue($cpanel_pass)) {
                    if ($this->oModuleSettings->IsTenantSettingsExists($oTenant->Name)) {
                        $bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
                        $this->Decorator()->UpdateSettings($cpanel_host, $this->oModuleSettings->GetTenantValue($oTenant->Name, 'CpanelPort'), $cpanel_user, \Aurora\System\Utils::EncryptValue($cpanel_pass), $oTenant->Id);
                        $bPrevState = \Aurora\System\Api::skipCheckUserRole($bPrevState);
                    }
                } else {
                    $cpanel_pass = \Aurora\System\Utils::DecryptValue($cpanel_pass);
                }
            }

            $email_user = urlencode($oAccount->Email);
            $email_pass = urlencode($sPassword);
            $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);

            if ($cpanel_user == 'root') {
                $query = 'https://' . $cpanel_host . ':2087/json-api/listaccts?api.version=1&searchtype=domain&search=' . $sDomain;

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($curl, CURLOPT_HEADER, 0);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                $header[0] = 'Authorization: Basic ' . base64_encode($cpanel_user . ':' . $cpanel_pass) . "\n\r";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
                curl_setopt($curl, CURLOPT_URL, $query);
                $result = curl_exec($curl);
                if ($result == false) {
                    \Aurora\System\Api::Log('curl_exec threw error "' . curl_error($curl) . '" for ' . $query);
                    curl_close($curl);
                    throw new ApiException(Notifications::CanNotChangePassword);
                } else {
                    curl_close($curl);
                    \Aurora\System\Api::Log('..:: QUERY0 ::.. ' . $query);
                    $json_res = json_decode($result, true);
                    \Aurora\System\Api::Log('..:: RESULT0 ::.. ' . $result);
                    if (isset($json_res['data']['acct'][0]['user'])) {
                        $cpanel_user0 = $json_res['data']['acct'][0]['user'];
                        \Aurora\System\Api::Log('..:: USER ::.. ' . $cpanel_user0);
                    }
                }
                $query = 'https://' . $cpanel_host . ':2087/json-api/cpanel?cpanel_jsonapi_user=' . $cpanel_user0 . '&cpanel_jsonapi_module=Email&cpanel_jsonapi_func=passwdpop&cpanel_jsonapi_apiversion=2&email=' . $email_user . '&password=' . $email_pass . '&domain=' . $sDomain;
            } else {
                $query = 'https://' . $cpanel_host . ':2083/execute/Email/passwd_pop?email=' . $email_user . '&password=' . $email_pass . '&domain=' . $sDomain;
            }

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_HEADER, 0);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $header[0] = 'Authorization: Basic ' . base64_encode($cpanel_user . ':' . $cpanel_pass) . "\n\r";
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_URL, $query);
            $result = curl_exec($curl);
            if ($result === false) {
                \Aurora\System\Api::Log('curl_exec threw error "' . curl_error($curl) . '" for ' . $query);
                curl_close($curl);
                throw new ApiException(Notifications::CanNotChangePassword);
            } else {
                curl_close($curl);
                \Aurora\System\Api::Log('..:: QUERY ::.. ' . $query);
                $json_res = json_decode($result, true);
                \Aurora\System\Api::Log('..:: RESULT ::.. ' . $result);
                if ((isset($json_res['errors'])) && ($json_res['errors'] !== null)) {
                    $sErrorText = is_string($json_res['errors']) ? $json_res['errors'] : (is_array($json_res['errors']) && isset($json_res['errors'][0]) ? $json_res['errors'][0] : '');
                    throw new ApiException(Notifications::CanNotChangePassword, null, $sErrorText);
                } else {
                    $bResult = true;
                }
            }
        }

        return $bResult;
    }

    /**
     * Obtains forwarder from cPanel.
     * @param string $sDomain
     * @param string $sEmail
     * @return array
     */
    protected function getForwarder($sDomain, $sEmail, $iTenantId = 0)
    {
        $aResult = [];

        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel && $sDomain && $sEmail) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'list_forwarders',
                [
                    'domain' => $sDomain,
                    'regex' => '^' . $sEmail . '$'
                ]
            );
            $aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
            $sForwardScriptPath = $this->oModuleSettings->ForwardScriptPath ?: \dirname(__FILE__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process_mail.php';
            if ($aParseResult
                && isset($aParseResult['Data'])
                && is_array($aParseResult['Data'])
                && count($aParseResult['Data']) > 0
            ) {
                foreach ($aParseResult['Data'] as $oData) {
                    if ($oData->forward !== '|' . $sForwardScriptPath) {
                        $aResult = [
                            'Email' => $oData->forward
                        ];
                        break;
                    }
                }
            }
        }

        return $aResult;
    }

    /**
     * Deletes forwarder from cPanel.
     * @param string $sAddress
     * @param string $sForwarder
     * @param int $iTenantId
     * @return boolean
     */
    protected function deleteForwarder($sAddress, $sForwarder, $iTenantId = 0)
    {
        $oCpanel = $this->getCpanel($iTenantId);

        if ($oCpanel && $sAddress && $sForwarder) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'delete_forwarder',
                [
                    'address' => $sAddress,
                    'forwarder' => $sForwarder
                ]
            );
            self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
            return true;
        }

        return false;
    }

    /**
     * Creates forwarder on cPanel.
     * @param string $sDomain
     * @param string $sEmail
     * @param string $sForwardEmail
     * @param int $iTenantId
     * @return boolean
     */
    protected function createForwarder($sDomain, $sEmail, $sForwardEmail, $iTenantId = 0)
    {
        $oCpanel = $this->getCpanel($iTenantId);

        if ($oCpanel && $sDomain && $sEmail && $sForwardEmail) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'add_forwarder',
                [
                    'domain' => $sDomain,
                    'email' => $sEmail,
                    'fwdopt' => 'fwd',
                    'fwdemail' => $sForwardEmail
                ]
            );
            self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
            return true;
        }

        return false;
    }

    /**
     * Obtains domain forwarders.
     * @param string $sEmail
     * @param string $sDomain
     * @param int $iTenantId
     * @return array
     */
    protected function getDomainForwarders($sEmail, $sDomain, $iTenantId = 0)
    {
        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel && $sDomain && $sEmail) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'list_forwarders',
                [
                    'domain' => $sDomain
                ]
            );
            $aResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
            if (is_array($aResult) && isset($aResult['Data'])) {
                return $aResult['Data'];
            }
        }

        return [];
    }

    /**
     * Obtains autoresponder on cPanel.
     * @param string $sEmail
     * @return array|boolean
     */
    protected function getAutoresponder($sEmail, $iTenantId = 0)
    {
        $mResult = false;

        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel && $sEmail) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'get_auto_responder',
                [
                    'email' => $sEmail
                ]
            );
            $aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
            if ($aParseResult
                && isset($aParseResult['Data'])
                && isset($aParseResult['Data']->subject)
            ) {
                if (isset($aParseResult['Data']->stop) && $aParseResult['Data']->stop !== null && $aParseResult['Data']->stop < time()) {
                    $bEnable = false;
                } else {
                    $bEnable = true;
                }
                $mResult = [
                    'Subject' => $aParseResult['Data']->subject,
                    'Message' => isset($aParseResult['Data']->body) ? $aParseResult['Data']->body : '',
                    'Enable' => $bEnable
                ];
            }
        }

        return $mResult;
    }

    /**
     * Updates autoresponder on cPanel.
     * @param string $sDomain
     * @param string $sEmail
     * @param string $sSubject
     * @param string $sMessage
     * @param boolean $bEnable
     * @return array
     */
    protected function updateAutoresponder($sDomain, $sEmail, $sSubject, $sMessage, $bEnable, $iTenantId = 0)
    {
        $aResult = [
            'Status' => false
        ];

        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel && $sDomain && $sEmail && $sSubject && $sMessage) {
            $iStartTime = 0;
            $iStopTime = 0;
            if (!$bEnable) {
                $iStopTime = time();
                $iStartTime = $iStopTime - 1;
            }
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'add_auto_responder',
                [
                    'email' => $sEmail,
                    'from' => '',
                    'subject' => $sSubject,
                    'body' => $sMessage,
                    'domain' => $sDomain,
                    'is_html' => 0,
                    'interval' => 8,
                    'start' => $iStartTime,
                    'stop' => $iStopTime
                ]
            );
            $aResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
        }

        return $aResult;
    }

    /**
     * Deletes autoresponder from cPanel.
     * @param string $sEmail
     */
    protected function deleteAutoresponder($sEmail, $iTenantId = 0)
    {
        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel && $sEmail) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'delete_auto_responder',
                [
                    'email'	=> $sEmail,
                ]
            );
            self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
        }
    }

    /**
     * Obtains filters from cPanel.
     * @param object $oAccount
     * @return array
     */
    protected function getFilters($oAccount, $iTenantId = 0)
    {
        $aFilters = [];

        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel && $oAccount) {
            $sCpanelResponse = $this->executeCpanelAction(
                $oCpanel,
                'Email',
                'list_filters',
                [
                    'account' => $oAccount->Email
                ]
            );
            $aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
            if ($aParseResult && isset($aParseResult['Data'])) {
                $aFilters = self::convertCPanelFIltersToWebmailFIlters($aParseResult['Data'], $oAccount);
            }
        }

        return $aFilters;
    }

    /**
     * Removes filters supported by the system from cPanel.
     * @param object $oAccount
     * @return boolean
     */
    protected function removeSupportedFilters($oAccount, $iTenantId = 0)
    {
        $bResult = false;

        if ($oAccount) {
            $aFilters = $this->getFilters($oAccount, $iTenantId);
            if (is_array($aFilters)) {
                if (count($aFilters) === 0) {
                    $bResult = true;
                } else {
                    $aSuportedFilterNames = array_map(function ($aFilter) {
                        return $aFilter['Filtername'];
                    }, $aFilters);

                    $oCpanel = $this->getCpanel($iTenantId);
                    if ($oCpanel) {
                        $bDelResult = true;
                        foreach ($aSuportedFilterNames as $sSuportedFilterName) {
                            $sCpanelResponse = $this->executeCpanelAction(
                                $oCpanel,
                                'Email',
                                'delete_filter',
                                [
                                    'account' => $oAccount->Email,
                                    'filtername' => $sSuportedFilterName
                                ]
                            );
                            $aDelResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
                            if (!$aDelResult['Status']) {
                                $bDelResult = false;
                                break;
                            }
                        }
                        $bResult = $bDelResult;
                    }
                }
            }
        }

        return $bResult;
    }

    /**
     * Obtains namespace for mail account from IMAP.
     * @staticvar object $oNamespace
     * @param object $oAccount
     * @return object
     */
    protected static function getImapNamespace($oAccount)
    {
        static $oNamespace = null;

        if ($oNamespace === null) {
            $oNamespace = MailModule::getInstance()->getMailManager()->_getImapClient($oAccount)->GetNamespace();
        }

        return $oNamespace;
    }

    /**
     * Parses response from cPanel.
     * @param string $sResponse
     * @param boolean $bAllowException
     * @return array
     * @throws \Exception
     */
    protected static function parseResponse($sResponse, $bAllowException = true)
    {
        $aResult = [
            'Status' => false
        ];

        $oResult = \json_decode($sResponse);

        if ($oResult
            && isset($oResult->result)
            && isset($oResult->result->status)
            && $oResult->result->status === 1
            && (isset($oResult->result->data)
                || empty($oResult->result->data))
        ) {
            $aResult = [
                'Status' => true,
                'Data' => $oResult->result->data ?? null
            ];
        } elseif ($oResult && isset($oResult->error)) {
            $aResult = [
                'Status' => false,
                'Error' => $oResult->error
            ];
        } elseif ($oResult && isset($oResult->result)
            && isset($oResult->result->errors) && !empty($oResult->result->errors)
            && isset($oResult->result->errors[0])) {
            $aResult = [
                'Status' => false,
                'Error' => $oResult->result->errors[0]
            ];
        } else {
            $aResult = [
                'Status' => false,
                'Error' => $sResponse
            ];
        }

        if ($bAllowException && $aResult['Status'] === false) {
            throw new \Exception(trim($aResult['Error'], ' ()'));
        }

        return $aResult;
    }

    /**
     * Converts cPanel filters to webmail filters.
     * @param array $aCPanelFilters
     * @param object $oAccount
     * @return array
     */
    protected static function convertCPanelFIltersToWebmailFIlters($aCPanelFilters, $oAccount)
    {
        $aResult = [];

        foreach ($aCPanelFilters as $oCPanelFilter) {
            $iAction = null;
            $iCondition = null;
            $iField = null;

            if ($oCPanelFilter->actions[0]->action === 'save') {
                if ($oCPanelFilter->actions[0]->dest === '/dev/null') {
                    $iAction = \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately;
                } else {
                    $iAction = \Aurora\Modules\Mail\Enums\FilterAction::MoveToFolder;
                }
            }

            $sDestEmail = "";
            if ($oCPanelFilter->actions[0]->action === 'deliver') {
                $sDestEmail = $oCPanelFilter->actions[0]->dest;
                $iAction = \Aurora\Modules\Mail\Enums\FilterAction::Redirect;
            }

            switch ($oCPanelFilter->rules[0]->match) {
                case 'contains':
                    $iCondition = \Aurora\Modules\Mail\Enums\FilterCondition::ContainSubstring;
                    break;
                case 'does not contain':
                    $iCondition = \Aurora\Modules\Mail\Enums\FilterCondition::NotContainSubstring;
                    break;
                case 'is':
                    $iCondition = \Aurora\Modules\Mail\Enums\FilterCondition::ContainExactPhrase;
                    break;
            }

            switch ($oCPanelFilter->rules[0]->part) {
                case '$header_from:':
                    $iField = \Aurora\Modules\Mail\Enums\FilterFields::From;
                    break;
                case '$header_to:':
                    $iField = \Aurora\Modules\Mail\Enums\FilterFields::To;
                    break;
                case '$header_subject:':
                    $iField = \Aurora\Modules\Mail\Enums\FilterFields::Subject;
                    break;
            }
            $oNamespace = self::getImapNamespace($oAccount);

            $sNamespace = \str_replace($oNamespace->GetPersonalNamespaceDelimiter(), '', $oNamespace->GetPersonalNamespace());

            $aFolderNameParts = \explode('/', $oCPanelFilter->actions[0]->dest);
            $sFolderFullName = '';
            if (\count($aFolderNameParts) > 1 && $aFolderNameParts[\count($aFolderNameParts) - 1] !== $sNamespace) {
                $sFolderFullName = $sNamespace . $aFolderNameParts[\count($aFolderNameParts) - 1];
            }

            if (isset($iAction) && isset($iCondition) && isset($iField)
                && (!empty($sFolderFullName) || $iAction === \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately || $iAction === \Aurora\Modules\Mail\Enums\FilterAction::Redirect)
            ) {
                $aResult[] = [
                    'Action' => $iAction,
                    'Condition' => $iCondition,
                    'Enable' => (bool) $oCPanelFilter->enabled,
                    'Field' => $iField,
                    'Filter' => $oCPanelFilter->rules[0]->val,
                    'FolderFullName' => $iAction === \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately ? '' : $sFolderFullName,
                    'Filtername' => $oCPanelFilter->filtername,
                    'Email' => $sDestEmail
                ];
            }
        }

        return $aResult;
    }

    /**
     * Converts webmail filters to cPanel filters.
     * @param array $aWebmailFilter
     * @param object $oAccount
     * @return array
     */
    protected static function convertWebmailFIlterToCPanelFIlter($aWebmailFilter, $oAccount)
    {
        $sAction = '';
        $sPart = '';
        $sMatch = '';
        $oNamespace = self::getImapNamespace($oAccount);
        $sNamespace = \str_replace($oNamespace->GetPersonalNamespaceDelimiter(), '', $oNamespace->GetPersonalNamespace());
        $sDest = \str_replace($sNamespace, '/', $aWebmailFilter["FolderFullName"]);

        switch ($aWebmailFilter["Action"]) {
            case \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately:
                $sDest = '/dev/null';
                // no break
            case \Aurora\Modules\Mail\Enums\FilterAction::MoveToFolder:
                $sAction = 'save';
                break;
            case \Aurora\Modules\Mail\Enums\FilterAction::Redirect:
                $sAction = 'deliver';
                $sDest = isset($aWebmailFilter['Email']) ? $aWebmailFilter['Email'] : $sDest;
                break;
        }

        switch ($aWebmailFilter["Condition"]) {
            case \Aurora\Modules\Mail\Enums\FilterCondition::ContainSubstring:
                $sMatch = 'contains';
                break;
            case \Aurora\Modules\Mail\Enums\FilterCondition::NotContainSubstring:
                $sMatch = 'does not contain';
                break;
            case \Aurora\Modules\Mail\Enums\FilterCondition::ContainExactPhrase:
                $sMatch = 'is';
                break;
        }

        switch ($aWebmailFilter["Field"]) {
            case \Aurora\Modules\Mail\Enums\FilterFields::From:
                $sPart = '$header_from:';
                break;
            case \Aurora\Modules\Mail\Enums\FilterFields::To:
                $sPart = '$header_to:';
                break;
            case \Aurora\Modules\Mail\Enums\FilterFields::Subject:
                $sPart = '$header_subject:';
                break;
        }

        return [
            'filtername' => \uniqid(),
            'account' => $oAccount->Email,
            'action1' => $sAction,
            'dest1' => $sDest,
            'part1' => $sPart,
            'match1' => $sMatch,
            'val1' => $aWebmailFilter['Filter'],
            'opt1' => 'or',
        ];
    }

    /**
     * Obtains list of module settings for authenticated user.
     *
     * @param int|null $TenantId Tenant ID
     *
     * @return array
     */
    public function GetSettings($TenantId = null)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
        $oSettings = $this->oModuleSettings;
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User) {
            if (empty($TenantId)) {
                if ($oAuthenticatedUser->isNormalOrTenant()) {
                    $aResult = ['AllowAliases' => $oSettings->AllowAliases];
                    if ($oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin) {
                        $aResult['AllowCreateDeleteAccountOnCpanel'] = $oSettings->AllowCreateDeleteAccountOnCpanel;
                    }
                    return $aResult;
                } elseif ($oAuthenticatedUser->isAdmin()) {
                    return [
                        'CpanelHost' => $oSettings->CpanelHost,
                        'CpanelPort' => $oSettings->CpanelPort,
                        'CpanelUser' => $oSettings->CpanelUser,
                        'CpanelHasPassword' => $oSettings->CpanelPassword !== '',
                        'AllowAliases' => $oSettings->AllowAliases,
                        'AllowCreateDeleteAccountOnCpanel' => $oSettings->AllowCreateDeleteAccountOnCpanel,
                    ];
                }
            } else {
                \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
                $oTenant = \Aurora\System\Api::getTenantById($TenantId);

                if ($oTenant && ($oAuthenticatedUser->isAdmin() || ($oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oAuthenticatedUser->IdTenant === $oTenant->Id))) {
                    return [
                        'CpanelHost' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelHost', ''),
                        'CpanelPort' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelPort', ''),
                        'CpanelUser' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelUser', ''),
                        'CpanelHasPassword' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelPassword', '') !== '',
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Updates module's settings - saves them to config.json file or to user settings in db.
     * @param string $CpanelHost
     * @param string $CpanelPort
     * @param string $CpanelUser
     * @param int $CpanelPassword
     * @param int|null $TenantId
     *
     * @return boolean
     */
    public function UpdateSettings($CpanelHost, $CpanelPort, $CpanelUser, $CpanelPassword, $TenantId = null)
    {
        $result = false;

        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();

        $oSettings = $this->oModuleSettings;
        if (!empty($TenantId)) {

            $oTenant = \Aurora\System\Api::getTenantById($TenantId);

            if ($oTenant && ($oAuthenticatedUser->isAdmin() || $oAuthenticatedUser->IdTenant === $oTenant->Id)) {
                $aValues = [
                    'CpanelHost' => $CpanelHost,
                    'CpanelPort' => $CpanelPort,
                    'CpanelUser' => $CpanelUser
                ];
                if ($CpanelPassword !== '') {
                    $aValues['CpanelPassword'] = $CpanelPassword;
                } else {
                    $aValues['CpanelPassword'] =  $oSettings->GetTenantValue($oTenant->Name, 'CpanelPassword');
                }
                $result = $oSettings->SaveTenantSettings($oTenant->Name, $aValues);
            }
        } else {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);

            $oSettings->CpanelHost = $CpanelHost;
            $oSettings->CpanelPort = $CpanelPort;
            $oSettings->CpanelUser = $CpanelUser;
            if ($CpanelPassword !== '') {
                $oSettings->CpanelPassword = $CpanelPassword;
            }
            $result = $oSettings->Save();
        }

        return $result;
    }

    /**
     * Obtains all aliases for specified user.
     * @param int $UserId User identifier.
     * @return array|boolean
     */
    public function GetAliases($UserId)
    {
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();

        $oUser = \Aurora\Api::getUserById($UserId);
        $bUserFound = $oUser instanceof \Aurora\Modules\Core\Models\User;
        if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::NormalUser && $oUser->Id === $oAuthenticatedUser->Id) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
        } elseif ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
        } else {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
        }
        $oAccount = MailModule::Decorator()->GetAccountByEmail($oUser->PublicId, $oUser->Id);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount && $this->isAccountServerSupported($oAccount)) {
            $aForwardersFromEmail = [];
            $sEmail = $oAccount->Email;
            $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sEmail);

            $aAliases = $this->getManager('Aliases')->getAliasesByUserId($oUser->Id);
            //is Server Supported
            $oServer = MailModule::getInstance()->getServersManager()->getServer($oAccount->ServerId);
            $bSupported = in_array($oServer->IncomingServer, $this->oModuleSettings->SupportedServers);
            if ($bSupported) {
                $aServerDomainsByTenant = MailModule::Decorator()->GetServerDomains($oAccount->ServerId, $oUser->IdTenant);
                //Get forwarders for all supported domains
                $aForwarders = [];
                foreach ($aServerDomainsByTenant as $sServerDomain) {
                    $aForwarders = array_merge($aForwarders, $this->getDomainForwarders($sEmail, $sServerDomain, $oUser->IdTenant));
                }

                //filter forvarders
                $aFilteredForwarders = $this->getAliasesFromForwarders($aForwarders, $oUser->IdTenant);
                foreach ($aFilteredForwarders as $oForwarder) {
                    $sFromEmail = $oForwarder->dest;
                    $sToEmail = $oForwarder->forward;
                    if ($sToEmail === $sEmail) {
                        $aForwardersFromEmail[] = $sFromEmail;
                    }
                }
                $aAliasesEmail = $aAliases->map(function ($oAlias) {
                    return $oAlias->Email;
                })->toArray();

                foreach ($aForwardersFromEmail as $sForwarderFromEmail) {
                    if (!in_array($sForwarderFromEmail, $aAliasesEmail)) {
                        //Check if an alias exists
                        $aAliasesCheck = $this->getManager('Aliases')->getAliases(0, 0, Models\Alias::where('Email', $sForwarderFromEmail));
                        if (empty($aAliasesCheck)) {
                            //create alias if doesn't exists
                            $oAlias = new \Aurora\Modules\CpanelIntegrator\Models\Alias();
                            $oAlias->IdUser = $oUser->Id;
                            $oAlias->IdAccount = $oAccount->Id;
                            $oAlias->Email = $sForwarderFromEmail;
                            $oAlias->ForwardTo = $oAccount->Email;
                            $this->getManager('Aliases')->createAlias($oAlias);
                        }
                    }
                }
                foreach ($aAliases as $oAlias) {
                    if (!in_array($oAlias->Email, $aForwardersFromEmail)) {
                        $this->getManager('Aliases')->deleteAlias($oAlias);
                    }
                }
                $aAliases = $this->getManager('Aliases')->getAliasesByUserId($oUser->Id);
            }

            return [
                'Domain'		=> $sDomain,
                'Aliases'		=> $aForwardersFromEmail,
                'ObjAliases'	=> $aAliases->all()
            ];
        }

        return false;
    }

    /**
     * Creates new alias with specified name and domain.
     * @param int $UserId User identifier.
     * @param string $AliasName Alias name.
     * @param string $AliasDomain Alias domain.
     * @return boolean|int
     */
    public function AddNewAlias($UserId, $AliasName, $AliasDomain)
    {
        $AliasName = strtolower($AliasName); // cPanel creates an alias with a lowercase name.
        $mResult = false;
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();

        $oUser = \Aurora\Api::getUserById($UserId);
        $bUserFound = $oUser instanceof \Aurora\Modules\Core\Models\User;
        if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::NormalUser && $UserId === $oAuthenticatedUser->Id) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
        } elseif ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
        } else {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
        }

        $oMailDecorator = MailModule::Decorator();
        $oAccount = $bUserFound && $oMailDecorator ? $oMailDecorator->GetAccountByEmail($oUser->PublicId, $oUser->Id) : null;
        $aServerDomainsByTenant = $oMailDecorator ? $oMailDecorator->GetServerDomains($oAccount->ServerId, $oUser->IdTenant) : [];
        //AliasDomain must be in the tenant’s domain list
        if (!in_array($AliasDomain, $aServerDomainsByTenant)) {
            throw new \Aurora\System\Exceptions\ApiException(Enums\ErrorCodes::DomainOutsideTenant);
        }
        //Checking if an alias matches an existing account
        $oCpanel = $this->getCpanel($oUser->IdTenant);
        if ($oCpanel) {
            $sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'list_pops', []);
            $aParseResult = self::parseResponse($sCpanelResponse);
            if ($aParseResult && isset($aParseResult['Data'])) {
                $aEmailAccountsOnCPanel = array_map(function ($oAccount) {
                    return $oAccount->email;
                }, $aParseResult['Data']);
                if (in_array($AliasName . '@' . $AliasDomain, $aEmailAccountsOnCPanel)) {
                    throw new \Aurora\System\Exceptions\ApiException(Enums\ErrorCodes::AliaMatchesExistingEmail);
                }
            }
        }
        //Check if an alias exists on CPanel
        $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
        $aResult = $this->getForwarder($AliasDomain, $AliasName . '@' . $AliasDomain, $oUser->IdTenant);
        $bAliasExists = is_array($aResult) && isset($aResult['Email']) && !empty($aResult['Email']);
        if ($bAliasExists) {
            throw new \Aurora\System\Exceptions\ApiException(Enums\ErrorCodes::AliasAlreadyExists);
        }
        //Check if an alias exists
        $aAliases = $this->getManager('Aliases')->getAliases(0, 0, Models\Alias::where('Email', $AliasName . '@' . $AliasDomain));
        if ($aAliases->count() > 0) {
            throw new \Aurora\System\Exceptions\ApiException(Enums\ErrorCodes::AliasAlreadyExists);
        }

        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)) {
            $sEmail = $oAccount->Email;
            $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
            $aServerDomainsByTenant = $oMailDecorator ? $oMailDecorator->GetServerDomains($oAccount->ServerId, $oUser->IdTenant) : [];
            //Get forwarders for all supported domains
            $aForwarders = [];
            foreach ($aServerDomainsByTenant as $sServerDomain) {
                $aForwarders = array_merge($aForwarders, $this->getDomainForwarders($sEmail, $sServerDomain, $oUser->IdTenant));
            }
            $aDomainAliases = $this->getAliasesFromForwarders($aForwarders, $oUser->IdTenant);
            $aUserAliases = [];
            foreach ($aDomainAliases as $oAlias) {
                $sToEmail = $oAlias->forward;
                if ($sToEmail === $sEmail) {
                    $aUserAliases[] = $oAlias;
                }
            }
            $aArgs = [
                'TenantId' => $oUser->IdTenant,
                'DomainAliases' => $aDomainAliases,
                'UserAliases' => $aUserAliases,
                'AliasName' => $AliasName,
                'AliasDomain' => $AliasDomain,
                'ToEmail' => $oAccount->Email,
                'UserId' => $oUser->Id
            ];
            $this->broadcastEvent(
                'CreateAlias::before',
                $aArgs
            );
            $bCreateForwardewResult = $this->createForwarder($AliasDomain, $AliasName . '@' . $AliasDomain, $oAccount->Email, $oUser->IdTenant);
            if ($bCreateForwardewResult) {
                //create Alias
                $oAlias = new \Aurora\Modules\CpanelIntegrator\Models\Alias();
                $oAlias->IdUser = $oUser->Id;
                $oAlias->IdAccount = $oAccount->Id;
                $oAlias->Email = $AliasName . '@' . $AliasDomain;
                $oAlias->ForwardTo = $oAccount->Email;
                $mResult = $this->getManager('Aliases')->createAlias($oAlias);
            }
        }

        return $mResult;
    }

    /**
     * Update existing Alias
     *
     * @param int $UserId
     * @param int $AccountID
     * @param string $FriendlyName
     * @param int $EntityId
     * @return bool
     * @throws \Aurora\System\Exceptions\ApiException
     */
    public function UpdateAlias($UserId, $AccountID, $FriendlyName, $EntityId)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $bResult = false;
        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAccount = MailModule::Decorator()->GetAccount($AccountID);
        if (!$oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount || $oAccount->IdUser !== $oUser->Id) {
            throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
        }
        $oAlias = $this->getManager('Aliases')->getAlias((int) $EntityId);
        if ($oAlias instanceof \Aurora\Modules\CpanelIntegrator\Models\Alias
            && $oAlias->IdUser === $oUser->Id
        ) {
            $oAlias->FriendlyName = $FriendlyName;
            $bResult = $this->getManager('Aliases')->updateAlias($oAlias);
        }

        return $bResult;
    }

    /**
     * Deletes aliases with specified emails.
     * @param int $UserId User identifier
     * @param array $Aliases Aliases emails.
     * @return boolean
     */
    public function DeleteAliases($UserId, $Aliases)
    {
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();

        $oUser = \Aurora\Api::getUserById($UserId);
        $bUserFound = $oUser instanceof \Aurora\Modules\Core\Models\User;

        if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::NormalUser && $oUser->Id === $oAuthenticatedUser->Id) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
        } elseif ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant) {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
        } else {
            \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
        }

        $bResult = false;
        $oAccount = $bUserFound ? MailModule::Decorator()->GetAccountByEmail($oUser->PublicId, $oUser->Id) : null;
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount
                && $this->isAccountServerSupported($oAccount)) {
            foreach ($Aliases as $sAlias) {
                preg_match('/(.+)@(.+)$/', $sAlias, $aMatches);
                $AliasName = isset($aMatches[1]) ? $aMatches[1] : '';
                $AliasDomain = isset($aMatches[2]) ? $aMatches[2] : '';
                $bResult = $this->deleteForwarder($AliasName . '@' . $AliasDomain, $oAccount->Email, $oUser->IdTenant);
                if ($bResult) {
                    $oAlias = $this->getManager('Aliases')->getUserAliasByEmail($oUser->Id, $AliasName . '@' . $AliasDomain);
                    if ($oAlias instanceof \Aurora\Modules\CpanelIntegrator\Models\Alias) {
                        $this->getManager('Aliases')->deleteAlias($oAlias);
                    }
                }
            }
        }

        return $bResult;
    }

    public function UpdateSignature($UserId, $AliasId = null, $UseSignature = null, $Signature = null)
    {
        \Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);

        $bResult = false;
        $oUser = \Aurora\System\Api::getAuthenticatedUser();
        if ($oUser->Id === $UserId) {
            $oAlias = $this->getManager('Aliases')->getAlias((int) $AliasId);
            if ($oAlias instanceof \Aurora\Modules\CpanelIntegrator\Models\Alias && $oAlias->IdUser === $oUser->Id) {
                $oAlias->UseSignature = $UseSignature;
                $oAlias->Signature = $Signature;
                $bResult = $this->getManager('Aliases')->updateAlias($oAlias);
            }
        }

        return $bResult;
    }

    protected function getAliasesFromForwarders($aForwarders, $iTenantId = 0)
    {
        $aResult = [];
        $oCpanel = $this->getCpanel($iTenantId);
        if ($oCpanel) {
            $sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'list_pops', []);
            $aParseResult = self::parseResponse($sCpanelResponse);
            if ($aParseResult && isset($aParseResult['Data'])) {
                $aEmailAccountsOnCPanel = array_map(function ($oAccount) {
                    return $oAccount->email;
                }, $aParseResult['Data']);
                foreach ($aForwarders as &$oForwarder) {
                    //if 'dest' is the email address of the real account, it is not an alias
                    if (!in_array($oForwarder->dest, $aEmailAccountsOnCPanel)) {
                        $aResult[] = $oForwarder;
                    }
                }
            }
        }

        return $aResult;
    }

    protected function isAliasExists($sEmail, $iTenantId = 0)
    {
        //Check if an alias exists on CPanel
        $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sEmail);
        $aResult = $this->getForwarder($sDomain, $sEmail, $iTenantId);
        $bAliasExists = is_array($aResult) && isset($aResult['Email']) && !empty($aResult['Email']);

        return $bAliasExists;
    }

    public function GetScriptForward($AccountID)
    {
        $aResult = [];

        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAccount = \Aurora\Modules\Mail\Module::getInstance()->GetAccount($AccountID);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount && $this->isAccountServerSupported($oAccount)) {
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
            // check if account belongs to authenticated user
            && ($oAccount->IdUser === $oAuthenticatedUser->Id
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)) {
                $oCpanel = null;
                $oUser = \Aurora\Api::getUserById($oAccount->IdUser);
                if ($oUser instanceof \Aurora\Modules\Core\Models\User) {
                    $oCpanel = $this->getCpanel($oUser->IdTenant);
                    $sEmail = $oAccount->Email;
                    $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oUser->PublicId);
                    $sForwardScriptPath = $this->oModuleSettings->ForwardScriptPath ?: \dirname(__FILE__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process_mail.php';
                    if ($oCpanel && $sDomain && $sEmail) {
                        $sCpanelResponse = $this->executeCpanelAction(
                            $oCpanel,
                            'Email',
                            'list_forwarders',
                            [
                                'domain' => $sDomain,
                                'regex' => '^' . $sEmail . '$'
                            ]
                        );
                        $aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
                        if ($aParseResult
                            && isset($aParseResult['Data'])
                            && is_array($aParseResult['Data'])
                            && count($aParseResult['Data']) > 0
                        ) {
                            foreach ($aParseResult['Data'] as $oData) {
                                if ($oData->forward === '|' . $sForwardScriptPath) {
                                    $aResult = [
                                        'Email' => $oData->forward
                                    ];
                                    break;
                                }
                            }
                        }
                    } else {
                        throw new ApiException(Notifications::InvalidInputParameter);
                    }
                }
            }
        }

        return $aResult;
    }

    public function CreateScriptForward($AccountID)
    {
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAccount = \Aurora\Modules\Mail\Module::getInstance()->GetAccount($AccountID);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount && $this->isAccountServerSupported($oAccount)) {
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
            // check if account belongs to authenticated user
            && ($oAccount->IdUser === $oAuthenticatedUser->Id
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)) {
                $oCpanel = null;
                $oUser = \Aurora\Api::getUserById($oAccount->IdUser);
                if ($oUser instanceof \Aurora\Modules\Core\Models\User) {
                    $oCpanel = $this->getCpanel($oUser->IdTenant);
                    $sEmail = $oAccount->Email;
                    $sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oUser->PublicId);
                    $sForwardScriptPath = $this->oModuleSettings->ForwardScriptPath ?: \dirname(__FILE__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process_mail.php';

                    if ($oCpanel && $sDomain && $sEmail) {
                        $sCpanelResponse = $this->executeCpanelAction(
                            $oCpanel,
                            'Email',
                            'add_forwarder',
                            [
                                'domain' => $sDomain,
                                'email' => $sEmail,
                                'fwdopt' => 'pipe',
                                'pipefwd' => $sForwardScriptPath
                            ]
                        );
                        self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
                        return true;
                    } else {
                        throw new ApiException(Notifications::InvalidInputParameter);
                    }
                }
            }
        }

        return false;
    }

    public function RemoveScriptForward($AccountID)
    {
        $bResult = false;
        $oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
        $oAccount = \Aurora\Modules\Mail\Module::getInstance()->GetAccount($AccountID);
        if ($oAccount instanceof \Aurora\Modules\Mail\Models\MailAccount && $this->isAccountServerSupported($oAccount)) {
            if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Models\User
            // check if account belongs to authenticated user
            && ($oAccount->IdUser === $oAuthenticatedUser->Id
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin
            || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)) {
                $sFromEmail = $oAccount->Email;
                $oUser = \Aurora\Api::getUserById($oAccount->IdUser);
                if ($oUser instanceof \Aurora\Modules\Core\Models\User) {
                    $sForwardScriptPath = $this->oModuleSettings->ForwardScriptPath ?: \dirname(__FILE__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process_mail.php';
                    try {
                        $this->deleteForwarder($sFromEmail, '|' . $sForwardScriptPath, $oUser->IdTenant);
                        $bResult = true;
                    } catch (\Exception $oEx) {
                        $bResult = false;
                    }
                }
            }
        }

        return $bResult;
    }
}
