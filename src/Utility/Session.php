<?php

namespace DreamFactory\Core\Utility;

use \Request;
use Carbon\Carbon;
use Illuminate\Routing\Router;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Models\User;

class Session
{
    /**
     * Checks to see if Access is Allowed based on Role-Service-Access.
     *
     * @param int $requestor
     *
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\NotImplementedException
     */
    public static function isAccessAllowed($requestor = ServiceRequestorTypes::API)
    {
        /** @var Router $router */
        $router = app('router');
        $service = strtolower($router->input('service'));
        $component = strtolower($router->input('resource'));
        $action = VerbsMask::toNumeric(Request::getMethod());
        $allowed = static::getServicePermissions($service, $component, $requestor);

        return ($action & $allowed) ? true : false;
    }

    /**
     * Checks for permission based on Role-Service-Access.
     *
     * @throws ForbiddenException
     */
    public static function checkPermission()
    {
        if (!static::isAccessAllowed()) {
            throw new ForbiddenException('Forbidden. You do not have permission to access the requested service/resource.');
        }
    }

    /**
     * @param string $action    - REST API action name
     * @param string $service   - API name of the service
     * @param string $component - API component/resource name
     * @param int    $requestor - Entity type requesting the service
     *
     * @throws ForbiddenException
     */
    public static function checkServicePermission(
        $action,
        $service,
        $component = null,
        $requestor = ServiceRequestorTypes::API
    ){
        $verb = VerbsMask::toNumeric(static::cleanAction($action));

        $mask = static::getServicePermissions($service, $component, $requestor);

        if (!($verb & $mask)) {
            $msg = ucfirst($action) . " access to ";
            if (!empty($component)) {
                $msg .= "component '$component' of ";
            }

            $msg .= "service '$service' is not allowed by this user's role.";

            throw new ForbiddenException($msg);
        }
    }

    /**
     * @param string $service   - API name of the service
     * @param string $component - API component/resource name
     * @param int    $requestor - Entity type requesting the service
     *
     * @returns array
     */
    public static function getServicePermissions($service, $component = null, $requestor = ServiceRequestorTypes::API)
    {
        if (static::isSysAdmin()) {
            return
                VerbsMask::NONE_MASK |
                VerbsMask::GET_MASK |
                VerbsMask::POST_MASK |
                VerbsMask::PUT_MASK |
                VerbsMask::PATCH_MASK |
                VerbsMask::DELETE_MASK;
        }

        $services = ArrayUtils::clean(static::get('role.services'));
        $service = strval($service);
        $component = strval($component);

        //  If exact match found take it, otherwise follow up the chain as necessary
        //  All - Service - Component - Sub-component
        $allAllowed = VerbsMask::NONE_MASK;
        $allFound = false;
        $serviceAllowed = VerbsMask::NONE_MASK;
        $serviceFound = false;
        $componentAllowed = VerbsMask::NONE_MASK;
        $componentFound = false;
        $exactAllowed = VerbsMask::NONE_MASK;
        $exactFound = false;
        foreach ($services as $svcInfo) {
            $tempRequestors = ArrayUtils::get($svcInfo, 'requestor_mask', ServiceRequestorTypes::API);
            if (!($requestor & $tempRequestors)) {
                //  Requestor type not found in allowed requestors, skip access setting
                continue;
            }

            $tempService = strval(ArrayUtils::get($svcInfo, 'service'));
            $tempComponent = strval(ArrayUtils::get($svcInfo, 'component'));
            $tempVerbs = ArrayUtils::get($svcInfo, 'verb_mask');

            if (0 == strcasecmp($service, $tempService)) {
                if (!empty($component)) {
                    if (0 == strcasecmp($component, $tempComponent)) {
                        // exact match
                        $exactAllowed |= $tempVerbs;
                        $exactFound = true;
                    } elseif (0 ==
                        strcasecmp(substr($component, 0, strpos($component, '/') + 1) . '*', $tempComponent)
                    ) {
                        $componentAllowed |= $tempVerbs;
                        $componentFound = true;
                    } elseif ('*' == $tempComponent) {
                        $serviceAllowed |= $tempVerbs;
                        $serviceFound = true;
                    }
                } else {
                    if (empty($tempComponent)) {
                        // exact match
                        $exactAllowed |= $tempVerbs;
                        $exactFound = true;
                    } elseif ('*' == $tempComponent) {
                        $serviceAllowed |= $tempVerbs;
                        $serviceFound = true;
                    }
                }
            } else {
                if (empty($tempService) && (('*' == $tempComponent) || (empty($tempComponent) && empty($component)))
                ) {
                    $allAllowed |= $tempVerbs;
                    $allFound = true;
                }
            }
        }

        if ($exactFound) {
            return $exactAllowed;
        } elseif ($componentFound) {
            return $componentAllowed;
        } elseif ($serviceFound) {
            return $serviceAllowed;
        } elseif ($allFound) {
            return $allAllowed;
        }

        return VerbsMask::NONE_MASK;
    }

    /**
     * @param string $action - requested REST action
     *
     * @return string
     */
    protected static function cleanAction($action)
    {
        // check for non-conformists
        $action = strtoupper($action);
        switch ($action) {
            case 'READ':
                return Verbs::GET;

            case 'CREATE':
                return Verbs::POST;

            case 'UPDATE':
                return Verbs::PUT;
        }

        return $action;
    }

    /**
     * @param string $action
     * @param string $service
     * @param string $component
     *
     * @returns bool
     */
    public static function getServiceFilters($action, $service, $component = null)
    {
        if (static::isSysAdmin()) {
            return [];
        }

        if (null === ($roleInfo = session('rsa.role'))) {
            // no role assigned
            return [];
        }

        $services = ArrayUtils::clean(ArrayUtils::get($roleInfo, 'services'));

        $serviceAllowed = null;
        $serviceFound = false;
        $componentFound = false;
        $action = VerbsMask::toNumeric(static::cleanAction($action));

        foreach ($services as $svcInfo) {
            $tempService = ArrayUtils::get($svcInfo, 'service');
            if (null === $tempVerbs = ArrayUtils::get($svcInfo, 'verb_mask')) {
                //  Check for old verbs array
                if (null !== $temp = ArrayUtils::get($svcInfo, 'verbs')) {
                    $tempVerbs = VerbsMask::arrayToMask($temp);
                }
            }

            if (0 == strcasecmp($service, $tempService)) {
                $serviceFound = true;
                $tempComponent = ArrayUtils::get($svcInfo, 'component');
                if (!empty($component)) {
                    if (0 == strcasecmp($component, $tempComponent)) {
                        $componentFound = true;
                        if ($tempVerbs & $action) {
                            $filters = ArrayUtils::get($svcInfo, 'filters');
                            $operator = ArrayUtils::get($svcInfo, 'filter_op', 'AND');
                            if (empty($filters)) {
                                return null;
                            }

                            return ['filters' => $filters, 'filter_op' => $operator];
                        }
                    } elseif (empty($tempComponent) || ('*' == $tempComponent)) {
                        if ($tempVerbs & $action) {
                            $filters = ArrayUtils::get($svcInfo, 'filters');
                            $operator = ArrayUtils::get($svcInfo, 'filter_op', 'AND');
                            if (empty($filters)) {
                                return null;
                            }

                            $serviceAllowed = ['filters' => $filters, 'filter_op' => $operator];
                        }
                    }
                } else {
                    if (empty($tempComponent) || ('*' == $tempComponent)) {
                        if ($tempVerbs & $action) {
                            $filters = ArrayUtils::get($svcInfo, 'filters');
                            $operator = ArrayUtils::get($svcInfo, 'filter_op', 'AND');
                            if (empty($filters)) {
                                return null;
                            }

                            $serviceAllowed = ['filters' => $filters, 'filter_op' => $operator];
                        }
                    }
                }
            }
        }

        if ($componentFound) {
            // at least one service and component match was found, but not the right verb

            return null;
        } elseif ($serviceFound) {
            return $serviceAllowed;
        }

        return null;
    }

    /**
     * @param array $credentials
     * @param bool  $remember
     * @param bool  $login
     *
     * @return bool
     * @throws \Exception
     */
    public static function authenticate(array $credentials, $remember=false, $login=true)
    {
        if (\Auth::attempt($credentials, false, false)) {
            if($login) {
                $user = \Auth::getLastAttempted();
                $user->update(['last_login_date' => Carbon::now()->toDateTimeString()]);
                Session::setUserInfoWithJWT($user, $remember);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return bool
     */
    public static function logout()
    {
        $token = static::getSessionToken();
        if(empty($token)){
            return false;
        }
        JWTUtilities::invalidate($token);
    }

    /**
     * Sets basic info of the user in session with JWT when authenticated.
     *
     * @param  array|User $user
     * @param bool        $forever
     *
     * @return bool
     */
    public static function setUserInfoWithJWT($user, $forever = false)
    {
        $userInfo = null;
        if ($user instanceof User) {
            $userInfo = $user->toArray();
            ArrayUtils::set($userInfo, 'is_sys_admin', $user->is_sys_admin);
        }

        if (!empty($userInfo)) {
            $token = JWTUtilities::makeJWTByUserId(ArrayUtils::get($userInfo, 'id'), $forever);
            static::setSessionToken($token);

            return static::setUserInfo($userInfo);
        }

        return false;
    }

    /**
     * Sets basic info of the user in session when authenticated.
     *
     * @param array $user
     *
     * @return bool
     */
    public static function setUserInfo($user)
    {
        if (!empty($user)) {
            \Session::put('user.id', ArrayUtils::get($user, 'id'));
            \Session::put('user.display_name', ArrayUtils::get($user, 'name'));
            \Session::put('user.first_name', ArrayUtils::get($user, 'first_name'));
            \Session::put('user.last_name', ArrayUtils::get($user, 'last_name'));
            \Session::put('user.email', ArrayUtils::get($user, 'email'));
            \Session::put('user.is_sys_admin', ArrayUtils::get($user, 'is_sys_admin'));
            \Session::put('user.last_login_date', ArrayUtils::get($user, 'last_login_date'));

            return true;
        }

        return false;
    }

    public static function setSessionData($appId = null, $userId = null)
    {
        $appInfo = ($appId) ? CacheUtilities::getAppInfo($appId) : null;
        $userInfo = ($userId) ? CacheUtilities::getUserInfo($userId) : null;

        $roleId = null;
        if (!empty($userId) && !empty($appId)) {
            $roleId = CacheUtilities::getRoleIdByAppIdAndUserId($appId, $userId);
        }

        if (empty($roleId) && !empty($appInfo)) {
            $roleId = ArrayUtils::get($appInfo, 'role_id');
        }

        Session::setUserInfo($userInfo);
        Session::put('app_id', $appId);

        $roleInfo = ($roleId) ? CacheUtilities::getRoleInfo($roleId) : null;
        if (!empty($roleInfo)) {
            Session::put('role.id', $roleId);
            Session::put('role.name', $roleInfo['name']);
            Session::put('role.services', $roleInfo['role_service_access_by_role_id']);
        }

        $systemLookup = CacheUtilities::getSystemLookups();
        $systemLookup = (!empty($systemLookup)) ? $systemLookup : [];
        $appLookup = (!empty($appInfo['app_lookup_by_app_id'])) ? $appInfo['app_lookup_by_app_id'] : [];
        $roleLookup = (!empty($roleInfo['role_lookup_by_role_id'])) ? $roleInfo['role_lookup_by_role_id'] : [];
        $userLookup = (!empty($userInfo['user_lookup_by_user_id'])) ? $userInfo['user_lookup_by_user_id'] : [];

        $combinedLookup = LookupKey::combineLookups($systemLookup, $appLookup, $roleLookup, $userLookup);

        Session::put('lookup', ArrayUtils::get($combinedLookup, 'lookup'));
        Session::put('lookup_secret', ArrayUtils::get($combinedLookup, 'lookup_secret'));
    }

    /**
     * Fetches user session data based on the authenticated user.
     *
     * @return array
     * @throws UnauthorizedException
     */
    public static function getPublicInfo()
    {
        if (empty(session('user'))) {
            throw new UnauthorizedException('There is no valid session for the current request.');
        }

        $sessionData = [
            'session_token'   => session('session_token'),
            'session_id'      => session('session_token'), // temp for compatibility with 1.x
            'id'              => session('user.id'),
            'name'            => session('user.display_name'),
            'first_name'      => session('user.first_name'),
            'last_name'       => session('user.last_name'),
            'email'           => session('user.email'),
            'is_sys_admin'    => session('user.is_sys_admin'),
            'last_login_date' => session('user.last_login_date'),
            'host'            => gethostname()
        ];

        $role = static::get('role');
        if (!session('user.is_sys_admin') && !empty($role)) {
            $sessionData['role'] = ArrayUtils::get($role, 'name');
            $sessionData['role_id'] = ArrayUtils::get($role, 'id');
        }

        return $sessionData;
    }

    /**
     * @return User|null
     */
    public static function user()
    {
        if (static::isAuthenticated()) {
            return User::find(static::getCurrentUserId());
        }

        return null;
    }

    /**
     * Gets user id of the currently logged in user.
     *
     * @return integer|null
     */
    public static function getCurrentUserId()
    {
        return session('user.id');
    }

    /**
     * Gets role id of the currently logged in user, if not admin.
     *
     * @return integer|null
     */
    public static function getRoleId()
    {
        return static::get('role.id');
    }

    public static function isAuthenticated()
    {
        $userId = static::getCurrentUserId();

        return boolval($userId);
    }

    public static function getSessionToken()
    {
        return \Session::get('session_token');
    }

    public static function setSessionToken($token)
    {
        \Session::set('session_token', $token);
    }

    public static function setApiKey($apiKey){
        \Session::set('api_key', $apiKey);
    }

    public static function getApiKey(){
        return \Session::get('api_key');
    }

    /**
     * @return bool
     */
    public static function isSysAdmin()
    {
        return boolval(session('user.is_sys_admin'));
    }

    public static function get($key, $default = null)
    {
        return \Session::get($key, $default);
    }

    public static function set($name, $value)
    {
        \Session::set($name, $value);
    }

    public static function put($key, $value = null)
    {
        \Session::put($key, $value);
    }

    public static function push($key, $value)
    {
        \Session::push($key, $value);
    }

    public static function has($name)
    {
        return \Session::has($name);
    }

    public static function getId()
    {
        return \Session::getId();
    }

    public static function isValidId($id)
    {
        return \Session::isValidId($id);
    }

    public static function setId($sessionId)
    {
        \Session::setId($sessionId);
    }

    public static function start()
    {
        return \Session::start();
    }

    public static function driver($driver = null)
    {
        return \Session::driver($driver);
    }

    public static function all()
    {
        return \Session::all();
    }

    public static function flush()
    {
        \Session::flush();
    }

    public static function remove($name)
    {
        return \Session::remove($name);
    }

    public static function forget($key)
    {
        \Session::forget($key);
    }
}