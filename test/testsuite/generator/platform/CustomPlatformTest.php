<?php

class CustomPlatformTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var GeneratorConfig
     */
    protected $generatorConfig;

    public function setUp(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../fixtures/generator/platform/');
        $props = [
            "propel.project"             => "kfw-propel",
            "propel.database"            => "pgsql", // Or anything else
            "propel.projectDir"          => $projectDir,
            "propel.platform.class"      => CustomPlatform::class,
            "propel.buildtime.conf.file" => "buildtime-conf.xml",
        ];

        $this->generatorConfig = new GeneratorConfig($props);
    }

    public function testGetPlatform()
    {
        $this->assertInstanceOf(CustomPlatform::class, $this->generatorConfig->getConfiguredPlatform());
        $this->assertInstanceOf(CustomPlatform::class, $this->generatorConfig->getConfiguredPlatform(null, 'default'));
    }
}
