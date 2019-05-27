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

	public function init()
	{
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		if ($this->getConfig('AllowCreateDeleteAccountOnCpanel', false) && ($oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::SuperAdmin || $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin))
		{
			// Subscription shouldn't work for Anonymous because Signup subscription will work
			// Subscription shouldn't work for Normal user because CPanel account should be created only for first user account
			$this->subscribeEvent('Mail::CreateAccount::before', array($this, 'onBeforeCreateAccount'));
			$this->subscribeEvent('Mail::DeleteAccount::before', array($this, 'onBeforeDeleteAccount'));
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
	}

	public function getCpanel($iTenantId = 0)
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
				'host'		=> "https://" . $sHost . ":" . $sPort,
				'username'	=> $sUser,
				'auth_type'	=> 'password',
				'password'	=> $sPassword,
			]);
		}

		return $this->oCpanel;
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
			$oResult = null;
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
				if (!empty($sDomain))
				{
					$iQuota = (int) $this->getConfig('UserDefaultQuotaMB', 1);
					try
					{
						$sResult = $oCpanel->execute_action(self::UAPI, 'Email', 'add_pop', $oCpanel->getUsername(),
							[
								'email'	=> $sLogin,
								'password'	=> $sPassword,
								'quota'	=> $iQuota,
								'domain'	=> $sDomain
							]
						);
						$oResult = \json_decode($sResult);
					}
					catch(\Exception $oException)
					{}
					if ($oResult && isset($oResult->result) && isset($oResult->result->data) && !empty($oResult->result->data))
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
					else if ($oResult && isset($oResult->result)
						&& isset($oResult->result->errors) && !empty($oResult->result->errors)
						&& isset($oResult->result->errors[0]))
					{
						//If Account wasn't created - delete user
						\Aurora\Modules\Core\Module::Decorator()->DeleteUser($oUser->EntityId);
						throw new \Exception($oResult->result->errors[0]);
					}
				}
			}
			if (!$bResult)
			{	//If Account wasn't created - delete user
				\Aurora\Modules\Core\Module::Decorator()->DeleteUser($oUser->EntityId);
			}
			\Aurora\System\Api::skipCheckUserRole($bPrevState);
		}

		return true; // break subscriptions to prevent account creation in other modules
	}

	public function onBeforeCreateAccount($aArgs, &$mResult)
	{
//		$iUserId = $aArgs['UserId'];
//		$oUser = \Aurora\System\Api::getUserById($iUserId);
//		$sAccountEmail = $aArgs['Email'];
//		if ($oUser instanceof \Aurora\Modules\Core\Classes\User && $sAccountEmail === $oUser->PublicId)
//		{
//			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sAccountEmail);
//			if (!empty($sDomain))
//			{
//				$iQuota = (int) $this->getConfig('UserDefaultQuotaMB', 1);
//				$oCpanel = $this->getCpanel($oUser->IdTenant);
//				$sResult = $oCpanel->execute_action(self::UAPI, 'Email', 'add_pop', $oCpanel->getUsername(),
//					[
//						'email' => $aArgs['IncomingLogin'],
//						'password' => $aArgs['IncomingPassword'],
//						'quota' => $iQuota,
//						'domain' => $sDomain
//					]
//				);
//				$aResult = self::parseResponse($sResult, false);
//				if ($aResult['Status'] === false && strrpos(strtolower($aResult['Error']), 'exists') === false)
//				{
//					throw new \Exception($aResult['Error']);
//				}
//			}
//		}
	}
	
	public function onBeforeDeleteAccount($aArgs, &$mResult)
	{
//		$iAccountId	 = $aArgs['AccountID'];
//		$oAccount = \Aurora\Modules\Mail\Module::Decorator()->GetAccount($iAccountId);
//		$oUser = \Aurora\System\Api::getUserById($oAccount->IdUser);
//		if ($oUser instanceof \Aurora\Modules\Core\Classes\User && $oAccount->Email === $oUser->PublicId)
//		{
//			$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
//			if (!empty($sDomain))
//			{
//				try
//				{
//					$oCpanel = $this->getCpanel($oUser->IdTenant);
//					$oCpanel->execute_action(self::UAPI, 'Email', 'delete_pop', $oCpanel->getUsername(),
//						[
//							'email' => $oAccount->Email,
//							'domain' => $sDomain
//						]
//					);
//				}
//				catch(\Exception $oException)
//				{
//				}
//			}
//		}
	}
	
	/**
	 * Adds to account response array information about if allowed to change the password for this account.
	 * @param array $aArguments
	 * @param mixed $mResult
	 */
	public function onMailAccountToResponseArray($aArguments, &$mResult)
	{
		$oAccount = $aArguments['Account'];

		if ($oAccount && $this->checkCanChangePassword($oAccount))
		{
			if (!isset($mResult['Extend']) || !is_array($mResult['Extend']))
			{
				$mResult['Extend'] = [];
			}
			$mResult['Extend']['AllowChangePasswordOnMailServer'] = true;
		}
	}

	/**
	 * Tries to change password for account if allowed.
	 * @param array $aArguments
	 * @param mixed $mResult
	 */
	public function onChangeAccountPassword($aArguments, &$mResult)
	{
		$bPasswordChanged = false;
		$bBreakSubscriptions = false;
		
		$oAccount = $aArguments['Account'];
		if ($oAccount && $this->checkCanChangePassword($oAccount) && $oAccount->getPassword() === $aArguments['CurrentPassword'])
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

	public function onBeforeUpdateForward($aArgs, &$mResult, &$mSubscriptionResult)
	{
		$mResult = false;

		try
		{
			$oCpanel = $this->getCpanel();
			if (isset($aArgs['AccountID'])
				&& isset($aArgs['Enable'])
				&& isset($aArgs['Email'])
				&& !empty(trim($aArgs['Email']))
				&& filter_var(trim($aArgs['Email']), FILTER_VALIDATE_EMAIL)
				&& $oCpanel)
			{
				$sEmail = trim($aArgs['Email']);
				//check if accountID belongs to authorized user
				$oUser = \Aurora\System\Api::getAuthenticatedUser();
				$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
				if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $oUser
					&& $oAccount->IdUser === $oUser->EntityId)
				{
					$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
					//delete or create Forward depending on the Enable parameter
					if ($aArgs['Enable'])
					{//create forward
						//remove forwarder if  already exists
						$aResult = $this->getForwarder($sDomain, $oAccount->Email);
						if (!empty($aResult) && isset($aResult['Email']))
						{
							$aDeletingResult = $this->deleteForwarder($oAccount->Email, $aResult['Email']);
							if ($aDeletingResult['Status'])
							{
								$aCreationResult = $this->createForwarder($sDomain, $oAccount->Email, $sEmail);
								if ($aCreationResult['Status'])
								{
									$mResult = true;
								}
								elseif (isset($aCreationResult['Error']))
								{
									$mSubscriptionResult = [
										'Error' => [
											'message'	=> $aCreationResult['Error']
										]
									];
								}
							}
							elseif (isset($aDeletingResult['Error']))
							{
								$mSubscriptionResult = [
									'Error' => [
										'message'	=> $aDeletingResult['Error']
									]
								];
							}
						}
						else
						{
							$aCreationResult = $this->createForwarder($sDomain, $oAccount->Email, $sEmail);
							if ($aCreationResult['Status'])
							{
								$mResult = true;
							}
							elseif (isset($aCreationResult['Error']))
							{
								$mSubscriptionResult = [
									'Error' => [
										'message'	=> $aCreationResult['Error']
									]
								];
							}
						}
						
					}
					else
					{//delete forward
						$aDeletingResult = $this->deleteForwarder($oAccount->Email, $sEmail);
						if ($aDeletingResult['Status'])
						{
							$mResult = true;
						}
						elseif (isset($aDeletingResult['Error']))
						{
							$mSubscriptionResult = [
								'Error' => [
									'message' => $aDeletingResult['Error']
								]
							];
						}
					}
				}
			}
		}
		catch(\Exception $oException)
		{}

		return true; // breaking subscriptions to prevent update in parent module
	}

	public function onBeforeGetForward($aArgs, &$mResult, $mSubscriptionResult)
	{
		$mResult = false;

		try
		{
			if (isset($aArgs['AccountID']))
			{
				//check if accountID belongs to authorized user
				$oUser = \Aurora\System\Api::getAuthenticatedUser();
				$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
				if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $oUser
					&& $oAccount->IdUser === $oUser->EntityId)
				{
					$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
					$aResult = $this->getForwarder($sDomain, $oAccount->Email);

					if (!empty($aResult) && isset($aResult['Email']))
					{
						$mResult = [
							'Enable'	=> true,
							'Email'	=> $aResult['Email']
						];
					}
					else if (!empty($aResult) && isset($aResult['Error']))
					{
						$mSubscriptionResult = [
							'Error' => [
								'message' => $aResult['Error']
							]
						];
					}
				}
			}
		}
		catch(\Exception $oException)
		{}

		return true; // breaking subscriptions to prevent update in parent module
	}

	public function onBeforeGetAutoresponder($aArgs, &$mResult, $mSubscriptionResult)
	{
		$mResult = false;

		try
		{
			if (isset($aArgs['AccountID']))
			{
				//check if accountID belongs to authorized user
				$oUser = \Aurora\System\Api::getAuthenticatedUser();
				$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
				if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $oUser
					&& $oAccount->IdUser === $oUser->EntityId)
				{
					$aResult = $this->getAutoresponder($oAccount->Email);

					if (!empty($aResult) && $aResult['Status'])
					{
						$mResult = [
							'Enable'	=> $aResult['Enable'],
							'Subject'	=> $aResult['Subject'],
							'Message'	=> $aResult['Message']
						];
					}
					else if (!empty($aResult) && isset($aResult['Error']))
					{
						$mSubscriptionResult = [
							'Error' => [
								'message' => $aResult['Error']
							]
						];
					}
					else
					{
						$mResult = [
							'Enable' => false
						];
					}
				}
			}
		}
		catch(\Exception $oException)
		{}

		return true; // breaking subscriptions to prevent update in parent module
	}

	public function onBeforeUpdateAutoresponder($aArgs, &$mResult, &$mSubscriptionResult)
	{
		$mResult = false;

		try
		{
			$oCpanel = $this->getCpanel();
			if (isset($aArgs['AccountID'])
				&& isset($aArgs['Enable'])
				&& isset($aArgs['Subject'])
				&& !empty(trim($aArgs['Subject']))
				&& isset($aArgs['Message'])
				&& !empty(trim($aArgs['Message']))
				&& $oCpanel)
			{
				$sSubject = trim($aArgs['Subject']);
				$sMessage = trim($aArgs['Message']);
				//check if accountID belongs to authorized user
				$oUser = \Aurora\System\Api::getAuthenticatedUser();
				$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
				if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $oUser
					&& $oAccount->IdUser === $oUser->EntityId)
				{
					$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($oAccount->Email);
					$aResult = $this->updateAutoresponder($sDomain, $oAccount->Email, $sSubject, $sMessage, $aArgs['Enable']);
					if ($aResult['Status'])
					{
						$mResult = true;
					}
					elseif (isset($aResult['Error']))
					{
						$mSubscriptionResult = [
							'Error' => [
								'message' => $aResult['Error']
							]
						];
					}
				}
			}
		}
		catch(\Exception $oException)
		{}

		return true; // breaking subscriptions to prevent update in parent module
	}

	public function onBeforeGetFilters($aArgs, &$mResult, &$mSubscriptionResult)
	{
		$mResult = [];

		try
		{
			$oCpanel = $this->getCpanel();
			if (isset($aArgs['AccountID']) && $oCpanel)
			{
				//check if accountID belongs to authorized user
				$oUser = \Aurora\System\Api::getAuthenticatedUser();
				$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
				if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $oUser
					&& $oAccount->IdUser === $oUser->EntityId)
				{
					$aResult = $this->getFiltersList($oAccount);
					if ($aResult['Status'])
					{
						$mResult = $aResult['Filters'];
					}
					elseif (isset($aResult['Error']))
					{
						$mSubscriptionResult = [
							'Error' => [
								'message' => $aResult['Error']
							]
						];
					}
				}
			}
		}
		catch(\Exception $oException)
		{}

		return true; // breaking subscriptions to prevent update in parent module
	}

	public function onBeforeUpdateFilters($aArgs, &$mResult, &$mSubscriptionResult)
	{
		$mResult = false;

		try
		{
			$oCpanel = $this->getCpanel();
			if (isset($aArgs['AccountID'])
				&& isset($aArgs['Filters'])
				&& is_array($aArgs['Filters'])
				&& !empty($aArgs['Filters'])
				&& $oCpanel)
			{
				//check if accountID belongs to authorized user
				$oUser = \Aurora\System\Api::getAuthenticatedUser();
				$oAccount = \Aurora\System\Api::GetModule('Mail')->GetAccount($aArgs['AccountID']);
				if ($oAccount instanceof \Aurora\Modules\Mail\Classes\Account
					&& $oUser
					&& $oAccount->IdUser === $oUser->EntityId)
				{
					$aResult = $this->removeSupportedFilters($oAccount);
					if ($aResult['Status'])
					{
						foreach ($aArgs['Filters'] as $aWebmailFilter)
						{
							$aFilterProperty = self::convertWebmailFIlterToCPanelFIlter($aWebmailFilter, $oAccount);
							//create filter
							$sCreationResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'store_filter', $oCpanel->getUsername(),
								$aFilterProperty
							);
							$aCreationResult = self::parseResponse($sCreationResponse);
							if (isset($aCreationResult['Error']))
							{
								$mSubscriptionResult = [
									'Error' => [
										'message'	=> $aCreationResult['Error']
									]
								];
							}
							//disable filter if needed
							if (!$aWebmailFilter['Enable'])
							{
								$sDisableResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'disable_filter', $oCpanel->getUsername(),
									[
										'account'	=> $oAccount->Email,
										'filtername'	=> $aFilterProperty['filtername']
									]
								);
								$aDisableResult = self::parseResponse($sDisableResponse);
								if (isset($aDisableResult['Error']))
								{
									$mSubscriptionResult = [
										'Error' => [
											'message'	=> $aDisableResult['Error']
										]
									];
								}
							}
							$mResult = true;
						}
					}
					elseif (isset($aResult['Error']))
					{
						$mSubscriptionResult = [
							'Error' => [
								'message' => $aResult['Error']
							]
						];
					}
				}
			}
		}
		catch(\Exception $oException)
		{}

		return true; // breaking subscriptions to prevent update in parent module
	}

	/**
	 * Checks if allowed to change password for account.
	 * @param \Aurora\Modules\Mail\Classes\Account $oAccount
	 * @return bool
	 */
	protected function checkCanChangePassword($oAccount)
	{
		$bFound = in_array('*', $this->getConfig('SupportedServers', array()));

		if (!$bFound)
		{
			$oServer = $oAccount->getServer();

			if ($oServer && in_array($oServer->IncomingServer, $this->getConfig('SupportedServers')))
			{
				$bFound = true;
			}
		}
		return $bFound;
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
			$cpanel_user = $this->getConfig('CpanelUser','');
			$cpanel_pass = $this->getConfig('CpanelPassword','');
			$cpanel_user0 = null;

			$email_user = urlencode($oAccount->Email);
			$email_pass = urlencode($sPassword);
			list($email_login, $email_domain) = explode('@', $oAccount->Email);

			if ($cpanel_user == "root")
			{
				$query = "https://".$cpanel_host.":2087/json-api/listaccts?api.version=1&searchtype=domain&search=".$email_domain;

				$curl = curl_init();
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
				curl_setopt($curl, CURLOPT_HEADER,0);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
				$header[0] = "Authorization: Basic " . base64_encode($cpanel_user.":".$cpanel_pass) . "\n\r";
				curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
				curl_setopt($curl, CURLOPT_URL, $query);
				$result = curl_exec($curl);
				if ($result == false) {
					\Aurora\System\Api::Log("curl_exec threw error \"" . curl_error($curl) . "\" for $query");
					curl_close($curl);
					throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
				} else {
					curl_close($curl);
					\Aurora\System\Api::Log("..:: QUERY0 ::.. ".$query);
					$json_res = json_decode($result,true);
					\Aurora\System\Api::Log("..:: RESULT0 ::.. ".$result);
					if(isset($json_res['data']['acct'][0]['user'])) {
						$cpanel_user0 = $json_res['data']['acct'][0]['user'];
						\Aurora\System\Api::Log("..:: USER ::.. ".$cpanel_user0);
					}
				}
				$query = "https://".$cpanel_host.":2087/json-api/cpanel?cpanel_jsonapi_user=".$cpanel_user0."&cpanel_jsonapi_module=Email&cpanel_jsonapi_func=passwdpop&cpanel_jsonapi_apiversion=2&email=".$email_user."&password=".$email_pass."&domain=".$email_domain;
			}
			else
			{
				$query = "https://".$cpanel_host.":2083/execute/Email/passwd_pop?email=".$email_user."&password=".$email_pass."&domain=".$email_domain;
			}

			$curl = curl_init();
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER,0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,0);
			curl_setopt($curl, CURLOPT_HEADER,0);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
			$header[0] = "Authorization: Basic " . base64_encode($cpanel_user.":".$cpanel_pass) . "\n\r";
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
			curl_setopt($curl, CURLOPT_URL, $query);
			$result = curl_exec($curl);
			if ($result == false) {
				\Aurora\System\Api::Log("curl_exec threw error \"" . curl_error($curl) . "\" for $query");
				curl_close($curl);
				throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
			} else {
				curl_close($curl);
				\Aurora\System\Api::Log("..:: QUERY ::.. ".$query);
				$json_res = json_decode($result,true);
				\Aurora\System\Api::Log("..:: RESULT ::.. ".$result);
				if ((isset($json_res["errors"]))&&($json_res["errors"]!==null))
				{
					throw new \Aurora\System\Exceptions\ApiException(\Aurora\System\Exceptions\Errs::UserManager_AccountNewPasswordUpdateError);
				} else {
					$bResult = true;
				}
			}
		}
		return $bResult;
	}

	protected function getMailModule()
	{
		if (!$this->oMailModule)
		{
			$this->oMailModule = \Aurora\System\Api::GetModule('Mail');
		}

		return $this->oMailModule;
	}

	protected function getForwarder($sDomain, $sEmail)
	{
		$aResult = [];

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $sDomain && $sEmail)
		{
			$sResult = $oCpanel->execute_action(self::UAPI, 'Email', 'list_forwarders', $oCpanel->getUsername(),
				[
					'domain'	=> $sDomain,
					'regex'	=> $sEmail
				]
			);
			$oResult = \json_decode($sResult);

			if ($oResult
				&& isset($oResult->result)
				&& isset($oResult->result->data)
				&& is_array($oResult->result->data)
				&& isset($oResult->result->data[0])
			)
			{
				$aResult = [
					'Email' => $oResult->result->data[0]->forward
				];
			}
			else if ($oResult && isset($oResult->error))
			{
				$aResult = [
					'Error' => [
						'message' => $oResult->error
					]
				];
			}
			else if ($oResult && isset($oResult->result)
				&& isset($oResult->result->errors) && !empty($oResult->result->errors)
				&& isset($oResult->result->errors[0]))
			{
				$aResult = [
					'Error' => [
						'message' => $oResult->result->errors[0]
					]
				];
			}
		}

		return $aResult;
	}

	protected function deleteForwarder($sAddress, $sForwarder, $iTenantId = 0)
	{
		$aResult = [
			'Status' => false
		];

		$oCpanel = $this->getCpanel($iTenantId);
		if ($oCpanel && $sAddress && $sForwarder)
		{
			$sResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'delete_forwarder', $oCpanel->getUsername(),
				[
					'address'	=> $sAddress,
					'forwarder'   => $sForwarder
				]
			);
			$aResult = self::parseResponse($sResponse);
		}

		return $aResult;
	}

	protected function createForwarder($sDomain, $sEmail, $sForwardEmail, $iTenantId = 0)
	{
		$aResult = [
			'Status' => false
		];

		$oCpanel = $this->getCpanel($iTenantId);
		if ($oCpanel && $sDomain && $sEmail && $sForwardEmail)
		{
			$sResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'add_forwarder', $oCpanel->getUsername(),
				[
					'domain'	=> $sDomain,
					'email'	=> $sEmail,
					'fwdopt'	=> 'fwd',
					'fwdemail'   => $sForwardEmail
				]
			);
			$aResult = self::parseResponse($sResponse);
		}

		return $aResult;
	}

	protected function getAutoresponder($sEmail)
	{
		$aResult = [
			'Status' => false
		];

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $sEmail)
		{
			$sResult = $oCpanel->execute_action(self::UAPI, 'Email', 'get_auto_responder', $oCpanel->getUsername(),
				[
					'email' => $sEmail
				]
			);
			$oResult = \json_decode($sResult);

			if ($oResult
				&& isset($oResult->result)
				&& isset($oResult->result->data)
				&& is_object($oResult->result->data)
				&& isset($oResult->result->data->subject)
			)
			{
				if ($oResult->result->data->stop !== null && $oResult->result->data->stop < time())
				{
					$bEnable = false;
				}
				else
				{
					$bEnable = true;
				}
				$aResult = [
					'Status'	=> true,
					'Subject'	=> $oResult->result->data->subject,
					'Message'	=> $oResult->result->data->body,
					'Enable'	=> $bEnable
				];
			}
			else if ($oResult && isset($oResult->error))
			{
				$aResult = [
					'Status'	=> false,
					'Error'	=> [
						'message'	=> $oResult->error
					]
				];
			}
			else if ($oResult && isset($oResult->result)
				&& isset($oResult->result->errors) && !empty($oResult->result->errors)
				&& isset($oResult->result->errors[0]))
			{
				$aResult = [
					'Status'	=> false,
					'Error'	=> [
						'message'	=> $oResult->result->errors[0]
					]
				];
			}
		}

		return $aResult;
	}

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
			$sResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'add_auto_responder', $oCpanel->getUsername(),
				[
					'email'	=> $sEmail,
					'from'		=> '',
					'subject'	=> $sSubject,
					'body'	=> $sMessage,
					'domain'	=> $sDomain,
					'is_html'	=> 0,
					'interval'	=> 0,
					'start'		=> $iStartTime,
					'stop'		=> $iStopTime
				]
			);
			$aResult = self::parseResponse($sResponse);
		}

		return $aResult;
	}

	protected function getFiltersList($oAccount)
	{
		$aResult = [
			'Status' => false
		];

		$oCpanel = $this->getCpanel();
		if ($oCpanel && $oAccount)
		{
			$sResult = $oCpanel->execute_action(self::UAPI, 'Email', 'list_filters', $oCpanel->getUsername(),
				[
					'account' => $oAccount->Email
				]
			);
			$oResult = \json_decode($sResult);

			if ($oResult
				&& isset($oResult->result)
				&& isset($oResult->result->data)
				&& is_array($oResult->result->data)
			)
			{
				$aResult = [
					'Status'	=> true,
					'Filters'	=> self::convertCPanelFIltersToWebmailFIlters($oResult->result->data, $oAccount)
				];
			}
			else if ($oResult && isset($oResult->error))
			{
				$aResult = [
					'Status'	=> false,
					'Error'	=> [
						'message'	=> $oResult->error
					]
				];
			}
			else if ($oResult && isset($oResult->result)
				&& isset($oResult->result->errors) && !empty($oResult->result->errors)
				&& isset($oResult->result->errors[0]))
			{
				$aResult = [
					'Status'	=> false,
					'Error'	=> [
						'message'	=> $oResult->result->errors[0]
					]
				];
			}
		}

		return $aResult;
	}

	protected function removeSupportedFilters($oAccount)
	{
		$aResult = [
			'Status' => false
		];

		if ($oAccount)
		{
			$aResult = $this->getFiltersList($oAccount);
			if ($aResult['Status'])
			{
				$aSuportedFilterNames = array_map(function ($aFilter) {
					return $aFilter['Filtername'];
				}, $aResult['Filters']);
				$oCpanel = $this->getCpanel();
				if ($oCpanel)
				{
					$bDelResult = true;
					foreach ($aSuportedFilterNames as $sSuportedFilterName)
					{
						$sResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'delete_filter', $oCpanel->getUsername(),
							[
								'account'		=> $oAccount->Email,
								'filtername'		=> $sSuportedFilterName
							]
						);
						$aDelResult = self::parseResponse($sResponse);
						if (!$aDelResult['Status'])
						{
							$bDelResult = false;
							if (isset($aDelResult['Error']))
							{
								$aResult = [
									'Status'	=> false,
									'Error'	=> $aDelResult['Error']
								];
							}
							break;
						}
					}
					if ($bDelResult)
					{
						$aResult = [
							'Status' => true
						];
					}
				}
				
			}
		}

		return $aResult;
	}
	public static function getImapNamespace($oAccount)
	{
		static $oNamespace = null;
		if ($oNamespace === null)
		{
			$oNamespace = \Aurora\System\Api::GetModule('Mail')->getMailManager()->_getImapClient($oAccount)->GetNamespace();
		}

		return $oNamespace;
	}

	public static function parseResponse($sResponse, $bAllowException = true)
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
				'Status' => true
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

		if ($bAllowException && $aResult['Status'] === false)
		{
			throw new \Exception($aResult['Error']);
		}
		
		return $aResult;
	}

	public static function convertCPanelFIltersToWebmailFIlters($aCPanelFilters, $oAccount)
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
			if (\count($aFolderNameParts)  > 1 && $aFolderNameParts[\count($aFolderNameParts) - 1] !== $sNamespace)
			{
				$sFolderFullName = $sNamespace . $aFolderNameParts[\count($aFolderNameParts) - 1];
			}

			if (isset($iAction) && isset($iCondition) && isset($iField)
				&& (!empty($sFolderFullName) || $iAction === \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately)
			)
			{
				$aResult[] = [
					'Action'			=> $iAction,
					'Condition'			=> $iCondition,
					'Enable'			=> (bool) $oCPanelFilter->enabled,
					'Field'				=> $iField,
					'Filter'			=> $oCPanelFilter->rules[0]->val,
					'FolderFullName'	=> $iAction === \Aurora\Modules\Mail\Enums\FilterAction::DeleteFromServerImmediately ? '' : $sFolderFullName,
					'Filtername'		=> $oCPanelFilter->filtername
				];
			}
		}

		return $aResult;
	}

	public static function convertWebmailFIlterToCPanelFIlter($aWebmailFilter, $oAccount)
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
			'filtername'		=> \uniqid(),
			'account'		=> $oAccount->Email,
			'action1'		=> $sAction,
			'dest1'			=> $sDest,
			'part1'			=> $sPart,
			'match1'		=> $sMatch,
			'val1'			=> $aWebmailFilter['Filter'],
			'opt1'			=> 'or',
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
			if ($oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin)
			{
				return [
					'AllowAliases' => $oSettings->GetValue('AllowAliases', false),
				];
			}
		}
		
		if (!empty($TenantId))
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
			$oTenant = \Aurora\System\Api::getTenantById($TenantId);

			if ($oTenant)
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
	
	public function onUpdateQuota($aArgs, &$mResult)
	{
		$mResult = $this->setMailQuota($aArgs['TenantId'], $aArgs['Email'], $aArgs['QuotaMb']);
	}
	
	protected function setMailQuota($iTenantId, $sEmail, $iQuota)
	{
		$oCpanel = $this->getCpanel($iTenantId);
		$sLogin = \MailSo\Base\Utils::GetAccountNameFromEmail($sEmail);
		$sDomain = \MailSo\Base\Utils::GetDomainFromEmail($sEmail);
		$sResponse = $oCpanel->execute_action(self::UAPI, 'Email', 'edit_pop_quota', $oCpanel->getUsername(),
			[
				'email' => $sLogin,
				'domain' => $sDomain,
				'quota' => $iQuota
			]
		);
		$aResult = self::parseResponse($sResponse);
		return $aResult;
	}
	
	protected function getDomainForwarders($sEmail, $sDomain, $iTenantId = 0)
	{
		$oCpanel = $this->getCpanel($iTenantId);
		if ($oCpanel && $sDomain && $sEmail)
		{
			$sResult = $oCpanel->execute_action(self::UAPI, 'Email', 'list_forwarders', $oCpanel->getUsername(),
				[
					'domain' => $sDomain
				]
			);
			$oResult = \json_decode($sResult);
			if ($oResult
				&& isset($oResult->result)
				&& isset($oResult->result->data)
				&& is_array($oResult->result->data)
			)
			{
				return $oResult->result->data;
			}
		}
		
		return [];
	}

	/**
	 * Obtains all aliases for specified user.
	 * @param int $UserId User identifier.
	 * @return array|boolean
	 */
	public function GetAliases($UserId)
	{
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		
		$oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
		$oUser = $oCoreDecorator ? $oCoreDecorator->GetUser($UserId) : null;
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
		if ($oAccount)
		{
			$sEmail = $oAccount->Email;
			$sDomain = preg_match('/.+@(.+)$/',  $sEmail, $aMatches) && $aMatches[1] ? $aMatches[1] : '';
			
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
		
		$oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
		$oUser = $oCoreDecorator ? $oCoreDecorator->GetUser($UserId) : null;
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
		if ($oAccount)
		{
			$sEmail = $oAccount->Email;
			$sDomain = preg_match('/.+@(.+)$/',  $sEmail, $aMatches) && $aMatches[1] ? $aMatches[1] : '';
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
	public function DeleteAlias($UserId, $Aliases)
	{
		$oAuthenticatedUser = \Aurora\System\Api::getAuthenticatedUser();
		
		$oCoreDecorator = \Aurora\Modules\Core\Module::Decorator();
		$oUser = $oCoreDecorator ? $oCoreDecorator->GetUser($UserId) : null;
		$bUserFound = $oUser instanceof \Aurora\Modules\Core\Classes\User;
		
		if ($bUserFound && $oAuthenticatedUser->Role === \Aurora\System\Enums\UserRole::TenantAdmin && $oUser->IdTenant === $oAuthenticatedUser->IdTenant)
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::TenantAdmin);
		}
		else
		{
			\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::SuperAdmin);
		}
		
		$mResult = false;
		$oMailDecorator = \Aurora\System\Api::GetModuleDecorator('Mail');
		$oAccount = $bUserFound && $oMailDecorator ? $oMailDecorator->GetAccountByEmail($oUser->PublicId, $oUser->EntityId) : null;
		if ($oAccount)
		{
			foreach ($Aliases as $sAlias)
			{
				preg_match('/(.+)@(.+)$/',  $sAlias, $aMatches);
				$AliasName = isset($aMatches[1]) ? $aMatches[1] : '';
				$AliasDomain = isset($aMatches[2]) ? $aMatches[2] : '';
				if ($this->deleteForwarder($AliasName . '@' . $AliasDomain, $oAccount->Email, $oUser->IdTenant))
				{
					$mResult = true;
				}
			}
		}
		
		return $mResult;
	}
}
