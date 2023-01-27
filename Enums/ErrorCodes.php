<?php
/*
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\CpanelIntegrator\Enums;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 */
class ErrorCodes
{
    public const DataIntegrity					= 1001;
    public const Validation_InvalidParameters	= 1002;
    public const AliasCreateFailed				= 1003;
    public const AliasUpdateFailed				= 1004;
    public const DomainOutsideTenant			= 1005;
    public const AliaMatchesExistingEmail		= 1006;
    public const AliasAlreadyExists			= 1007;
    public const SaleCreateFailed				= 1008;
    public const SaleUpdateFailed				= 1009;

    /**
     * @var array
     */
    protected $aConsts = [
        'DataIntegrity'					=> self::DataIntegrity,
        'Validation_InvalidParameters'	=> self::Validation_InvalidParameters,
        'AliasCreateFailed'				=> self::SaleCreateFailed,
        'AliasUpdateFailed'				=> self::SaleUpdateFailed,
        'DomainOutsideTenant'			=> self::DomainOutsideTenant,
        'AliaMatchesExistingEmail'		=> self::AliaMatchesExistingEmail,
        'AliasAlreadyExists'			=> self::AliasAlreadyExists
    ];
}
