<?php

namespace PHPCensor;

use Pimple\Container as BaseContainer;
use PHPixie\Slice;
use PHPixie\Database;

class Container extends BaseContainer
{
    public function init(Application $application, array $config)
    {
        $this['application'] = $application;

        $dns = $config['b8']['database']['type'] . ':host=' . $config['b8']['database']['servers']['read'][0]['host'];
        if (isset($config['b8']['database']['servers']['read'][0]['port'])) {
            $dns .= ';port=' . (integer)$config['b8']['database']['servers']['read'][0]['port'];
        }
        $dns .= ';dbname=' . $config['b8']['database']['name'];
        
        $this['database.dns']      = $dns;
        $this['database.user']     = $config['b8']['database']['username'];
        $this['database.password'] = $config['b8']['database']['password'];
        
        $this['database'] = function ($container) {
            $slice    = new Slice();
            $database = new Database($slice->arrayData(
                [
                    'default' => [
                        'driver'     => 'pdo',
                        'connection' => $container['database.dns'],
                        'user'       => $container['database.user'],
                        'password'   => $container['database.password'],
                    ]
                ]
            ));

            return $database;
        };
    }
}