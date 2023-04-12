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
 * Aurora\Modules\CpanelIntegrator\Models\Alias
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 * @property int $IdUser
 * @property integer $Id
 * @property integer $IdAccount
 * @property string $Email
 * @property string $ForwardTo
 * @property string $FriendlyName
 * @property integer $UseSignature
 * @property string $Signature
 * @property \Illuminate\Support\Carbon|null $CreatedAt
 * @property \Illuminate\Support\Carbon|null $UpdatedAt
 * @property-read mixed $entity_id
 * @method static int count(string $columns = '*')
 * @method static \Illuminate\Database\Eloquent\Builder|\Aurora\Modules\CpanelIntegrator\Models\Alias find(int|string $id, array|string $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder|\Aurora\Modules\CpanelIntegrator\Models\Alias findOrFail(int|string $id, mixed $id, Closure|array|string $columns = ['*'], Closure $callback = null)
 * @method static \Illuminate\Database\Eloquent\Builder|\Aurora\Modules\CpanelIntegrator\Models\Alias first(array|string $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder|\Aurora\Modules\CpanelIntegrator\Models\Alias firstWhere(Closure|string|array|\Illuminate\Database\Query\Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Alias newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Alias newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Alias query()
 * @method static \Illuminate\Database\Eloquent\Builder|\Aurora\Modules\CpanelIntegrator\Models\Alias where(Closure|string|array|\Illuminate\Database\Query\Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereForwardTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereFriendlyName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereIdAccount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereIdUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Aurora\Modules\CpanelIntegrator\Models\Alias whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereSignature($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Alias whereUseSignature($value)
 * @mixin \Eloquent
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
