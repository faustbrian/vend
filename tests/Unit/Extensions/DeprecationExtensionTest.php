<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Data\CallData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ProtocolData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Extensions\DeprecationExtension;
use Cline\Forrst\Extensions\ExtensionUrn;

describe('DeprecationExtension', function (): void {
    describe('Happy Paths', function (): void {
        test('returns correct URN constant', function (): void {
            // Arrange
            $extension = new DeprecationExtension();

            // Act
            $urn = $extension->getUrn();

            // Assert
            expect($urn)->toBe(ExtensionUrn::Deprecation->value);
        });

        test('registerWarning registers a deprecation warning', function (): void {
            // Arrange
            $extension = new DeprecationExtension();

            // Act
            $result = $extension->registerWarning(
                urn: 'deprecation:test.function',
                type: DeprecationExtension::TYPE_FUNCTION,
                target: 'urn:cline:forrst:fn:test:function',
                message: 'This function is deprecated',
            );

            // Assert
            expect($result)->toBe($extension);
        });

        test('registerWarning supports method chaining', function (): void {
            // Arrange
            $extension = new DeprecationExtension();

            // Act
            $result = $extension
                ->registerWarning(
                    urn: 'deprecation:func1',
                    type: DeprecationExtension::TYPE_FUNCTION,
                    target: 'func1',
                    message: 'Deprecated',
                )
                ->registerWarning(
                    urn: 'deprecation:func2',
                    type: DeprecationExtension::TYPE_FUNCTION,
                    target: 'func2',
                    message: 'Deprecated',
                );

            // Assert
            expect($result)->toBeInstanceOf(DeprecationExtension::class);
        });

        test('deprecateFunction creates function deprecation warning', function (): void {
            // Arrange
            $extension = new DeprecationExtension();

            // Act
            $result = $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:old:function',
                message: 'Use new.function instead',
                sunsetDate: '2025-12-31',
                replacementFn: 'new.function',
                replacementVer: '2',
            );

            // Assert
            expect($result)->toBe($extension);
        });

        test('deprecateVersion creates version deprecation warning', function (): void {
            // Arrange
            $extension = new DeprecationExtension();

            // Act
            $result = $extension->deprecateVersion(
                function: 'urn:cline:forrst:fn:test:function',
                version: '1',
                message: 'Version 1 is deprecated',
                sunsetDate: '2025-12-31',
                replacementVer: '2',
            );

            // Assert
            expect($result)->toBe($extension);
        });

        test('afterExecute adds deprecation warnings to response', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:test:function',
                message: 'This function is deprecated',
            );

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(
                    function: 'urn:cline:forrst:fn:test:function',
                    version: '1',
                ),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->urn)->toBe(ExtensionUrn::Deprecation->value)
                ->and($result->extensions[0]->data['warnings'])->toHaveCount(1)
                ->and($result->extensions[0]->data['warnings'][0]['target'])->toBe('urn:cline:forrst:fn:test:function');
        });

        test('afterExecute returns unmodified response when no warnings apply', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:other:function',
                message: 'Deprecated',
            );

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result)->toBe($response);
        });

        test('afterExecute filters acknowledged warnings', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:test:function',
                message: 'Deprecated',
            );

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deprecation->value,
                ['acknowledge' => ['deprecation:test.function']],
            );

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result)->toBe($response);
        });

        test('afterExecute includes all warning fields', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->registerWarning(
                urn: 'deprecation:test.function',
                type: DeprecationExtension::TYPE_FUNCTION,
                target: 'urn:cline:forrst:fn:test:function',
                message: 'This function is deprecated',
                sunsetDate: '2025-12-31',
                replacement: ['function' => 'new.function', 'version' => '2.0.0'],
                documentation: 'https://docs.example.com/migration',
            );

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            $warning = $result->extensions[0]->data['warnings'][0];
            expect($warning)->toHaveKey('urn')
                ->and($warning)->toHaveKey('type')
                ->and($warning)->toHaveKey('target')
                ->and($warning)->toHaveKey('message')
                ->and($warning)->toHaveKey('sunset_date')
                ->and($warning)->toHaveKey('replacement')
                ->and($warning)->toHaveKey('documentation')
                ->and($warning['sunset_date'])->toBe('2025-12-31')
                ->and($warning['replacement']['function'])->toBe('new.function')
                ->and($warning['documentation'])->toBe('https://docs.example.com/migration');
        });

        test('deprecateFunction includes replacement information', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:old:function',
                message: 'Use new.function instead',
                replacementFn: 'new.function',
                replacementVer: '2',
            );

            $request = RequestObjectData::asRequest('old.function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            $warning = $result->extensions[0]->data['warnings'][0];
            expect($warning['replacement']['function'])->toBe('new.function')
                ->and($warning['replacement']['version'])->toBe('2');
        });

        test('deprecateVersion applies to specific version', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateVersion(
                function: 'urn:cline:forrst:fn:test:function',
                version: '1',
                message: 'Version 1 is deprecated',
                replacementVer: '2',
            );

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(
                    function: 'urn:cline:forrst:fn:test:function',
                    version: '1',
                ),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->data['warnings'][0]['type'])->toBe(DeprecationExtension::TYPE_VERSION)
                ->and($result->extensions[0]->data['warnings'][0]['target'])->toBe('test.function@1');
        });

        test('afterExecute preserves response data', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction('urn:cline:forrst:fn:test:function', 'Deprecated');

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->id)->toBe($response->id)
                ->and($result->result)->toBe(['data' => 'test'])
                ->and($result->errors)->toBe($response->errors);
        });
    });

    describe('Edge Cases', function (): void {
        test('registerWarning without optional fields', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->registerWarning(
                urn: 'deprecation:minimal',
                type: DeprecationExtension::TYPE_FUNCTION,
                target: 'minimal.function',
                message: 'Minimal warning',
            );

            $request = RequestObjectData::asRequest('minimal.function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            $warning = $result->extensions[0]->data['warnings'][0];
            expect($warning)->not->toHaveKey('sunset_date')
                ->and($warning)->not->toHaveKey('replacement')
                ->and($warning)->not->toHaveKey('documentation');
        });

        test('deprecateFunction without replacement information', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:test:function',
                message: 'Deprecated without replacement',
            );

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            $warning = $result->extensions[0]->data['warnings'][0];
            expect($warning)->not->toHaveKey('replacement');
        });

        test('deprecateFunction with replacement function but no version', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction(
                function: 'urn:cline:forrst:fn:old:function',
                message: 'Deprecated',
                replacementFn: 'new.function',
            );

            $request = RequestObjectData::asRequest('old.function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            $warning = $result->extensions[0]->data['warnings'][0];
            expect($warning['replacement']['function'])->toBe('new.function')
                ->and($warning['replacement'])->not->toHaveKey('version');
        });

        test('deprecateVersion without replacement version', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateVersion(
                function: 'urn:cline:forrst:fn:test:function',
                version: '1',
                message: 'Version 1 is deprecated',
            );

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function', version: '1'),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            $warning = $result->extensions[0]->data['warnings'][0];
            expect($warning)->not->toHaveKey('replacement');
        });

        test('afterExecute with empty acknowledge array shows all warnings', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction('urn:cline:forrst:fn:test:function', 'Deprecated');

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deprecation->value,
                ['acknowledge' => []],
            );

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->data['warnings'])->toHaveCount(1);
        });

        test('afterExecute handles null acknowledge option', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction('urn:cline:forrst:fn:test:function', 'Deprecated');

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions)->toHaveCount(1);
        });

        test('afterExecute preserves existing extensions', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction('urn:cline:forrst:fn:test:function', 'Deprecated');

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $existingExtension = ExtensionData::response('urn:cline:forrst:ext:custom', ['key' => 'value']);
            $response = ResponseData::success(
                ['data' => 'test'],
                $request->id,
                extensions: [$existingExtension],
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions)->toHaveCount(2)
                ->and($result->extensions[0]->urn)->toBe('urn:cline:forrst:ext:custom')
                ->and($result->extensions[1]->urn)->toBe(ExtensionUrn::Deprecation->value);
        });

        test('deprecateVersion does not apply to different version', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateVersion(
                function: 'urn:cline:forrst:fn:test:function',
                version: '1',
                message: 'Version 1 is deprecated',
            );

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function', version: '2'),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result)->toBe($response);
        });

        test('multiple warnings for same function', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateFunction('urn:cline:forrst:fn:test:function', 'Function deprecated');
            $extension->deprecateVersion('urn:cline:forrst:fn:test:function', '1', 'Version deprecated');

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function', version: '1'),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->data['warnings'])->toHaveCount(2);
        });

        test('registerWarning overwrites existing warning with same URN', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->registerWarning(
                urn: 'deprecation:test',
                type: DeprecationExtension::TYPE_FUNCTION,
                target: 'urn:cline:forrst:fn:test:function',
                message: 'Original message',
            );
            $extension->registerWarning(
                urn: 'deprecation:test',
                type: DeprecationExtension::TYPE_FUNCTION,
                target: 'urn:cline:forrst:fn:test:function',
                message: 'Updated message',
            );

            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert
            expect($result->extensions[0]->data['warnings'])->toHaveCount(1)
                ->and($result->extensions[0]->data['warnings'][0]['message'])->toBe('Updated message');
        });

        test('supports all deprecation types', function (string $type): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->registerWarning(
                urn: 'deprecation:test:'.$type,
                type: $type,
                target: 'test.target',
                message: 'Deprecated '.$type,
            );

            $request = RequestObjectData::asRequest('test.target');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert - Warning should be present for function type only
            if ($type === DeprecationExtension::TYPE_FUNCTION) {
                expect($result->extensions)->toHaveCount(1);
            } else {
                expect($result)->toBe($response);
            }
        })->with([
            DeprecationExtension::TYPE_FUNCTION,
            DeprecationExtension::TYPE_VERSION,
            DeprecationExtension::TYPE_ARGUMENT,
            DeprecationExtension::TYPE_FIELD,
        ]);
    });

    describe('Sad Paths', function (): void {
        test('afterExecute handles request without version for version deprecation', function (): void {
            // Arrange
            $extension = new DeprecationExtension();
            $extension->deprecateVersion(
                function: 'urn:cline:forrst:fn:test:function',
                version: '1',
                message: 'Version 1 is deprecated',
            );

            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
            );
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deprecation->value, []);

            // Act
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);
            $result = $event->getResponse();

            // Assert - Should not match version deprecation
            expect($result)->toBe($response);
        });
    });
});
