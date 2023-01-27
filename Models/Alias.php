<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\CpanelIntegrator\Models;

use Aurora\System\Classes\Model;
use Aurora\Modules\Mail\Models\MailAccount;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property int $IdUser
 */
class Alias extends Model
{
    protected $table = 'cpanel_aliases';

    protected $foreignModel = MailAccount::class;
    protected $foreignModelIdColumn = 'IdAccount'; // Column that refers to an external table

    protected $fillable = [
        'Id',
        'IdUser',
        'IdAccount',
        'Email',
        'ForwardTo',
        'FriendlyName',
        'UseSignature',
        'Signature'
    ];

    protected $appends = [
        'EntityId'
    ];

    public function getEntityIdAttribute()
    {
        return $this->Id;
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->Signature = '';
    }
}
