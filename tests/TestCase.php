<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        if (env('LIVT_MYSQL_TEST_SUITE') !== '1'
            || config('database.default') !== 'mysql'
            || app()->environment() !== 'testing'
            || config('database.connections.mysql.database') !== 'livt_test'
            || config('database.connections.mysql.username') !== 'livt_test'
            || filled(config('database.connections.mysql.url'))
            || $app->make('db')->connection('mysql')->selectOne('SELECT DATABASE() AS database_name')->database_name !== 'livt_test') {
            throw new \RuntimeException('Tests require the dedicated livt_test MySQL database.');
        }

        return $app;
    }
}
