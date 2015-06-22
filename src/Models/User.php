<?php
namespace DreamFactory\Core\Models;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\BadRequestException;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Model;

/**
 * User
 *
 * @property integer $id
 * @property string  $name
 * @property string  $first_name
 * @property string  $last_name
 * @property string  $email
 * @property string  $description
 * @property boolean $is_active
 * @property boolean $is_sys_admin
 * @property string  $created_date
 * @property string  $last_modified_date
 * @method static \Illuminate\Database\Query\Builder|User whereId($value)
 * @method static \Illuminate\Database\Query\Builder|User whereName($value)
 * @method static \Illuminate\Database\Query\Builder|User whereFirstName($value)
 * @method static \Illuminate\Database\Query\Builder|User whereLastName($value)
 * @method static \Illuminate\Database\Query\Builder|User whereEmail($value)
 * @method static \Illuminate\Database\Query\Builder|User whereIsActive($value)
 * @method static \Illuminate\Database\Query\Builder|User whereIsSysAdmin($value)
 * @method static \Illuminate\Database\Query\Builder|User whereCreatedDate($value)
 * @method static \Illuminate\Database\Query\Builder|User whereLastModifiedDate($value)
 */
class User extends BaseSystemModel implements AuthenticatableContract, CanResetPasswordContract
{

    use Authenticatable, CanResetPassword;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'user';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'is_active',
        'phone',
        'security_question',
        'security_answer',
        'adldap',
        'oauth_provider',
        'last_login_date'
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['is_sys_admin', 'password', 'remember_token'];

    protected $casts = ['is_active' => 'boolean', 'is_sys_admin' => 'boolean'];

    /**
     * Assigns a role to a user for all apps in the system.
     *
     * @param $user
     * @param $defaultRole
     *
     * @return bool
     * @throws \Exception
     */
    public static function applyDefaultUserAppRole($user, $defaultRole)
    {
        $apps = App::all();

        if (count($apps) === 0) {
            return false;
        }

        foreach ($apps as $app) {
            if (!UserAppRole::whereUserId($user->id)->whereAppId($app->id)->exists()) {
                $userAppRoleData = [
                    'user_id' => $user->id,
                    'app_id'  => $app->id,
                    'role_id' => $defaultRole
                ];

                UserAppRole::create($userAppRoleData);
            }
        }

        return true;
    }

    /**
     * @param $value
     *
     * @return string
     */
    public function getEmailAttribute($value)
    {
        if (false !== strpos($value, '+')) {
            list($emailId, $domain) = explode('@', $value);
            list($emailId, $provider) = explode('+', $emailId);

            $value = $emailId . '@' . $domain;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    protected static function createInternal($record, $params = [])
    {
        try {
            $model = static::create($record);

            if (ArrayUtils::getBool($params, 'admin') &&
                ArrayUtils::getBool($record, 'is_sys_admin')
            ) {
                $model->is_sys_admin = 1;
            }

            $model->password = ArrayUtils::get($record, 'password');
            $model->save();
        } catch (\PDOException $e) {
            throw $e;
        }

        return static::buildResult($model, $params);
    }

    /**
     * {@inheritdoc}
     */
    public static function updateInternal($id, $record, $params = [])
    {
        if (empty($record)) {
            throw new BadRequestException('There are no fields in the record to create . ');
        }

        if (empty($id)) {
            //Todo:perform logging below
            //Log::error( 'Update request with no id supplied: ' . print_r( $record, true ) );
            throw new BadRequestException('Identifying field "id" can not be empty for update request . ');
        }

        $model = static::find($id);

        if (!$model instanceof Model) {
            throw new ModelNotFoundException('No model found for ' . $id);
        }

        $pk = $model->primaryKey;
        //	Remove the PK from the record since this is an update
        ArrayUtils::remove($record, $pk);

        try {
            if ($model->is_sys_admin && !ArrayUtils::getBool($params, 'admin')) {
                throw new ForbiddenException('Not allowed to change an admin user.');
            } elseif (ArrayUtils::getBool($params, 'admin') && !$model->is_sys_admin) {
                throw new BadRequestException('Cannot update a non-admin user.');
            }

            $password = ArrayUtils::get($record, 'password');
            if (ArrayUtils::getBool($params, 'admin') && !empty($password)) {
                $model->password = ArrayUtils::get($record, 'password');
            }

            $model->update($record);

            return static::buildResult($model, $params);
        } catch (\Exception $ex) {
            if (!$ex instanceof ForbiddenException && !$ex instanceof BadRequestException) {
                throw new InternalServerErrorException('Failed to update resource: ' . $ex->getMessage());
            } else {
                throw $ex;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function deleteInternal($id, $record, $params = [])
    {
        if (empty($record)) {
            throw new BadRequestException('There are no fields in the record to create . ');
        }

        if (empty($id)) {
            //Todo:perform logging below
            //Log::error( 'Update request with no id supplied: ' . print_r( $record, true ) );
            throw new BadRequestException('Identifying field "id" can not be empty for update request . ');
        }

        $model = static::find($id);

        if (!$model instanceof Model) {
            throw new ModelNotFoundException('No model found for ' . $id);
        }

        try {
            if ($model->is_sys_admin && !ArrayUtils::getBool($params, 'admin')) {
                throw new ForbiddenException('Not allowed to delete an admin user.');
            } elseif (ArrayUtils::getBool($params, 'admin') && !$model->is_sys_admin) {
                throw new BadRequestException('Cannot delete a non-admin user.');
            }

            $result = static::buildResult($model, $params);
            $model->delete();

            return $result;
        } catch (\Exception $ex) {
            if (!$ex instanceof ForbiddenException && !$ex instanceof BadRequestException) {
                throw new InternalServerErrorException('Failed to delete resource: ' . $ex->getMessage());
            } else {
                throw $ex;
            }
        }
    }

    /**
     * Encrypts security answer.
     *
     * @param string $value
     */
    public function setSecurityAnswerAttribute($value)
    {
        $this->attributes['security_answer'] = bcrypt($value);
    }

    /**
     * Encrypts password.
     *
     * @param $password
     */
    public function setPasswordAttribute($password)
    {
        if (!empty($password)) {
            $password = bcrypt($password);
        }

        $this->attributes['password'] = $password;
    }
}