<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\DeprecatedData;
use Cline\Forrst\Discovery\ErrorDefinitionData;
use Cline\Forrst\Discovery\ExampleData;
use Cline\Forrst\Discovery\ExternalDocsData;
use Cline\Forrst\Discovery\FunctionDescriptor;
use Cline\Forrst\Discovery\FunctionExtensionsData;
use Cline\Forrst\Discovery\LinkData;
use Cline\Forrst\Discovery\Query\QueryCapabilitiesData;
use Cline\Forrst\Discovery\ResultDescriptorData;
use Cline\Forrst\Discovery\SimulationScenarioData;
use Cline\Forrst\Discovery\TagData;
use Cline\Forrst\Enums\ErrorCode;

/**
 * @author Brian Faust <brian@cline.sh>
 */
enum TestFunctionUrn: string
{
    case TestFunction = 'urn:app:forrst:fn:test:function';
    case AnotherFunction = 'urn:app:forrst:fn:test:another';
}

/**
 * @author Brian Faust <brian@cline.sh>
 */
enum TestErrorCode: string
{
    case TestError = 'TEST_ERROR';
}

/**
 * @author Brian Faust <brian@cline.sh>
 */
enum TestTag: string
{
    case Testing = 'testing';
}

describe('FunctionDescriptor', function (): void {
    describe('Happy Paths', function (): void {
        test('creates instance with make()', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make();

            // Assert
            expect($descriptor)->toBeInstanceOf(FunctionDescriptor::class);
        });

        test('sets name with string', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:users:list')
                ->summary('List users');

            // Assert
            expect($descriptor->getUrn())->toBe('urn:app:forrst:fn:users:list');
        });

        test('sets urn with BackedEnum', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn(TestFunctionUrn::TestFunction)
                ->summary('Test function');

            // Assert
            expect($descriptor->getUrn())->toBe('urn:app:forrst:fn:test:function');
        });

        test('sets version', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->version('2.0.0');

            // Assert
            expect($descriptor->getVersion())->toBe('2.0.0');
        });

        test('defaults version to 1.0.0', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test');

            // Assert
            expect($descriptor->getVersion())->toBe('1.0.0');
        });

        test('sets summary', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('List all users');

            // Assert
            expect($descriptor->getSummary())->toBe('List all users');
        });

        test('sets description', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->description('Detailed description here');

            // Assert
            expect($descriptor->getDescription())->toBe('Detailed description here');
        });

        test('defaults to discoverable', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test');

            // Assert
            expect($descriptor->isDiscoverable())->toBeTrue();
        });

        test('sets hidden', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->hidden();

            // Assert
            expect($descriptor->isDiscoverable())->toBeFalse();
        });

        test('adds argument with fluent API', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->argument('user_id', schema: ['type' => 'string'], required: true);

            // Assert
            $arguments = $descriptor->getArguments();
            expect($arguments)->toHaveCount(1)
                ->and($arguments[0])->toBeInstanceOf(ArgumentData::class)
                ->and($arguments[0]->name)->toBe('user_id')
                ->and($arguments[0]->required)->toBeTrue();
        });

        test('adds multiple arguments', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->argument('user_id', required: true)
                ->argument('include', schema: ['type' => 'array']);

            // Assert
            expect($descriptor->getArguments())->toHaveCount(2);
        });

        test('adds pre-built ArgumentData', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'filter',
                schema: ['type' => 'object'],
                required: false,
            );

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->addArgument($argument);

            // Assert
            $arguments = $descriptor->getArguments();
            expect($arguments)->toHaveCount(1)
                ->and($arguments[0]->name)->toBe('filter');
        });

        test('sets result with schema', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->result(schema: ['type' => 'object']);

            // Assert
            $result = $descriptor->getResult();
            expect($result)->toBeInstanceOf(ResultDescriptorData::class)
                ->and($result->schema)->toBe(['type' => 'object']);
        });

        test('sets result with resource', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->resultResource('user', collection: true);

            // Assert
            $result = $descriptor->getResult();
            expect($result->resource)->toBe('user')
                ->and($result->collection)->toBeTrue();
        });

        test('adds error with string code', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->error('NOT_FOUND', 'Resource not found');

            // Assert
            $errors = $descriptor->getErrors();
            expect($errors)->toHaveCount(1)
                ->and($errors[0])->toBeInstanceOf(ErrorDefinitionData::class)
                ->and($errors[0]->code)->toBe('NOT_FOUND');
        });

        test('adds error with BackedEnum code', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->error(TestErrorCode::TestError, 'Test error occurred');

            // Assert
            $errors = $descriptor->getErrors();
            expect($errors[0]->code)->toBe('TEST_ERROR');
        });

        test('adds error with ErrorCode enum', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->error(ErrorCode::NotFound, 'Not found');

            // Assert
            $errors = $descriptor->getErrors();
            expect($errors[0]->code)->toBe(ErrorCode::NotFound->value);
        });

        test('adds tag with string', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->tag('users', summary: 'User management');

            // Assert
            $tags = $descriptor->getTags();
            expect($tags)->toHaveCount(1)
                ->and($tags[0])->toBeInstanceOf(TagData::class)
                ->and($tags[0]->name)->toBe('users');
        });

        test('adds tag with BackedEnum', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->tag(TestTag::Testing);

            // Assert
            $tags = $descriptor->getTags();
            expect($tags[0]->name)->toBe('testing');
        });

        test('sets query capabilities', function (): void {
            // Arrange
            $query = new QueryCapabilitiesData();

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->query($query);

            // Assert
            expect($descriptor->getQuery())->toBe($query);
        });

        test('sets deprecated', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->deprecated('Use v2 instead', '2025-12-31');

            // Assert
            $deprecated = $descriptor->getDeprecated();
            expect($deprecated)->toBeInstanceOf(DeprecatedData::class)
                ->and($deprecated->reason)->toBe('Use v2 instead')
                ->and($deprecated->sunset)->toBe('2025-12-31');
        });

        test('sets side effects', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->sideEffects(['create', 'update']);

            // Assert
            expect($descriptor->getSideEffects())->toBe(['create', 'update']);
        });

        test('adds example', function (): void {
            // Arrange
            $example = ExampleData::from([
                'name' => 'Basic example',
                'value' => ['id' => '123'],
            ]);

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->example($example);

            // Assert
            $examples = $descriptor->getExamples();
            expect($examples)->toHaveCount(1)
                ->and($examples[0])->toBe($example);
        });

        test('adds link', function (): void {
            // Arrange
            $link = LinkData::from([
                'name' => 'Get user',
                'function' => 'urn:app:forrst:fn:users:get',
            ]);

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->link($link);

            // Assert
            $links = $descriptor->getLinks();
            expect($links)->toHaveCount(1)
                ->and($links[0])->toBe($link);
        });

        test('sets external docs', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->externalDocs('https://docs.example.com', 'API Guide');

            // Assert
            $docs = $descriptor->getExternalDocs();
            expect($docs)->toBeInstanceOf(ExternalDocsData::class)
                ->and($docs->url)->toBe('https://docs.example.com')
                ->and($docs->description)->toBe('API Guide');
        });

        test('adds simulation', function (): void {
            // Arrange
            $simulation = new SimulationScenarioData(
                name: 'success',
                input: ['id' => '123'],
                output: ['status' => 'ok'],
                description: 'Successful operation',
            );

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->simulation($simulation);

            // Assert
            $simulations = $descriptor->getSimulations();
            expect($simulations)->toHaveCount(1);
        });

        test('sets extensions', function (): void {
            // Arrange
            $extensions = FunctionExtensionsData::from([
                'allowed' => ['urn:cline:forrst:ext:caching'],
            ]);

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->extensions($extensions);

            // Assert
            expect($descriptor->getExtensions())->toBe($extensions);
        });

        test('chains all methods fluently', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:users:create')
                ->version('1.0.0')
                ->summary('Create a new user')
                ->description('Creates a user account')
                ->argument('name', required: true)
                ->argument('email', required: true)
                ->result(schema: ['type' => 'object'])
                ->error('VALIDATION_ERROR', 'Invalid input')
                ->tag('users')
                ->sideEffects(['create'])
                ->externalDocs('https://docs.example.com');

            // Assert
            expect($descriptor->getUrn())->toBe('urn:app:forrst:fn:users:create')
                ->and($descriptor->getVersion())->toBe('1.0.0')
                ->and($descriptor->getSummary())->toBe('Create a new user')
                ->and($descriptor->getDescription())->toBe('Creates a user account')
                ->and($descriptor->getArguments())->toHaveCount(2)
                ->and($descriptor->getResult())->not->toBeNull()
                ->and($descriptor->getErrors())->toHaveCount(1)
                ->and($descriptor->getTags())->toHaveCount(1)
                ->and($descriptor->getSideEffects())->toBe(['create'])
                ->and($descriptor->getExternalDocs())->not->toBeNull();
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty arguments', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test');

            // Assert
            expect($descriptor->getArguments())->toBe([]);
        });

        test('handles empty errors', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test');

            // Assert
            expect($descriptor->getErrors())->toBe([]);
        });

        test('handles null optional fields', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test');

            // Assert
            expect($descriptor->getDescription())->toBeNull()
                ->and($descriptor->getResult())->toBeNull()
                ->and($descriptor->getTags())->toBeNull()
                ->and($descriptor->getQuery())->toBeNull()
                ->and($descriptor->getDeprecated())->toBeNull()
                ->and($descriptor->getSideEffects())->toBeNull()
                ->and($descriptor->getExamples())->toBeNull()
                ->and($descriptor->getLinks())->toBeNull()
                ->and($descriptor->getExternalDocs())->toBeNull()
                ->and($descriptor->getSimulations())->toBeNull()
                ->and($descriptor->getExtensions())->toBeNull();
        });

        test('argument with all parameters', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->argument(
                    name: 'filter',
                    schema: ['type' => 'object'],
                    required: false,
                    summary: 'Filter criteria',
                    description: 'Detailed filter description',
                    default: [],
                    deprecated: new DeprecatedData(reason: 'Use query instead'),
                    examples: [['status' => 'active']],
                );

            // Assert
            $arg = $descriptor->getArguments()[0];
            expect($arg->name)->toBe('filter')
                ->and($arg->required)->toBeFalse()
                ->and($arg->summary)->toBe('Filter criteria')
                ->and($arg->default)->toBe([])
                ->and($arg->deprecated)->not->toBeNull();
        });

        test('result with description', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->result(
                    schema: ['type' => 'object'],
                    collection: false,
                    description: 'The created resource',
                );

            // Assert
            $result = $descriptor->getResult();
            expect($result->description)->toBe('The created resource')
                ->and($result->collection)->toBeFalse();
        });

        test('error with all parameters', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->error(
                    code: 'VALIDATION_ERROR',
                    message: 'Validation failed',
                    description: 'One or more fields failed validation',
                    details: ['type' => 'object'],
                );

            // Assert
            $error = $descriptor->getErrors()[0];
            expect($error->code)->toBe('VALIDATION_ERROR')
                ->and($error->message)->toBe('Validation failed')
                ->and($error->description)->toBe('One or more fields failed validation')
                ->and($error->details)->toBe(['type' => 'object']);
        });

        test('multiple tags accumulate', function (): void {
            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->tag('users')
                ->tag('admin')
                ->tag('internal');

            // Assert
            expect($descriptor->getTags())->toHaveCount(3);
        });

        test('multiple examples accumulate', function (): void {
            // Arrange
            $example1 = ExampleData::from(['name' => 'Example 1']);
            $example2 = ExampleData::from(['name' => 'Example 2']);

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->example($example1)
                ->example($example2);

            // Assert
            expect($descriptor->getExamples())->toHaveCount(2);
        });

        test('multiple links accumulate', function (): void {
            // Arrange
            $link1 = LinkData::from(['name' => 'Link 1', 'function' => 'fn1']);
            $link2 = LinkData::from(['name' => 'Link 2', 'function' => 'fn2']);

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->link($link1)
                ->link($link2);

            // Assert
            expect($descriptor->getLinks())->toHaveCount(2);
        });

        test('multiple simulations accumulate', function (): void {
            // Arrange
            $sim1 = new SimulationScenarioData(name: 'success', input: ['id' => '1'], output: ['ok' => true]);
            $sim2 = new SimulationScenarioData(name: 'failure', input: ['id' => '0'], error: ['code' => 'NOT_FOUND']);

            // Act
            $descriptor = FunctionDescriptor::make()
                ->urn('urn:app:forrst:fn:test')
                ->summary('Test')
                ->simulation($sim1)
                ->simulation($sim2);

            // Assert
            expect($descriptor->getSimulations())->toHaveCount(2);
        });
    });
});
