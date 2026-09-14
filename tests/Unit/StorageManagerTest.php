<?php

declare(strict_types=1);

namespace Tests\Unit;

use Naf\Core\{Config, Container};
use Naf\Decorators\AutoResolvingContainer;
use Naf\Storage\Adapters\LocalAdapter;
use Naf\Storage\Exceptions\StorageException;
use Naf\Storage\Filesystem;
use Naf\Storage\StorageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\RecordingAdapter;
use Tests\NafTestCase;

use function Naf\{app, config};
use function Naf\Storage\storage;

final class StorageManagerTest extends NafTestCase
{
    private AutoResolvingContainer $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new AutoResolvingContainer(new Container());
    }

    public function testDefaultAndNamedDisksAreLazyCachedAndIsolated(): void
    {
        $manager = new StorageManager(new Config(['storage' => [
            'default' => 'local',
            'disks' => [
                'local' => ['adapter' => LocalAdapter::class, 'root' => $this->directory . '/local'],
                'documents' => ['adapter' => LocalAdapter::class, 'root' => $this->directory . '/documents'],
            ],
        ]]), $this->container);
        $this->assertSame($manager->disk(), $manager->disk('local'));
        $this->assertDirectoryDoesNotExist($this->directory . '/local');
        $this->assertDirectoryDoesNotExist($this->directory . '/documents');
        $manager->disk()->put('same', 'local');
        $manager->disk('documents')->put('same', 'documents');
        $this->assertSame('local', $manager->disk()->get('same'));
        $this->assertSame('documents', $manager->disk('documents')->get('same'));
        $this->assertNotSame($manager->disk(), $manager->disk('documents'));
    }

    public function testAdapterConfigurationUsesNafAutowiringAndSharedDependencies(): void
    {
        $dependency = (object) ['constructed' => 0];
        $this->container->set(\stdClass::class, $dependency);
        $manager = new StorageManager(new Config(['storage' => [
            'default' => 'first',
            'disks' => [
                'first' => ['adapter' => RecordingAdapter::class, 'bucket' => 'one'],
                'second' => ['adapter' => RecordingAdapter::class, 'bucket' => 'two'],
                'broken' => ['adapter' => 'MissingAdapter'],
            ],
        ]]), $this->container);
        $this->assertSame(0, $dependency->constructed);
        $manager->disk()->put('same', 'one');
        $manager->disk()->get('same');
        $this->assertSame(1, $dependency->constructed);
        $manager->disk('second')->put('same', 'two');
        $this->assertSame(2, $dependency->constructed);
        $this->assertSame('one', $manager->disk()->get('same'));
        $this->assertSame('two', $manager->disk('second')->get('same'));
    }

    public function testNativePluginDiscoveryDefaultConfigHelpersAndContainerServices(): void
    {
        $this->assertTrue(app()->hasPlugin('naf/storage'));
        $plugin = app()->getPlugin('naf/storage');
        $this->assertTrue($plugin->isBooted());
        $this->assertSame(dirname(__DIR__, 2) . '/bootstrap.php', realpath($plugin->getBootstrapFile()));
        $this->assertSame('local', config('storage:default'));
        $this->assertSame(BASE_PATH . '/storage', config('storage:disks:local:root'));
        $this->assertSame(LocalAdapter::class, config('storage:disks:local:adapter'));
        $this->assertSame(storage(), storage('local'));
        $this->assertSame(storage(), app()->container()->get(Filesystem::class));
        $this->assertSame(storage(), app()->container()->make(StorageConsumer::class)->filesystem);
        $this->assertSame(app()->container()->get(StorageManager::class), app()->container()->make(StorageConsumer::class)->manager);
        $this->assertDirectoryDoesNotExist(BASE_PATH . '/storage');
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidDiskConfiguration(mixed $config, ?string $name): void
    {
        $manager = new StorageManager(new Config(['storage' => $config]), $this->container);
        $this->expectException(StorageException::class);
        $manager->disk($name);
    }

    public static function invalidConfigurations(): array
    {
        return [
            [null, null], [false, null], ['invalid', null], [[], null],
            [['default' => 42], null], [['default' => []], null], [['default' => ''], null],
            [['default' => 'unknown', 'disks' => []], null],
            [['default' => 'local', 'disks' => 'invalid'], null],
            [['disks' => ['local' => false]], 'local'],
            [['disks' => ['local' => []]], 'local'],
            [['disks' => ['local' => ['adapter' => 'Missing']]], 'local'],
            [['disks' => ['local' => ['adapter' => new \stdClass()]]], 'local'],
            [['disks' => ['local' => ['adapter' => \stdClass::class]]], 'local'],
            [['disks' => ['local' => ['adapter' => LocalAdapter::class]]], 'local'],
            [['disks' => ['local' => ['adapter' => LocalAdapter::class, 'root' => []]]], 'local'],
            [['disks' => ['local' => ['adapter' => LocalAdapter::class, 'root' => 'relative']]], 'local'],
            [['disks' => ['local' => ['adapter' => LocalAdapter::class, 'root' => '/tmp/storage', 'url' => false]]], 'local'],
            [['disks' => ['local' => ['adapter' => LocalAdapter::class, 'root' => '/tmp/storage', 'url' => 'unsafe:']]], 'local'],
            [['disks' => []], ''],
        ];
    }

    public function testConstructionErrorsRetainTheirCauseAndCanBeRetried(): void
    {
        $manager = new StorageManager(new Config(['storage' => [
            'default' => 'custom',
            'disks' => ['custom' => ['adapter' => RecordingAdapter::class, 'bucket' => 'one']],
        ]]), $this->container);
        $this->container->set(\stdClass::class, static fn () => throw new \RuntimeException('Dependency unavailable'));
        try {
            $manager->disk();
            $this->fail('Construction should fail');
        } catch (StorageException $exception) {
            $this->assertNotNull($exception->getPrevious());
            $this->assertStringContainsString('custom', $exception->getMessage());
        }
        $this->container->set(\stdClass::class, (object) ['constructed' => 0]);
        $this->assertInstanceOf(Filesystem::class, $manager->disk());
    }

    public function testBootstrapResolvesConfigurationLazilyAndSupportsServiceOverrides(): void
    {
        $container = app()->container();
        $previous = [];
        foreach ([Config::class, StorageManager::class, Filesystem::class] as $id) {
            $previous[$id] = $container->get($id);
        }
        try {
            $calls = 0;
            $container->set(Config::class, function () use (&$calls): Config {
                $calls++;
                return new Config(['storage' => [
                    'default' => 'documents',
                    'disks' => ['documents' => [
                        'adapter' => LocalAdapter::class,
                        'root' => $this->directory . '/configured',
                        'url' => '/documents',
                    ]],
                ]]);
            });
            require dirname(__DIR__, 2) . '/bootstrap.php';
            $this->assertSame(0, $calls);
            storage()->put('file', 'configured');
            $this->assertSame(1, $calls);
            $this->assertSame('configured', file_get_contents($this->directory . '/configured/file'));
            $this->assertSame('/documents/file', storage()->url('file'));

            $override = new StorageManager(new Config(['storage' => [
                'default' => 'custom',
                'disks' => ['custom' => ['adapter' => LocalAdapter::class, 'root' => $this->directory . '/override']],
            ]]), $container);
            $container->set(StorageManager::class, $override);
            $this->assertSame($override->disk(), storage());
        } finally {
            foreach ($previous as $id => $service) {
                $container->set($id, $service);
            }
        }
    }
}

final class StorageConsumer
{
    public function __construct(public Filesystem $filesystem, public StorageManager $manager)
    {
    }
}
