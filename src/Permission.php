<?php

/**
 * @desc 描述
 * @author Tinywan(ShaoBo Wan)
 * @date 2022/1/10 21:33
 */

declare(strict_types=1);

namespace Casbin\WebmanPermission;


use Casbin\Enforcer;
use Casbin\Exceptions\CasbinException;
use Casbin\Model\Model;
use support\Container;
use Casbin\WebmanPermission\Watcher\RedisWatcher;
use Workerman\Worker;
use Webman\Bootstrap;

/**
 * @see \Casbin\Enforcer
 * @mixin Enforcer
 * @method static bool enforce(mixed ...$rvals) 权限检查，输入参数通常是(sub, obj, act)
 * @method static bool addPolicy(mixed ...$params) 当前策略添加授权规则
 * @method static bool addPolicies(mixed ...$params) 当前策略添加授权规则
 * @method static bool hasPolicy(mixed ...$params) 确定是否存在授权规则
 * @method static bool removePolicy(mixed ...$params) 当前策略移除授权规则
 * @method static array getAllRoles() 获取所有角色
 * @method static array getPolicy() 获取所有的角色的授权规则
 * @method static array getRolesForUser(string $name, string ...$domain) 获取用户具有的角色
 * @method static array getUsersForRole(string $name, string ...$domain) 获取具有角色的用户
 * @method static bool hasRoleForUser(string $name, string $role, string ...$domain) 确定用户是否具有角色
 * @method static bool addRoleForUser(string $user, string $role, string ...$domain) 给用户添加角色
 * @method static bool addPermissionForUser(string $user, string ...$permission) 赋予权限给某个用户或角色
 * @method static bool deleteRoleForUser(string $user, string $role, string $domain) 删除用户的角色
 * @method static bool deleteRolesForUser(string $user, string ...$domain) 删除某个用户的所有角色
 * @method static bool deleteRole(string $role) 删除单个角色
 * @method static bool deletePermission(string ...$permission) 删除权限
 * @method static bool deletePermissionForUser(string $name, string $permission) 删除用户或角色的权限。如果用户或角色没有权限则返回 false(不会受影响)。
 * @method static bool deletePermissionsForUser(string $name) 删除用户或角色的权限。如果用户或角色没有任何权限（也就是不受影响），则返回false。
 * @method static array getPermissionsForUser(string $name) 获取用户或角色的所有权限
 * @method static bool hasPermissionForUser(string $user, string ...$permission) 决定某个用户是否拥有某个权限
 * @method static array getImplicitRolesForUser(string $name, string ...$domain) 获取用户具有的隐式角色
 * @method static array getImplicitPermissionsForUser(string $username, string ...$domain) 获取用户具有的隐式权限
 * @method static void addFunction(string $name, \Closure $func) 添加一个自定义函数
 */
class Permission implements Bootstrap
{
    /**
     * @var Enforcer|null $_manager
     */
    protected static ?Enforcer $_manager = null;

    /**
     * @param Worker $worker
     * @return void
     * @throws CasbinException
     * @author Tinywan(ShaoBo Wan)
     */
    public static function start($worker)
    {
        if ($worker) {
            $driver = config('plugin.casbin.webman-permission.permission.default');
            $config = config('plugin.casbin.webman-permission.permission.' . $driver);
            $model = new Model();
            if ('file' == $config['model']['config_type']) {
                $model->loadModel($config['model']['config_file_path']);
            } elseif ('text' == $config['model']['config_type']) {
                $model->loadModel($config['model']['config_text']);
            }
            if (is_null(static::$_manager)) {
                static::$_manager = new Enforcer($model, Container::get($config['adapter']), false);
            }

            $watcher = new RedisWatcher(config('redis.default'));
            static::$_manager->setWatcher($watcher);
            $watcher->setUpdateCallback(function () {
                static::$_manager->loadPolicy();
            });
        }
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @author Tinywan(ShaoBo Wan)
     */
    public static function __callStatic($name, $arguments)
    {
        return static::$_manager->{$name}(...$arguments);
    }
}
