<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Data\Configuration\ServerData;
use Cline\Forrst\Servers\ConfigurationServer;
use Illuminate\Support\Facades\Config;
use Tests\Support\Fakes\Functions\GetData;
use Tests\Support\Fakes\Functions\NotifyHello;
use Tests\Support\Fakes\Functions\Subtract;
use Tests\Support\Fakes\Functions\Sum;

describe('ConfigurationServer', function (): void {
    test('creates server from configuration and accesses properties', function (): void {
        $serverData = ServerData::from([
            'name' => 'test',
            'path' => '/rpc',
            'route' => '/rpc',
            'version' => '1.0',
            'middleware' => [],
            'functions' => null,
        ]);

        $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));

        expect($server)->toBeInstanceOf(ConfigurationServer::class);
        expect($server->getName())->toBe('test');
        expect($server->getRoutePath())->toBe('/rpc');
        expect($server->getRouteName())->toBe('rpc');
        expect($server->getVersion())->toBe('1.0');
        expect($server->getMiddleware())->toBe([]);
    });

    test('returns configured methods when explicitly defined', function (): void {
        $configuredMethods = [
            GetData::class,
            Sum::class,
            NotifyHello::class,
        ];

        $serverData = ServerData::from([
            'name' => 'test',
            'path' => '/rpc',
            'route' => '/rpc',
            'version' => '1.0',
            'middleware' => [],
            'functions' => $configuredMethods,
        ]);

        $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));

        expect($server->functions())->toBe($configuredMethods);
    });

    test('returns empty array when methods directory does not exist', function (): void {
        Config::set('rpc.paths.functions', '/non/existent/directory');
        Config::set('rpc.namespaces.functions', 'App\\Methods');

        $serverData = ServerData::from([
            'name' => 'test',
            'path' => '/rpc',
            'route' => '/rpc',
            'version' => '1.0',
            'middleware' => [],
            'functions' => null,
        ]);

        $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));

        expect($server->functions())->toBe([]);
    });

    test('auto-discovers method files from configured directory', function (): void {
        // Use absolute path for test support methods
        $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
        $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

        Config::set('rpc.paths.functions', $methodsPath);
        Config::set('rpc.namespaces.functions', $methodsNamespace);

        $serverData = ServerData::from([
            'name' => 'test-auto-discover',
            'path' => '/rpc',
            'route' => '/rpc',
            'version' => '1.0',
            'middleware' => [],
            'functions' => null,
        ]);

        $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
        $methods = $server->functions();

        expect($methods)->toBeArray();
        expect(count($methods))->toBeGreaterThan(0);
        // These are actual test methods that implement FunctionInterface
        expect($methods)->toContain(GetData::class);
        expect($methods)->toContain(Sum::class);
        expect($methods)->toContain(Subtract::class);
    });

    test('functions() can be called multiple times', function (): void {
        $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
        $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

        Config::set('rpc.paths.functions', $methodsPath);
        Config::set('rpc.namespaces.functions', $methodsNamespace);

        $serverData = ServerData::from([
            'name' => 'test',
            'path' => '/rpc',
            'route' => '/rpc',
            'version' => '1.0',
            'middleware' => [],
            'functions' => null,
        ]);

        $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));

        // Call methods() multiple times - should work without errors
        $methods1 = $server->functions();
        $methods2 = $server->functions();
        $methods3 = $server->functions();

        expect($methods1)->toBeArray();
        expect($methods2)->toBeArray();
        expect($methods3)->toBeArray();
        expect($methods1)->toBe($methods2);
        expect($methods2)->toBe($methods3);
    });

    describe('Auto-discovery Pipeline Coverage', function (): void {
        test('executes full transformation pipeline with real method files', function (): void {
            // Arrange - Use actual test methods directory
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
            $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-pipeline',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - This will hit lines 131-142 and 145
            expect($methods)->toBeArray();
            expect($methods)->toContain(GetData::class);
            expect($methods)->toContain(Sum::class);
            expect($methods)->toContain(Subtract::class);
            expect($methods)->toContain(NotifyHello::class);

            // Verify methods have required interface
            foreach ($methods as $method) {
                expect(class_implements($method))->toContain(FunctionInterface::class);
            }
        });

        test('discovers methods from nested directory structures', function (): void {
            // Arrange
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
            $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-nested',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Verifies path to namespace conversion (lines 136-139)
            expect($methods)->toBeArray();
            expect(count($methods))->toBeGreaterThan(0);

            // Each method class should be properly namespaced
            foreach ($methods as $method) {
                expect($method)->toStartWith($methodsNamespace);
                expect($method)->not->toContain('/');
                expect($method)->toContain('\\');
            }
        });

        test('applies ucfirst correctly during namespace transformation', function (): void {
            // Arrange
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
            $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-ucfirst',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Line 139 applies ucfirst
            expect($methods)->toBeArray();

            foreach ($methods as $method) {
                $className = class_basename($method);
                // First character should be uppercase
                expect($className[0])->toBe(mb_strtoupper($className[0]));
            }
        });

        test('filters out Abstract and Test files during real discovery', function (): void {
            // Arrange
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
            $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-filtering',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Line 136 rejects AbstractFunction and Test.php filenames
            expect($methods)->toBeArray();

            foreach ($methods as $method) {
                expect($method)->not->toContain('AbstractFunction');
                // Class names shouldn't end with "Test" (test files), but namespace can contain "Tests"
                expect(class_basename($method))->not->toEndWith('Test');
            }
        });

        test('validates methods implement FunctionInterface during discovery', function (): void {
            // Arrange
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
            $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-interface-check',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Line 141 filters by interface implementation
            expect($methods)->toBeArray();
            expect(count($methods))->toBeGreaterThan(0);

            foreach ($methods as $method) {
                $implements = class_implements($method);
                expect($implements)->toBeArray();
                expect(in_array(FunctionInterface::class, $implements, true))->toBeTrue();
            }
        });

        test('returns discovered methods array correctly', function (): void {
            // Arrange
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/Functions');
            $methodsNamespace = 'Tests\\Support\\Fakes\\Functions';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-return',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Line 145 returns the methods array
            expect($methods)->toBeArray();
            expect($methods)->not->toBeEmpty();

            // Verify structure
            $keys = array_keys($methods);
            expect($keys)->toEqual(range(0, count($methods) - 1));
        });
    });

    describe('Edge Cases and Error Handling', function (): void {
        test('filters out PHP files that do not contain valid classes', function (): void {
            // Arrange - Use directory with invalid PHP file
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/InvalidMethods');
            $methodsNamespace = 'Tests\\Support\\Fakes\\InvalidMethods';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-invalid',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Line 140-144: class_exists check and interface validation should filter out invalid files
            expect($methods)->toBeArray();
            expect($methods)->toBeEmpty(); // No valid method classes in InvalidMethods directory
        });

        test('filters out classes that do not implement FunctionInterface', function (): void {
            // Arrange - Directory contains NoInterface.php (valid class but no FunctionInterface)
            $methodsPath = realpath(__DIR__.'/../../Support/Fakes/InvalidMethods');
            $methodsNamespace = 'Tests\\Support\\Fakes\\InvalidMethods';

            Config::set('rpc.paths.functions', $methodsPath);
            Config::set('rpc.namespaces.functions', $methodsNamespace);

            $serverData = ServerData::from([
                'name' => 'test-no-interface',
                'path' => '/rpc',
                'route' => '/rpc',
                'version' => '1.0',
                'middleware' => [],
                'functions' => null,
            ]);

            // Act
            $server = new ConfigurationServer($serverData, Config::get('rpc.paths.functions', ''), Config::get('rpc.namespaces.functions', ''));
            $methods = $server->functions();

            // Assert - Line 144: in_array check should filter out classes without FunctionInterface
            expect($methods)->toBeArray();
            expect($methods)->toBeEmpty();
        });
    });
});
