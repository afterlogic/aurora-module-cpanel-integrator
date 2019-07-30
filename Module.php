<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\CpanelIntegrator;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	const UAPI = '3';
	private $oCpanel = null;
	public $oMailModule = null;
	
	protected static $bAllowDeleteFromMailServerIfPossible = false;

	public function init()
	{
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		if ($this->getConfig('AllowCreateDeleteAccountOnCpanel', false) && $oAuthenticatedUser && 
				($oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin))
		{
			// Subscription shouldn't work for Anonymous because Signup subscription will work
			// Subscription shouldn't work for Normal user because CPanel account should be created only for first user account
			$this->subscribeEvent('Mail::CreateAccount::before', array($this, 'onBeforeCreateAccount'));
			$this->subscribeEvent('Mail::BeforeDeleteAccount', array($this, 'onBeforeDeleteAccount'));
		}
		$this->subscribeEvent('MailSignup::Signup::before', [$this, 'onAfterSignup']);
		$this->subscribeEvent('Mail::Account::ToResponseArray', array($this, 'onMailAccountToResponseArray'));
		$this->subscribeEvent('Mail::ChangeAccountPassword', array($this, 'onChangeAccountPassword'));
		$this->subscribeEvent('Mail::UpdateForward::before', array($this, 'onBeforeUpdateForward'));
		$this->subscribeEvent('Mail::GetForward::before', array($this, 'onBeforeGetForward'));
		$this->subscribeEvent('Mail::GetAutoresponder::before', array($this, 'onBeforeGetAutoresponder'));
		$this->subscribeEvent('Mail::UpdateAutoresponder::before', array($this, 'onBeforeUpdateAutoresponder'));
		$this->subscribeEvent('Mail::GetFilters::before', array($this, 'onBeforeGetFilters'));
		$this->subscribeEvent('Mail::UpdateFilters::before', array($this, 'onBeforeUpdateFilters'));
		$this->subscribeEvent('Mail::UpdateQuota', array($this, 'onUpdateQuota'));
		
		$this->subscribeEvent('AdminPanelWebclient::DeleteEntities::before', array($this, 'onBeforeDeleteEntities'));/** @deprecated since version 8.3.7 **/
		$this->subscribeEvent('Core::DeleteTenant::before', array($this, 'onBeforeDeleteEntities'));
		$this->subscribeEvent('Core::DeleteTenants::before', array($this, 'onBeforeDeleteEntities'));
		$this->subscribeEvent('Core::DeleteUser::before', array($this, 'onBeforeDeleteEntities'));
		$this->subscribeEvent('Core::DeleteUsers::before', array($this, 'onBeforeDeleteEntities'));
		$this->subscribeEvent('Mail::DeleteServer::before', array($this, 'onBeforeDeleteEntities'));
		$this->subscribeEvent('MailDomains::DeleteDomains::before', array($this, 'onBeforeDeleteEntities'));
	}

	/**
	 * Connects to cPanel. Uses main credentials if $iTenantId has not been passed, otherwise - tenant credentials to cPanel.
	 * @param int $iTenantId
	 * @return object
	 */
	protected function getCpanel($iTenantId = 0)
	{
		if (!$this->oCpanel)
		{
			$sHost = $this->getConfig('CpanelHost', '');
			$sPort = $this->getConfig('CpanelPort', '');
			$sUser = $this->getConfig('CpanelUser', '');
			$sPassword = $this->getConfig('CpanelPassword', '');
			
			$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
			if ($iTenantId !== 0 && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin)
			{
				$oSettings = $this->GetModuleSettings();
				$oTenant = \Aurora\System\Api::getTenantById($iTenantId);
				$sHost = $oSettings->GetTenantValue($oTenant->Name, 'CpanelHost', '');
				$sPort = $oSettings->GetTenantValue($oTenant->Name, 'CpanelPort', '');
				$sUser = $oSettings->GetTenantValue($oTenant->Name, 'CpanelUser', '');
				$sPassword = $oSettings->GetTenantValue($oTenant->Name, 'CpanelPassword', '');
			}

			$this->oCpanel = new \Gufy\CpanelPhp\Cpanel([
				'host' => "https://" . $sHost . ":" . $sPort,
				'username' => $sUser,
				'auth_type' => 'password',
				'password' => $sPassword,
			]);
		}

		return $this->oCpanel;
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
	 * @param type $mResult Is passed by reference.
	 */
	public function onAfterSignup($aArgs, &$mResult)
	{
		try
		{
			$oCpanel = $this->getCpanel();
		}
		catch(\Exception $oException)
		{}
		if (isset($aArgs['Login']) && isset($aArgs['Password'])
			&& !empty(trim($aArgs['Password'])) && !empty(trim($aArgs['Login']))
			&& $oCpanel)
		{
			$bResult = false;
			$sLogin = trim($aArgs['Login']);
			$sPassword = trim($aArgs['Password']);
			$sFriendlyName = isset($aArgs['Name']) ? trim($aArgs['Name']) : '';
			$bSignMe = isset($aArgs['SignMe']) ? (bool) $aArgs['SignMe'] : false;
			$bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
			$iUserId = \Aurora\Modules\Core\Module::Decorator()->CreateUser(0, $sLogin);
			$oUser = \Aurora\System\Api::getUserById((int) $iUserId);
			if ($oUser instanceof \Aurora\Modules\Core\Classes\User)
			{
				$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oUser->PublicId);
				if (!empty($sDomain) && $this->isDomainSupported($sDomain))
				{
					$iQuota = (int) $this->getConfig('UserDefaultQuotaMB', 1);
					try
					{
						$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'add_pop',
							[
								'email' => $sLogin,
								'password' => $sPassword,
								'quota'	=> $iQuota,
								'domain' => $sDomain
							]
						);
						$aParseResult = self::parseResponse($sCpanelResponse, false);
					}
					catch(\Exception $oException)
					{}
					if (is_array($aParseResult) && isset($aParseResult['Data']) && !empty($aParseResult['Data']))
					{
						try
						{
							$bPrevState = \Aurora\System\Api::skipCheckUserRole(true);
							$oAccount = \Aurora\Modules\Mail\Module::Decorator()->CreateAccount($oUser->EntityId, $sFriendlyName, $sLogin, $sLogin, $sPassword);
							\Aurora\System\Api::skipCheckUserRole($bPrevState);
							if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account)
							{
								$bResult = true;
								$iTime = $bSignMe ? 0 : time();
								$sAuthToken = \Aurora\System\Api::UserSession()->Set(
									[
										'token'		=> 'auth',
										'sign-me'		=> $bSignMe,
										'id'			=> $oAccount->IdUser,
										'account'		=> $oAccount->EntityId,
										'account_type'	=> $oAccount->getName()
									], $iTime);
								$mResult = ['AuthToken' => $sAuthToken];
							}
						}
						catch (\Exception $oException)
						{
							if ($oException instanceof \Aurora\Modules\Mail\Exceptions\Exception &&
								$oException->getCode() === \Aurora\Modules\Mail\Enums\ErrorCodes::CannotLoginCredentialsIncorrect)
							{
								\Aurora\Modules\Core\Module::Decorator()->DeleteUser($oUser->EntityId);
							}
							throw $oException;
						}
					}
					else if (is_array($aParseResult) && isset($aParseResult['Error']))
					{
						//If Account wasn't created - delete user
						\Aurora\Modules\Core\Module::Decorator()->DeleteUser($oUser->EntityId);
						throw new \Exception($aParseResult['Error']);
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
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User && $sAccountEmail === $oUser->PublicId)
		{
			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sAccountEmail);
			if (!empty($sDomain) && $this->isDomainSupported($sDomain))
			{
				$aTenantQuota = \Aurora\Modules\Mail\Module::Decorator()->GetEntitySpaceLimits('Tenant', $oUser->EntityId, $oUser->IdTenant);
				$iQuota = $aTenantQuota ? $aTenantQuota['UserSpaceLimitMb'] : (int) $this->getConfig('UserDefaultQuotaMB', 1);
				$oCpanel = $this->getCpanel($oUser->IdTenant);
				$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'add_pop',
					[
						'email' => $aArgs['IncomingLogin'],
						'password' => $aArgs['IncomingPassword'],
						'quota' => $iQuota,
						'domain' => $sDomain
					]
				);
				$aResult = self::parseResponse($sCpanelResponse, false);
				if ($aResult['Status'] === false && isset($aResult['Error']) && !empty($aResult['Error']) && strrpos(strtolower($aResult['Error']), 'exists') === false)
				{
					throw new \Exception($aResult['Error']);
				}
			}
		}
	}
	
	/**
	 * Sets flag that allows to delete mail account on cPanel.
	 * @param array $aArgs
	 * @param mixed $mResult
	 */
	public function onBeforeDeleteEntities($aArgs, &$mResult)
	{
		self::$bAllowDeleteFromMailServerIfPossible = isset($aArgs['DeletionConfirmedByAdmin']) && $aArgs['DeletionConfirmedByAdmin'] === true;
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
		if ($oUser instanceof \Aurora\Modules\Core\Classes\User
				&& $oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount)
				&& $oAccount->Email === $oUser->PublicId
			)
		{
			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
			if (!empty($sDomain))
			{
				$aAliases = self::Decorator()->GetAliases($oUser->EntityId);
				if (isset($aAliases['Aliases']) && is_array($aAliases['Aliases']) && count($aAliases['Aliases']) > 0)
				{
					self::Decorator()->DeleteAliases($oUser->EntityId, $aAliases['Aliases']);
				}
				
				$aForwardArgs = [
					'AccountID' => $oAccount->EntityId,
					'Enable' => false,
					'Email' => '',
				];
				$mForwardResult = false;
				$this->onBeforeUpdateForward($aForwardArgs, $mForwardResult);
				
				$this->deleteAutoresponder($oAccount->Email);
				
				$oSettings = $this->GetModuleSettings();
				if ($oSettings->GetValue('AllowCreateDeleteAccountOnCpanel', false) && self::$bAllowDeleteFromMailServerIfPossible)
				{
					$oCpanel = $this->getCpanel($oUser->IdTenant);
					$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'delete_pop',
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
		if ($this->isDomainSupported($sDomain))
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'edit_pop_quota',
				[
					'email' => $sLogin,
					'domain' => $sDomain,
					'quota' => $iQuota
				]
			);
			$mResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
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

		if ($oAccount && $this->isAccountServerSupported($oAccount))
		{
			if (!isset($mResult['Extend']) || !is_array($mResult['Extend']))
			{
				$mResult['Extend'] = [];
			}
			$mResult['Extend']['AllowChangePasswordOnMailServer'] = true;
			
			$oMailModule = \Aurora\System\Api::GetModule('Mail');
			if ($oMailModule)
			{
				$mResult['AllowFilters'] = $oMailModule->getConfig('AllowFilters', '');
				$mResult['AllowForward'] = $oMailModule->getConfig('AllowForward', '');
				$mResult['AllowAutoresponder'] = $oMailModule->getConfig('AllowAutoresponder', '');
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
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount)
				&& $oAccount->getPassword() === $aArguments['CurrentPassword'])
		{
			$bPasswordChanged = $this->changePassword($oAccount, $aArguments['NewPassword']);
			$bBreakSubscriptions = true; // break if Cpanel plugin tries to change password in this account. 
		}
		
		if (is_array($mResult))
		{
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
				|| $aArgs['Enable'] && (empty(trim($aArgs['Email'])) || !filter_var(trim($aArgs['Email']), FILTER_VALIDATE_EMAIL)))
		{
			throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
		}

		$oCpanel = $this->getCpanel();
		$sToEmail = trim($aArgs['Email']);
		
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			if ($oCpanel
					&& $oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User
					// check if account belongs to authenticated user
					&& ($oAccount->IdUser === $oAuthenticatedUser->EntityId
							|| $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin
							|| $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin))
			{
				$sFromEmail = $oAccount->Email;
				$sFromDomain = \MailSo\Base\Utils::GetDomainFromEmail($sFromEmail);

				// check if there is forwarder on cPanel
				$aResult = $this->getForwarder($sFromDomain, $sFromEmail);
				$bForwarderExists = is_array($aResult) && isset($aResult['Email']) && !empty($aResult['Email']);
				$sOldToEmail = $bForwarderExists ? $aResult['Email'] : '';
				// "Enable" indicates if forwarder should exist on cPanel
				if ($aArgs['Enable'])
				{
					if ($bForwarderExists)
					{
						$bSameForwarderExists = $bForwarderExists && $sOldToEmail === $sToEmail;
						if ($bSameForwarderExists)
						{
							$mResult = true;
						}
						else if ($this->deleteForwarder($sFromEmail, $sOldToEmail))
						{
							$mResult = $this->createForwarder($sFromDomain, $sFromEmail, $sToEmail);
						}
					}
					else
					{
						$mResult = $this->createForwarder($sFromDomain, $sFromEmail, $sToEmail);
					}
				}
				else
				{
					if ($bForwarderExists)
					{
						$mResult = $this->deleteForwarder($sFromEmail, $sOldToEmail);
					}
					else
					{
						$mResult = true;
					}
				}
			}

			return true; // breaking subscriptions to prevent update in parent module
		}
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

		if (isset($aArgs['AccountID']))
		{
			$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
			$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
			if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $this->isAccountServerSupported($oAccount))
			{
				if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User
					// check if account belongs to authenticated user
					&& $oAccount->IdUser === $oAuthenticatedUser->EntityId)
				{
					$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
					$aResult = $this->getForwarder($sDomain, $oAccount->Email);

					if (is_array($aResult))
					{
						if (isset($aResult['Email']))
						{
							$mResult = [
								'Enable' => true,
								'Email' => $aResult['Email']
							];
						}
						else
						{
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

		if (isset($aArgs['AccountID']))
		{
			$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
			$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
			if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $this->isAccountServerSupported($oAccount))
			{
				if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User
					// check if account belongs to authenticated user
					&& $oAccount->IdUser === $oAuthenticatedUser->EntityId)
				{
					$mAutoresponder = $this->getAutoresponder($oAccount->Email);

					if (is_array($mAutoresponder) && isset($mAutoresponder['Enable']))
					{
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
				|| $aArgs['Enable'] && (empty(trim($aArgs['Subject'])) || empty(trim($aArgs['Message']))))
		{
			throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
		}

		$mResult = false;

		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User
				// check if account belongs to authenticated user
				&& $oAccount->IdUser === $oAuthenticatedUser->EntityId)
			{
				$sSubject = trim($aArgs['Subject']);
				$sMessage = trim($aArgs['Message']);
				$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
				$mResult = $this->updateAutoresponder($sDomain, $oAccount->Email, $sSubject, $sMessage, $aArgs['Enable']);
			}

			return true; // breaking subscriptions to prevent update in parent module
		}
	}

	/**
	 * Obtains filters for specified account.
	 * @param array $aArgs
	 * @param mixed $mResult
	 * @return boolean
	 */
	public function onBeforeGetFilters($aArgs, &$mResult)
	{
		if (!isset($aArgs['AccountID']))
		{
			throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
		}

		$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			$mResult = [];
			
			$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
			$oCpanel = $this->getCpanel();
			if ($oCpanel
				&& $oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User
				// check if account belongs to authenticated user
				&& $oAccount->IdUser === $oAuthenticatedUser->EntityId)
			{
				$mResult = $this->getFilters($oAccount);
			}

			return true; // breaking subscriptions to prevent update in parent module
		}
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
		if (!isset($aArgs['AccountID']) || !isset($aArgs['Filters']) || !is_array($aArgs['Filters']))
		{
			throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Notifications::InvalidInputParameter);
		}

		$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			$mResult = false;
			
			$oCpanel = $this->getCpanel();
			$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
			if ($oCpanel
				&& $oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User
				// check if account belongs to authenticated user
				&& $oAccount->IdUser === $oAuthenticatedUser->EntityId)
			{
				if ($this->removeSupportedFilters($oAccount))
				{
					if (count($aArgs['Filters']) === 0)
					{
						$mResult = true;
					}
					else
					{
						foreach ($aArgs['Filters'] as $aWebmailFilter)
						{
							$aFilterProperty = self::convertWebmailFIlterToCPanelFIlter($aWebmailFilter, $oAccount);
							//create filter
							$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'store_filter',
								$aFilterProperty
							);
							$aCreationResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
							//disable filter if needed
							if (!$aCreationResult['Status'])
							{
								$mResult = false;
								break;
							}
							if (!$aWebmailFilter['Enable'])
							{
								$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'disable_filter',
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
	}

	/**
	 * Checks if the CpanelIntegrator module can work with the specified domain.
	 * @param string $sDomain
	 * @return bool
	 */
	protected function isDomainSupported($sDomain)
	{
		$bSupported = in_array('*', $this->getConfig('SupportedServers', array()));

		if (!$bSupported)
		{
			$aGetMailServerResult = \Aurora\System\Api::GetModuleDecorator('Mail')->GetMailServerByDomain($sDomain, /*AllowWildcardDomain*/true);
			if (!empty($aGetMailServerResult) && isset($aGetMailServerResult['Server']) && $aGetMailServerResult['Server'] instanceof \Aurora\Modules\Mail\Classes\Server)
			{
				$bSupported = in_array($aGetMailServerResult['Server']->IncomingServer, $this->getConfig('SupportedServers'));
			}
		}
		
		return $bSupported;
	}

	/**
	 * Checks if the CpanelIntegrator module can work with the server of the specified account.
	 * @param \Aurora\Modules\Mail\Classes\Account $oAccount
	 * @return bool
	 */
	protected function isAccountServerSupported($oAccount)
	{
		$bSupported = in_array('*', $this->getConfig('SupportedServers', array()));

		if (!$bSupported)
		{
			$oServer = $oAccount->getServer();
			if ($oServer instanceof \Aurora\Modules\Mail\Classes\Server)
			{
				$bSupported = in_array($oServer->IncomingServer, $this->getConfig('SupportedServers'));
			}
		}
		
		return $bSupported;
	}

	/**
	 * Tries to change password for account.
	 * @param \Aurora\Modules\Mail\Classes\Account $oAccount
	 * @param string $sPassword
	 * @return boolean
	 * @throws \Aurora\System\Exceptions\ApiException
	 */
	protected function changePassword($oAccount, $sPassword)
	{
		$bResult = false;
		
		if (0 < strlen($oAccount->IncomingPassword) && $oAccount->IncomingPassword !== $sPassword )
		{
			$cpanel_host = $this->getConfig('CpanelHost', '');
			$cpanel_user = $this->getConfig('CpanelUser', '');
			$cpanel_pass = $this->getConfig('CpanelPassword', '');
			$cpanel_user0 = null;

			$email_user = urlencode($oAccount->Email);
			$email_pass = urlencode($sPassword);
			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);

			if ($cpanel_user == 'root')
			{
				$query = 'https://' . $cpanel_host . ':2087/json-api/listaccts?api.version=1&searchtype=domain&search=' . $sDomain;

				$curl = curl_init();
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
				curl_setopt($curl, CURLOPT_HEADER,0);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
				$header[0] = 'Authorization: Basic ' . base64_encode($cpanel_user . ':' . $cpanel_pass) . "\n\r";
				curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
				curl_setopt($curl, CURLOPT_URL, $query);
				$result = curl_exec($curl);
				if ($result == false)
				{
					\Aurora\System\Api::Log('curl_exec threw error "' . curl_error($curl) . '" for ' . $query);
					curl_close($curl);
					throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
				}
				else
				{
					curl_close($curl);
					\Aurora\System\Api::Log('..:: QUERY0 ::.. ' . $query);
					$json_res = json_decode($result,true);
					\Aurora\System\Api::Log('..:: RESULT0 ::.. ' . $result);
					if (isset($json_res['data']['acct'][0]['user']))
					{
						$cpanel_user0 = $json_res['data']['acct'][0]['user'];
						\Aurora\System\Api::Log('..:: USER ::.. ' . $cpanel_user0);
					}
				}
				$query = 'https://' . $cpanel_host . ':2087/json-api/cpanel?cpanel_jsonapi_user=' . $cpanel_user0 . '&cpanel_jsonapi_module=Email&cpanel_jsonapi_func=passwdpop&cpanel_jsonapi_apiversion=2&email=' . $email_user . '&password=' . $email_pass . '&domain=' . $sDomain;
			}
			else
			{
				$query = 'https://' . $cpanel_host . ':2083/execute/Email/passwd_pop?email=' . $email_user . '&password=' . $email_pass . '&domain=' . $sDomain;
			}

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
			curl_setopt($curl, CURLOPT_HEADER,0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
			$header[0] = 'Authorization: Basic ' . base64_encode($cpanel_user . ':' . $cpanel_pass) . "\n\r";
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_URL, $query);
			$result = curl_exec($curl);
			if ($result === false)
			{
				\Aurora\System\Api::Log('curl_exec threw error "' . curl_error($curl) . '" for ' . $query);
				curl_close($curl);
				throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
			}
			else
			{
				curl_close($curl);
				\Aurora\System\Api::Log('..:: QUERY ::.. ' . $query);
				$json_res = json_decode($result,true);
				\Aurora\System\Api::Log('..:: RESULT ::.. ' . $result);
				if ((isset($json_res['errors']))&&($json_res['errors']!==null))
				{
					$sErrorText = is_string($json_res['errors']) ? $json_res['errors'] : (is_array($json_res['errors']) && isset($json_res['errors'][0]) ? $json_res['errors'][0] : '');
					throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError, null, $sErrorText);
				}
				else
				{
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
	protected function getForwarder($sDomain, $sEmail)
	{
		$aResult = [];

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $sDomain && $sEmail)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'list_forwarders',
				[
					'domain' => $sDomain,
					'regex' => $sEmail
				]
			);
			$aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured

			if ($aParseResult
				&& isset($aParseResult['Data'])
				&& is_array($aParseResult['Data'])
				&& isset($aParseResult['Data'][0])
			)
			{
				$aResult = [
					'Email' => $aParseResult['Data'][0]->forward
				];
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
		
		if ($oCpanel && $sAddress && $sForwarder)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'delete_forwarder',
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
		
		if ($oCpanel && $sDomain && $sEmail && $sForwardEmail)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'add_forwarder',
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
		if ($oCpanel && $sDomain && $sEmail)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'list_forwarders',
				[
					'domain' => $sDomain
				]
			);
			$aResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
			if (is_array($aResult) && isset($aResult['Data']))
			{
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
	protected function getAutoresponder($sEmail)
	{
		$mResult = false;

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $sEmail)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'get_auto_responder',
				[
					'email' => $sEmail
				]
			);
			$aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
			if ($aParseResult
				&& isset($aParseResult['Data'])
				&& isset($aParseResult['Data']->subject)
			)
			{
				if ($aParseResult['Data']->stop !== null && $aParseResult['Data']->stop < time())
				{
					$bEnable = false;
				}
				else
				{
					$bEnable = true;
				}
				$mResult = [
					'Subject' => $aParseResult['Data']->subject,
					'Message' => $aParseResult['Data']->body,
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
	protected function updateAutoresponder($sDomain, $sEmail, $sSubject, $sMessage, $bEnable)
	{
		$aResult = [
			'Status' => false
		];

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $sDomain && $sEmail && $sSubject && $sMessage)
		{
			$iStartTime = 0;
			$iStopTime = 0;
			if (!$bEnable)
			{
				$iStopTime = time();
				$iStartTime = $iStopTime - 1;
			}
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'add_auto_responder',
				[
					'email' => $sEmail,
					'from' => '',
					'subject' => $sSubject,
					'body' => $sMessage,
					'domain' => $sDomain,
					'is_html' => 0,
					'interval' => 0,
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
	protected function deleteAutoresponder($sEmail)
	{
		$oCpanel = $this->getCpanel();
		if ($oCpanel && $sEmail)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'delete_auto_responder',
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
	protected function getFilters($oAccount)
	{
		$aFilters = [];

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $oAccount)
		{
			$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'list_filters',
				[
					'account' => $oAccount->Email
				]
			);
			$aParseResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
			if ($aParseResult && isset($aParseResult['Data']))
			{
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
	protected function removeSupportedFilters($oAccount)
	{
		$bResult = false;

		if ($oAccount)
		{
			$aFilters = $this->getFilters($oAccount);
			if (is_array($aFilters))
			{
				if (count($aFilters) === 0)
				{
					$bResult = true;
				}
				else
				{
					$aSuportedFilterNames = array_map(function ($aFilter) {
						return $aFilter['Filtername'];
					}, $aFilters);
					
					$oCpanel = $this->getCpanel();
					if ($oCpanel)
					{
						$bDelResult = true;
						foreach ($aSuportedFilterNames as $sSuportedFilterName)
						{
							$sCpanelResponse = $this->executeCpanelAction($oCpanel, 'Email', 'delete_filter',
								[
									'account' => $oAccount->Email,
									'filtername' => $sSuportedFilterName
								]
							);
							$aDelResult = self::parseResponse($sCpanelResponse); // throws exception in case if error has occured
							if (!$aDelResult['Status'])
							{
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
		
		if ($oNamespace === null)
		{
			$oNamespace = \Aurora\System\Api::GetModule('Mail')->getMailManager()->_getImapClient($oAccount)->GetNamespace();
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
		)
		{
			$aResult = [
				'Status' => true,
				'Data' => $oResult->result->data
			];
		}
		else if ($oResult && isset($oResult->error))
		{
			$aResult = [
				'Status' => false,
				'Error' => $oResult->error
			];
		}
		else if ($oResult && isset($oResult->result)
			&& isset($oResult->result->errors) && !empty($oResult->result->errors)
			&& isset($oResult->result->errors[0]))
		{
			$aResult = [
				'Status' => false,
				'Error' => $oResult->result->errors[0]
			];
		}
		else
		{
			$aResult = [
				'Status' => false,
				'Error' => $sResponse
			];
		}

		if ($bAllowException && $aResult['Status'] === false)
		{
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

		foreach ($aCPanelFilters as $oCPanelFilter)
		{
			$iAction = null;
			$iCondition = null;
			$iField = null;

			if ($oCPanelFilter->actions[0]->action === 'save')
			{
				if ($oCPanelFilter->actions[0]->dest === '/dev/null')
				{
					$iAction = \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately;
				}
				else
				{
					$iAction = \Aurora\Modules\Mail\Enums\FilterAction::MoveToFolder;
				}
			}

			switch ($oCPanelFilter->rules[0]->match)
			{
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

			switch ($oCPanelFilter->rules[0]->part)
			{
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
			if (\count($aFolderNameParts) > 1 && $aFolderNameParts[\count($aFolderNameParts) - 1] !== $sNamespace)
			{
				$sFolderFullName = $sNamespace . $aFolderNameParts[\count($aFolderNameParts) - 1];
			}

			if (isset($iAction) && isset($iCondition) && isset($iField)
				&& (!empty($sFolderFullName) || $iAction === \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately)
			)
			{
				$aResult[] = [
					'Action' => $iAction,
					'Condition' => $iCondition,
					'Enable' => (bool) $oCPanelFilter->enabled,
					'Field' => $iField,
					'Filter' => $oCPanelFilter->rules[0]->val,
					'FolderFullName' => $iAction === \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately ? '' : $sFolderFullName,
					'Filtername' => $oCPanelFilter->filtername
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

		switch ($aWebmailFilter["Action"])
		{
			case \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately:
				$sDest = '/dev/null';
			case \Aurora\Modules\Mail\Enums\FilterAction::MoveToFolder:
				$sAction = 'save';
				break;
		}

		switch ($aWebmailFilter["Condition"])
		{
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

		switch ($aWebmailFilter["Field"])
		{
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
	 * @return array
	 */
	public function GetSettings($TenantId = null)
	{
		$oSettings = $this->GetModuleSettings();
		
		if (empty($TenantId))
		{
			$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
			if ($oAuthenticatedUser instanceof \Aurora\Modules\Core\Classes\User && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)
			{
				return [
					'AllowAliases' => $oSettings->GetValue('AllowAliases', false),
					'AllowCreateDeleteAccountOnCpanel' => $oSettings->GetValue('AllowCreateDeleteAccountOnCpanel', false),
				];
			}
		}
		
		if (!empty($TenantId))
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
			$oTenant = \Aurora\System\Api::getTenantById($TenantId);

			if ($oTenant instanceof \Aurora\Modules\Core\Classes\Tenant)
			{
				return [
					'CpanelHost' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelHost', ''),
					'CpanelPort' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelPort', ''),
					'CpanelUser' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelUser', ''),
					'CpanelHasPassword' => $oSettings->GetTenantValue($oTenant->Name, 'CpanelPassword', '') !== '',
				];
			}
		}
		
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
		return [
			'CpanelHost' => $oSettings->GetValue('CpanelHost', ''),
			'CpanelPort' => $oSettings->GetValue('CpanelPort', ''),
			'CpanelUser' => $oSettings->GetValue('CpanelUser', ''),
			'CpanelHasPassword' => $oSettings->GetValue('CpanelPassword', '') !== '',
			'AllowAliases' => $oSettings->GetValue('AllowAliases', false),
			'AllowCreateDeleteAccountOnCpanel' => $oSettings->GetValue('AllowCreateDeleteAccountOnCpanel', false),
		];
	}
	
	/**
	 * Updates module's settings - saves them to config.json file or to user settings in db.
	 * @param int $ContactsPerPage Count of contacts per page.
	 * @return boolean
	 */
	public function UpdateSettings($CpanelHost, $CpanelPort, $CpanelUser, $CpanelPassword, $TenantId = null)
	{
		$oSettings = $this->GetModuleSettings();
		if (!empty($TenantId))
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
			$oTenant = \Aurora\System\Api::getTenantById($TenantId);

			if ($oTenant)
			{
				$oSettings->SetTenantValue($oTenant->Name, 'CpanelHost', $CpanelHost);		
				$oSettings->SetTenantValue($oTenant->Name, 'CpanelPort', $CpanelPort);		
				$oSettings->SetTenantValue($oTenant->Name, 'CpanelUser', $CpanelUser);
				if ($CpanelPassword !== '')
				{
					$oSettings->SetTenantValue($oTenant->Name, 'CpanelPassword', $CpanelPassword);		
				}
				return $oSettings->SaveTenantSettings($oTenant->Name);
			}
		}
		else
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);

			$oSettings->SetValue('CpanelHost', $CpanelHost);
			$oSettings->SetValue('CpanelPort', $CpanelPort);
			$oSettings->SetValue('CpanelUser', $CpanelUser);
			if ($CpanelPassword !== '')
			{
				$oSettings->SetValue('CpanelPassword', $CpanelPassword);
			}
			return $oSettings->Save();
		}
	}

	/**
	 * Obtains all aliases for specified user.
	 * @param int $UserId User identifier.
	 * @return array|boolean
	 */
	public function GetAliases($UserId)
	{
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		
		$oUser = \Aurora\Modules\Core\Module::Decorator()->GetUserUnchecked($UserId);
		$bUserFound = $oUser instanceof \Aurora\Modules\Core\Classes\User;
		if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant)
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
		}
		else
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
		}
		
		$oAccount = \Aurora\System\Api::GetModuleDecorator('Mail')->GetAccountByEmail($oUser->PublicId, $oUser->EntityId);
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			$sEmail = $oAccount->Email;
			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sEmail);
			
			$aForwarders = $this->getDomainForwarders($sEmail, $sDomain, $oUser->IdTenant);
			$aAliases = [];
			foreach ($aForwarders as $oForwarder)
			{
				$sFromEmail = $oForwarder->dest;
				$sToEmail = $oForwarder->forward;
				if ($sToEmail === $sEmail)
				{
					$aAliases[] = $sFromEmail;
				}
			}

			return [
				'Domain' => $sDomain,
				'Aliases' => $aAliases
			];
		}
		
		return false;
	}
	
	/**
	 * Creates new alias with specified name and domain.
	 * @param int $UserId User identifier.
	 * @param string $AliasName Alias name.
	 * @param string $AliasDomain Alias domain.
	 * @return boolean
	 */
	public function AddNewAlias($UserId, $AliasName, $AliasDomain)
	{
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		
		$oUser = \Aurora\Modules\Core\Module::Decorator()->GetUserUnchecked($UserId);
		$bUserFound = $oUser instanceof \Aurora\Modules\Core\Classes\User;
		if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant)
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
		}
		else
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
		}
		
		$oMailDecorator = \Aurora\System\Api::GetModuleDecorator('Mail');
		$oAccount = $bUserFound && $oMailDecorator ? $oMailDecorator->GetAccountByEmail($oUser->PublicId, $oUser->EntityId) : null;
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			$sEmail = $oAccount->Email;
			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
			$aArgs = [
				'TenantId' => $oUser->IdTenant,
				'Forwarders' => $this->getDomainForwarders($sEmail, $sDomain, $oUser->IdTenant),
				'AliasName' => $AliasName,
				'AliasDomain' => $AliasDomain,
				'ToEmail' => $oAccount->Email
			];
			$this->broadcastEvent(
				'CreateAlias::before', 
				$aArgs
			);
			return $this->createForwarder($AliasDomain, $AliasName . '@' . $AliasDomain, $oAccount->Email, $oUser->IdTenant);
		}
		
		return false;
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
		
		$oUser = \Aurora\Modules\Core\Module::Decorator()->GetUserUnchecked($UserId);
		$bUserFound = $oUser instanceof \Aurora\Modules\Core\Classes\User;
		
		if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant)
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
		}
		else
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
		}
		
		$bResult = false;
		$oMailDecorator = \Aurora\System\Api::GetModuleDecorator('Mail');
		$oAccount = $bUserFound && $oMailDecorator ? $oMailDecorator->GetAccountByEmail($oUser->PublicId, $oUser->EntityId) : null;
		if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
				&& $this->isAccountServerSupported($oAccount))
		{
			foreach ($Aliases as $sAlias)
			{
				preg_match('/(.+)@(.+)$/', $sAlias, $aMatches);
				$AliasName = isset($aMatches[1]) ? $aMatches[1] : '';
				$AliasDomain = isset($aMatches[2]) ? $aMatches[2] : '';
				$bResult = $this->deleteForwarder($AliasName . '@' . $AliasDomain, $oAccount->Email, $oUser->IdTenant);
			}
		}
		
		return $bResult;
	}
}
