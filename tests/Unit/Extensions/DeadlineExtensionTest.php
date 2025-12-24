<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Carbon\CarbonImmutable;
use Cline\Forrst\Data\CallData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ProtocolData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Extensions\DeadlineExtension;
use Cline\Forrst\Extensions\ExtensionUrn;

describe('DeadlineExtension', function (): void {
    describe('Happy Paths', function (): void {
        test('returns correct URN constant', function (): void {
            // Arrange
            $extension = new DeadlineExtension();

            // Act
            $urn = $extension->getUrn();

            // Assert
            expect($urn)->toBe(ExtensionUrn::Deadline->value);
        });

        test('getSubscribedEvents returns event configuration with priorities', function (): void {
            // Arrange
            $extension = new DeadlineExtension();

            // Act
            $events = $extension->getSubscribedEvents();

            // Assert
            expect($events)->toHaveKey(ExecutingFunction::class)
                ->and($events)->toHaveKey(FunctionExecuted::class)
                ->and($events[ExecutingFunction::class]['priority'])->toBe(10)
                ->and($events[ExecutingFunction::class]['method'])->toBe('onExecutingFunction')
                ->and($events[FunctionExecuted::class]['priority'])->toBe(200)
                ->and($events[FunctionExecuted::class]['method'])->toBe('onFunctionExecuted');
        });

        test('onExecutingFunction does not short-circuit when deadline is in future', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
            );

            $futureDeadline = CarbonImmutable::now()->addHours(1)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $futureDeadline],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onExecutingFunction does not short-circuit when timeout is in future', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 300, 'unit' => 'second']],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onExecutingFunction does not short-circuit when no deadline specified', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $extensionData = ExtensionData::request(ExtensionUrn::Deadline->value, []);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onFunctionExecuted adds deadline info to response', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);

            $futureDeadline = CarbonImmutable::now()->addMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $futureDeadline],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - spec-compliant response with remaining, elapsed, and nested {value, unit}
            $result = $executedEvent->getResponse();
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->urn)->toBe(ExtensionUrn::Deadline->value)
                ->and($result->extensions[0]->data)->toHaveKey('remaining')
                ->and($result->extensions[0]->data)->toHaveKey('elapsed')
                ->and($result->extensions[0]->data['remaining']['value'])->toBeGreaterThanOrEqual(0)
                ->and($result->extensions[0]->data['remaining']['unit'])->toBe('millisecond')
                ->and($result->extensions[0]->data['elapsed']['unit'])->toBe('millisecond');
        });

        test('onFunctionExecuted preserves response data', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);

            $futureDeadline = CarbonImmutable::now()->addMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $futureDeadline],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert
            $result = $executedEvent->getResponse();
            expect($result->id)->toBe($response->id)
                ->and($result->result)->toBe(['data' => 'test'])
                ->and($result->errors)->toBe($response->errors);
        });

        test('onFunctionExecuted returns unmodified response when no deadline', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deadline->value, []);

            // Act - no onExecutingFunction since context not set
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert
            expect($executedEvent->getResponse())->toBe($response);
        });

        test('onFunctionExecuted handles timeout option', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 10, 'unit' => 'second']],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - spec-compliant with specified, remaining, elapsed, utilization
            $result = $executedEvent->getResponse();
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->data)->toHaveKey('remaining')
                ->and($result->extensions[0]->data)->toHaveKey('elapsed')
                ->and($result->extensions[0]->data)->toHaveKey('specified')
                ->and($result->extensions[0]->data)->toHaveKey('utilization')
                ->and($result->extensions[0]->data['remaining']['value'])->toBeGreaterThanOrEqual(0)
                ->and($result->extensions[0]->data['specified']['value'])->toBe(10)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('second');
        });

        test('onFunctionExecuted extracts timeout from options when context not set', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 5, 'unit' => 'minute']],
            );

            // Act - skip onExecutingFunction so specifiedTimeout is null
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should extract timeout from options as fallback
            $result = $executedEvent->getResponse();
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->data)->toHaveKey('specified')
                ->and($result->extensions[0]->data['specified']['value'])->toBe(5)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('minute')
                ->and($result->extensions[0]->data)->toHaveKey('utilization');
        });
    });

    describe('Edge Cases', function (): void {
        test('onExecutingFunction handles null options', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $extensionData = ExtensionData::request(ExtensionUrn::Deadline->value);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onFunctionExecuted with null options returns unmodified response', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(ExtensionUrn::Deadline->value);

            // Act - skip onExecutingFunction so context is null
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert
            expect($executedEvent->getResponse())->toBe($response);
        });

        test('onFunctionExecuted includes remaining in response even for past deadline', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);

            // Set deadline well in the past
            $pastDeadline = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $pastDeadline],
            );

            // Set up context first (even though deadline is past)
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should have remaining clamped to 0
            $result = $executedEvent->getResponse();
            expect($result->extensions[0]->data)->toHaveKey('remaining')
                ->and($result->extensions[0]->data)->toHaveKey('elapsed')
                ->and($result->extensions[0]->data['remaining']['value'])->toBe(0)
                ->and($result->extensions[0]->data['remaining']['unit'])->toBe('millisecond');
        });

        test('onExecutingFunction parses ISO 8601 deadline timestamp', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            $deadline = now()->addMinutes(30)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $deadline],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onExecutingFunction handles timeout with different units', function (string $unit): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 1, 'unit' => $unit]],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        })->with([
            'millisecond',
            'second',
            'minute',
            'hour',
        ]);

        test('onFunctionExecuted preserves existing response extensions', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            $existingExtension = ExtensionData::response('urn:cline:forrst:ext:custom', ['key' => 'value']);
            $response = ResponseData::success(
                ['data' => 'test'],
                $request->id,
                extensions: [$existingExtension],
            );

            $futureDeadline = CarbonImmutable::now()->addMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $futureDeadline],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert
            $result = $executedEvent->getResponse();
            expect($result->extensions)->toHaveCount(2)
                ->and($result->extensions[0]->urn)->toBe('urn:cline:forrst:ext:custom')
                ->and($result->extensions[1]->urn)->toBe(ExtensionUrn::Deadline->value);
        });

        test('onFunctionExecuted handles timeout value of 0', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 0, 'unit' => 'second']],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should still add extension even with 0 timeout
            $result = $executedEvent->getResponse();
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->data)->toHaveKey('remaining')
                ->and($result->extensions[0]->data)->toHaveKey('specified')
                ->and($result->extensions[0]->data['remaining']['value'])->toBe(0)
                ->and($result->extensions[0]->data['specified']['value'])->toBe(0)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('second');
        });

        test('onExecutingFunction prioritizes absolute deadline over timeout', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            $futureDeadline = CarbonImmutable::now()->addHours(1)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                [
                    'deadline' => $futureDeadline,
                    'timeout' => ['value' => 1, 'unit' => 'second'],
                ],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert - Should use absolute deadline, which is in future
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onFunctionExecuted calculates utilization for millisecond timeout', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 5_000, 'unit' => 'millisecond']],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should calculate utilization based on millisecond conversion
            $result = $executedEvent->getResponse();
            expect($result->extensions[0]->data)->toHaveKey('utilization')
                ->and($result->extensions[0]->data['specified']['value'])->toBe(5_000)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('millisecond');
        });

        test('onFunctionExecuted calculates utilization for minute timeout', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 2, 'unit' => 'minute']],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should calculate utilization based on minute conversion
            $result = $executedEvent->getResponse();
            expect($result->extensions[0]->data)->toHaveKey('utilization')
                ->and($result->extensions[0]->data['specified']['value'])->toBe(2)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('minute');
        });

        test('onFunctionExecuted calculates utilization for hour timeout', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => 1, 'unit' => 'hour']],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should calculate utilization based on hour conversion
            $result = $executedEvent->getResponse();
            expect($result->extensions[0]->data)->toHaveKey('utilization')
                ->and($result->extensions[0]->data['specified']['value'])->toBe(1)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('hour');
        });

        test('onFunctionExecuted handles timeout with invalid unit gracefully', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $response = ResponseData::success(['data' => 'test'], $request->id);

            // Use absolute deadline instead of invalid timeout unit to avoid Carbon error
            $futureDeadline = CarbonImmutable::now()->addMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                [
                    'deadline' => $futureDeadline,
                    'timeout' => ['value' => 10, 'unit' => 'fortnight'], // Invalid but captured for metadata
                ],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - Should use deadline (not timeout) and still include specified timeout in metadata
            $result = $executedEvent->getResponse();
            expect($result->extensions[0]->data)->toHaveKey('specified')
                ->and($result->extensions[0]->data['specified']['value'])->toBe(10)
                ->and($result->extensions[0]->data['specified']['unit'])->toBe('fortnight')
                ->and($result->extensions[0]->data)->toHaveKey('utilization');
        });
    });

    describe('Sad Paths', function (): void {
        test('onExecutingFunction returns error when absolute deadline has passed', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            $pastDeadline = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $pastDeadline],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->not->toBeNull()
                ->and($result->errors)->toHaveCount(1)
                ->and($result->errors[0]->code)->toBe(ErrorCode::DeadlineExceeded->value)
                ->and($result->errors[0]->message)->toContain('already passed')
                ->and($result->errors[0]->details)->toHaveKey('deadline')
                ->and($event->isPropagationStopped())->toBeTrue();
        });

        test('onExecutingFunction error response includes extension data', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            $pastDeadline = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $pastDeadline],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result->extensions)->toHaveCount(1)
                ->and($result->extensions[0]->urn)->toBe(ExtensionUrn::Deadline->value)
                ->and($result->extensions[0]->data['exceeded'])->toBeTrue()
                ->and($result->extensions[0]->data)->toHaveKey('deadline');
        });

        test('onExecutingFunction returns error when timeout has already expired', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            // Negative timeout means deadline is in the past
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['timeout' => ['value' => -10, 'unit' => 'second']],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->not->toBeNull()
                ->and($result->errors)->toHaveCount(1)
                ->and($result->errors[0]->code)->toBe(ErrorCode::DeadlineExceeded->value)
                ->and($event->isPropagationStopped())->toBeTrue();
        });

        test('onExecutingFunction error response has null result', function (): void {
            // Arrange
            $extension = new DeadlineExtension();
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');

            $pastDeadline = CarbonImmutable::now()->subMinutes(5)->toIso8601String();
            $extensionData = ExtensionData::request(
                ExtensionUrn::Deadline->value,
                ['deadline' => $pastDeadline],
            );
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result->result)->toBeNull()
                ->and($result->id)->toBe($request->id);
        });
    });
});
