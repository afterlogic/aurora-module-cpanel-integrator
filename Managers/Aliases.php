<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\CpanelIntegrator\Managers;

use Aurora\Modules\CpanelIntegrator\Models\Alias;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 */
class Aliases extends \Aurora\System\Managers\AbstractManager
{
    /**
     * @param \Aurora\System\Module\AbstractModule $oModule
     */
    public function __construct(\Aurora\System\Module\AbstractModule $oModule = null)
    {
        parent::__construct($oModule);
    }

    /**
     * @param \Aurora\Modules\CpanelIntegrator\Models\Alias $oAlias
     * @return int|bool
     */
    public function createAlias(\Aurora\Modules\CpanelIntegrator\Models\Alias &$oAlias)
    {
        $mResult = false;
        if ($oAlias->validate()) {
            $mResult = $oAlias->save();
            if (!$mResult) {
                throw new \Aurora\System\Exceptions\ManagerException(\Aurora\Modules\CpanelIntegrator\Enums\ErrorCodes::AliasCreateFailed);
            }
        }

        return $mResult;
    }

    /**
     * @param \Aurora\Modules\CpanelIntegrator\Models\Alias $oAlias
     * @return bool
     */
    public function updateAlias(\Aurora\Modules\CpanelIntegrator\Models\Alias $oAlias)
    {
        $bResult = false;
        if ($oAlias->validate()) {
            if (!$oAlias->save()) {
                throw new \Aurora\System\Exceptions\ManagerException(\Aurora\Modules\CpanelIntegrator\Enums\ErrorCodes::AliasUpdateFailed);
            }

            $bResult = true;
        }

        return $bResult;
    }

    /**
     * @param \Aurora\Modules\CpanelIntegrator\Models\Alias $oAlias
     * @return bool
     */
    public function deleteAlias(\Aurora\Modules\CpanelIntegrator\Models\Alias $oAlias)
    {
        $bResult = $oAlias->delete();
        return $bResult;
    }

    /**
     * @param int $iEntityId
     * @return object
     */
    public function getAlias($iEntityId)
    {
        $oResult = Alias::find($iEntityId);

        return $oResult;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder $oFilter
     * @return array
     */
    public function getAliases($iCount = 0, $iLimit = 0, \Illuminate\Database\Eloquent\Builder $oFilter = null)
    {
        if ($oFilter === null) {
            $oFilter = Alias::query();
        }
        if ($iCount > 0) {
            $oFilter = $oFilter->offset($iCount);
        }
        if ($iLimit > 0) {
            $oFilter = $oFilter->limit($iLimit);
        }
        return $oFilter->get();
    }

    /**
     * @param int $iUserId UserId.
     * @return array
     */
    public function getAliasesByUserId($iUserId)
    {
        return $this->getAliases(0, 0, Alias::where('IdUser', $iUserId));
    }

    /**
     * @param int $iUserId UserId.
     * @return bool|\Aurora\Modules\CpanelIntegrator\Classes\Alias
     */
    public function getUserAliasByEmail($iUserId, $sEmail)
    {
        return Alias::where('IdUser', $iUserId)->where('Email', $sEmail)->first();
    }
}
