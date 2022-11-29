<?php

if (file_exists($file = dirname(__FILE__) . '/../vendor/autoload.php')) {
    set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/../vendor/phing/phing/classes');

    require_once $file;
}

$loader = new Nette\Loaders\RobotLoader;

$loader->addDirectory(__DIR__ . '/fixtures/bookstore/build');
$loader->addDirectory(__DIR__ . '/fixtures/generator');
$loader->addDirectory(__DIR__ . '/fixtures/namespaced/build');
$loader->addDirectory(__DIR__ . '/fixtures/nestedset/build');
$loader->addDirectory(__DIR__ . '/fixtures/schemas/build');
$loader->addDirectory(__DIR__ . '/fixtures/treetest/build');

$loader->setTempDirectory(sys_get_temp_dir());

$loader->register();