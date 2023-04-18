<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\CpanelIntegrator;

use Aurora\System\SettingsProperty;

/**
 * @property bool $Disabled
 * @property string $CpanelHost
 * @property string $CpanelPort
 * @property string $CpanelUser
 * @property string $CpanelPassword
 * @property int $UserDefaultQuotaMB
 * @property array $SupportedServers
 * @property bool $AllowCreateDeleteAccountOnCpanel
 * @property bool $AllowAliases
 * @property string $ForwardScriptPath
 */

class Settings extends \Aurora\System\Module\Settings
{
    protected function initDefaults()
    {
        $this->aContainer = [
            "Disabled" => new SettingsProperty(
                false,
                "bool",
                null,
                "Setting to true disables the module",
            ),
            "CpanelHost" => new SettingsProperty(
                "127.0.0.1",
                "string",
                null,
                "Hostname of cPanel server the integration is configured for",
            ),
            "CpanelPort" => new SettingsProperty(
                "2083",
                "string",
                null,
                "Port number used to connect to cPanel server",
            ),
            "CpanelUser" => new SettingsProperty(
                "",
                "string",
                null,
                "User account used for integration with cPanel server",
            ),
            "CpanelPassword" => new SettingsProperty(
                "",
                "string",
                null,
                "Password of the account used for integration with cPanel server",
            ),
            "UserDefaultQuotaMB" => new SettingsProperty(
                1,
                "int",
                null,
                "Default quota of new email accounts created on cPanel",
            ),
            "SupportedServers" => new SettingsProperty(
                [
                    "*"
                ],
                "array",
                null,
                "List of mail servers the integration applies to, * for all the mail servers",
            ),
            "AllowCreateDeleteAccountOnCpanel" => new SettingsProperty(
                false,
                "bool",
                null,
                "Enables managing email accounts from within adminpanel interface of our product",
            ),
            "AllowAliases" => new SettingsProperty(
                false,
                "bool",
                null,
                "Enables creating mail aliases from our adminpanel",
            ),
            "ForwardScriptPath" => new SettingsProperty(
                "",
                "string",
                null,
                "Filesystem location of custom mail forwarder, defaults to scripts/process_mail.php file",
            ),
        ];
    }
}
