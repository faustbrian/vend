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
use Cline\Forrst\Events\RequestValidated;
use Cline\Forrst\Extensions\ExtensionUrn;
use Cline\Forrst\Extensions\LocaleExtension;

describe('LocaleExtension', function (): void {
    describe('Happy Paths', function (): void {
        test('getUrn returns correct URN constant', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act
            $urn = $extension->getUrn();

            // Assert
            expect($urn)->toBe(ExtensionUrn::Locale->value)
                ->and($urn)->toBe('urn:cline:forrst:ext:locale');
        });

        test('isErrorFatal returns false for graceful degradation', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act
            $isFatal = $extension->isErrorFatal();

            // Assert
            expect($isFatal)->toBeFalse();
        });

        test('getSupportedLanguages returns configured languages', function (): void {
            // Arrange
            $extension = new LocaleExtension(['en', 'de', 'fr']);

            // Act
            $languages = $extension->getSupportedLanguages();

            // Assert
            expect($languages)->toHaveCount(3)
                ->and($languages)->toContain('en')
                ->and($languages)->toContain('de')
                ->and($languages)->toContain('fr');
        });

        test('default supported language is en', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act
            $languages = $extension->getSupportedLanguages();

            // Assert
            expect($languages)->toHaveCount(1)
                ->and($languages[0])->toBe('en');
        });

        test('isValidLanguageTag validates known language codes', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act & Assert
            expect($extension->isValidLanguageTag('en'))->toBeTrue();
            expect($extension->isValidLanguageTag('de'))->toBeTrue();
            expect($extension->isValidLanguageTag('zh'))->toBeTrue();
            expect($extension->isValidLanguageTag('ja'))->toBeTrue();
        });

        test('isValidLanguageTag validates language tags with region', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act & Assert
            expect($extension->isValidLanguageTag('en-US'))->toBeTrue();
            expect($extension->isValidLanguageTag('zh-Hans'))->toBeTrue();
            expect($extension->isValidLanguageTag('de-DE'))->toBeTrue();
        });

        test('toCapabilities includes supported languages', function (): void {
            // Arrange
            $extension = new LocaleExtension(['en', 'de', 'fr', 'es']);

            // Act
            $capabilities = $extension->toCapabilities();

            // Assert
            expect($capabilities)->toHaveKey('urn', ExtensionUrn::Locale->value)
                ->and($capabilities)->toHaveKey('supported_languages')
                ->and($capabilities['supported_languages'])->toContain('en')
                ->and($capabilities)->toHaveKey('default_language', 'en');
        });

        test('getSubscribedEvents returns RequestValidated and FunctionExecuted', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act
            $events = $extension->getSubscribedEvents();

            // Assert
            expect($events)->toHaveKey(RequestValidated::class)
                ->and($events)->toHaveKey(FunctionExecuted::class);
        });
    });

    describe('Edge Cases', function (): void {
        test('isValidLanguageTag returns false for invalid language codes', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act & Assert
            expect($extension->isValidLanguageTag('xyz'))->toBeFalse();
            expect($extension->isValidLanguageTag('invalidlang'))->toBeFalse();
        });

        test('isValidLanguageTag returns false for empty string', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act & Assert
            expect($extension->isValidLanguageTag(''))->toBeFalse();
        });

        test('empty supported languages array still works', function (): void {
            // Arrange
            $extension = new LocaleExtension([]);

            // Act
            $languages = $extension->getSupportedLanguages();

            // Assert
            expect($languages)->toBeEmpty();
        });

        test('default language constant is en', function (): void {
            // Assert
            expect(LocaleExtension::DEFAULT_LANGUAGE)->toBe('en');
        });

        test('default timezone constant is UTC', function (): void {
            // Assert
            expect(LocaleExtension::DEFAULT_TIMEZONE)->toBe('UTC');
        });

    });

    describe('Sad Paths', function (): void {
        test('isValidLanguageTag handles null-like values gracefully', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act & Assert - Should not throw
            expect($extension->isValidLanguageTag('0'))->toBeFalse();
        });

        test('isValidLanguageTag handles special characters', function (): void {
            // Arrange
            $extension = new LocaleExtension();

            // Act & Assert
            expect($extension->isValidLanguageTag('en@!#'))->toBeFalse();
        });

        test('supported languages are case sensitive', function (): void {
            // Arrange
            $extension = new LocaleExtension(['en', 'de']);

            // Act
            $languages = $extension->getSupportedLanguages();

            // Assert - Only lowercase stored
            expect($languages)->not->toContain('EN')
                ->and($languages)->toContain('en');
        });
    });

    describe('Request/Response Handling', function (): void {
        describe('Happy Paths', function (): void {
            test('onRequestValidated resolves locale from extension options', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de', 'fr']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'de',
                            'timezone' => 'Europe/Berlin',
                            'currency' => 'EUR',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('de')
                    ->and($event->request->meta['locale_resolved']['timezone'])->toBe('Europe/Berlin')
                    ->and($event->request->meta['locale_resolved']['currency'])->toBe('EUR')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeFalse();
            });

            test('onRequestValidated handles exact language match without fallback', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'zh-Hans', 'fr']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'zh-Hans',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('zh-Hans')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeFalse();
            });

            test('onRequestValidated does nothing when extension not present', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert - locale_resolved not set when extension not present
                expect($event->request->meta)->not->toHaveKey('locale_resolved');
            });

            test('onFunctionExecuted adds locale metadata to response', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de', 'fr']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'de',
                            'timezone' => 'Europe/Berlin',
                            'currency' => 'EUR',
                        ]),
                    ],
                );
                $response = ResponseData::success(['data' => 'test'], $request->id);

                // Set up locale first
                $validatedEvent = new RequestValidated($request);
                $extension->onRequestValidated($validatedEvent);

                // Act
                $executedEvent = new FunctionExecuted(
                    $request,
                    $request->extensions[0],
                    $response,
                );
                $extension->onFunctionExecuted($executedEvent);

                // Assert
                $result = $executedEvent->getResponse();
                expect($result->extensions)->toHaveCount(1)
                    ->and($result->extensions[0]->urn)->toBe(ExtensionUrn::Locale->value)
                    ->and($result->extensions[0]->data['language'])->toBe('de')
                    ->and($result->extensions[0]->data['fallback_used'])->toBe(false)
                    ->and($result->extensions[0]->data['timezone'])->toBe('Europe/Berlin')
                    ->and($result->extensions[0]->data['currency'])->toBe('EUR');
            });

            test('onFunctionExecuted preserves response data and errors', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-456',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                        ]),
                    ],
                );
                $response = ResponseData::success(['result' => 'success'], $request->id);

                // Set up locale first
                $validatedEvent = new RequestValidated($request);
                $extension->onRequestValidated($validatedEvent);

                // Act
                $executedEvent = new FunctionExecuted(
                    $request,
                    $request->extensions[0],
                    $response,
                );
                $extension->onFunctionExecuted($executedEvent);

                // Assert
                $result = $executedEvent->getResponse();
                expect($result->id)->toBe('req-456')
                    ->and($result->result)->toBe(['result' => 'success'])
                    ->and($result->errors)->toBeNull();
            });

            test('onFunctionExecuted preserves existing extensions in response', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-789',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                        ]),
                    ],
                );
                $existingExtension = ExtensionData::response('urn:cline:forrst:ext:custom', ['key' => 'value']);
                $response = ResponseData::success(
                    ['data' => 'test'],
                    $request->id,
                    extensions: [$existingExtension],
                );

                // Set up locale first
                $validatedEvent = new RequestValidated($request);
                $extension->onRequestValidated($validatedEvent);

                // Act
                $executedEvent = new FunctionExecuted(
                    $request,
                    $request->extensions[0],
                    $response,
                );
                $extension->onFunctionExecuted($executedEvent);

                // Assert
                $result = $executedEvent->getResponse();
                expect($result->extensions)->toHaveCount(2)
                    ->and($result->extensions[0]->urn)->toBe('urn:cline:forrst:ext:custom')
                    ->and($result->extensions[1]->urn)->toBe(ExtensionUrn::Locale->value);
            });

            test('onFunctionExecuted filters out null values from response metadata', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-999',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                        ]),
                    ],
                );
                $response = ResponseData::success(['data' => 'test'], $request->id);

                // Set up locale first (no timezone/currency)
                $validatedEvent = new RequestValidated($request);
                $extension->onRequestValidated($validatedEvent);

                // Act
                $executedEvent = new FunctionExecuted(
                    $request,
                    $request->extensions[0],
                    $response,
                );
                $extension->onFunctionExecuted($executedEvent);

                // Assert
                $result = $executedEvent->getResponse();
                expect($result->extensions[0]->data)->toHaveKey('language')
                    ->and($result->extensions[0]->data)->toHaveKey('fallback_used')
                    ->and($result->extensions[0]->data)->not->toHaveKey('timezone')
                    ->and($result->extensions[0]->data)->not->toHaveKey('currency');
            });
        });

        describe('Sad Paths', function (): void {
            test('onRequestValidated handles missing language option by using default', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'timezone' => 'Europe/Berlin',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('en')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('onRequestValidated rejects invalid timezone', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => 'Invalid/Timezone',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['timezone'])->toBeNull();
            });

            test('onRequestValidated rejects invalid currency code', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'INVALID',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('onRequestValidated normalizes currency to uppercase', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'usd',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBe('USD');
            });
        });

        describe('Edge Cases', function (): void {
            test('onRequestValidated handles empty extension options', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, []),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('en')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue()
                    ->and($event->request->meta['locale_resolved']['timezone'])->toBeNull()
                    ->and($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('onRequestValidated handles null extension options', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('en')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('onRequestValidated accepts null for optional timezone', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => null,
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['timezone'])->toBeNull();
            });

            test('onRequestValidated accepts null for optional currency', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => null,
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });
        });
    });

    describe('Language Resolution and Fallback', function (): void {
        describe('Happy Paths', function (): void {
            test('resolves exact language match without fallback', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de', 'fr']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'de',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('de')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeFalse();
            });

            test('resolves base language when specific variant not supported', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'de-DE',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert - base language 'de' matches, so 'de-DE' is accepted as-is
                expect($event->request->meta['locale_resolved']['language'])->toBe('de-DE')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeFalse();
            });

            test('resolves progressively shorter language tags', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'zh-Hans']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'zh-Hans-CN',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('zh-Hans')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('uses first fallback language when requested language not supported', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'fr', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'ja',
                            'fallback' => ['fr', 'de'],
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('fr')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('tries multiple fallback languages until match found', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'es']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'ja',
                            'fallback' => ['zh', 'fr', 'es'],
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('es')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('uses shorter version of fallback language when exact not supported', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'ja',
                            'fallback' => ['de-DE'],
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert - base language 'de' matches, so fallback 'de-DE' is used as-is
                expect($event->request->meta['locale_resolved']['language'])->toBe('de-DE')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('defaults to en when no languages match', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'ja',
                            'fallback' => ['zh', 'ko'],
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('en')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });
        });

        describe('Edge Cases', function (): void {
            test('handles empty fallback array', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'ja',
                            'fallback' => [],
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('en')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('handles single-character language code', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'x',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['language'])->toBe('en')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeTrue();
            });

            test('handles language tag with multiple hyphens', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en', 'de']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'de-DE-u-co-phonebk',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert - base language 'de' matches, so full tag is accepted as-is
                expect($event->request->meta['locale_resolved']['language'])->toBe('de-DE-u-co-phonebk')
                    ->and($event->request->meta['locale_resolved']['fallback_used'])->toBeFalse();
            });
        });
    });

    describe('Timezone Validation', function (): void {
        describe('Happy Paths', function (): void {
            test('validates common IANA timezone identifiers', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);

                // Act & Assert
                $validTimezones = [
                    'America/New_York',
                    'Europe/London',
                    'Asia/Tokyo',
                    'Australia/Sydney',
                    'UTC',
                ];

                foreach ($validTimezones as $timezone) {
                    $request = new RequestObjectData(
                        protocol: ProtocolData::forrst(),
                        id: 'req-123',
                        call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                        extensions: [
                            ExtensionData::request(ExtensionUrn::Locale->value, [
                                'language' => 'en',
                                'timezone' => $timezone,
                            ]),
                        ],
                    );

                    $event = new RequestValidated($request);
                    $extension->onRequestValidated($event);

                    expect($event->request->meta['locale_resolved']['timezone'])->toBe($timezone);
                }
            });

            test('validates timezone with offset format', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => 'Etc/GMT+5',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['timezone'])->toBe('Etc/GMT+5');
            });
        });

        describe('Sad Paths', function (): void {
            test('rejects completely invalid timezone identifier', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => 'NotATimezone',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['timezone'])->toBeNull();
            });

            test('rejects empty timezone string', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => '',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['timezone'])->toBeNull();
            });

            test('rejects malformed timezone with special characters', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => 'America/New@York!',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['timezone'])->toBeNull();
            });
        });

        describe('Edge Cases', function (): void {
            test('handles deprecated timezone identifiers', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'timezone' => 'US/Eastern',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert - Deprecated but still valid
                expect($event->request->meta['locale_resolved']['timezone'])->toBe('US/Eastern');
            });
        });
    });

    describe('Currency Validation', function (): void {
        describe('Happy Paths', function (): void {
            test('validates common ISO 4217 currency codes', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);

                // Act & Assert
                $validCurrencies = [
                    'USD' => 'USD',
                    'EUR' => 'EUR',
                    'GBP' => 'GBP',
                    'JPY' => 'JPY',
                    'CNY' => 'CNY',
                ];

                foreach ($validCurrencies as $input => $expected) {
                    $request = new RequestObjectData(
                        protocol: ProtocolData::forrst(),
                        id: 'req-123',
                        call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                        extensions: [
                            ExtensionData::request(ExtensionUrn::Locale->value, [
                                'language' => 'en',
                                'currency' => $input,
                            ]),
                        ],
                    );

                    $event = new RequestValidated($request);
                    $extension->onRequestValidated($event);

                    expect($event->request->meta['locale_resolved']['currency'])->toBe($expected);
                }
            });

            test('normalizes lowercase currency codes to uppercase', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'eur',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBe('EUR');
            });

            test('normalizes mixed-case currency codes to uppercase', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'GbP',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBe('GBP');
            });
        });

        describe('Sad Paths', function (): void {
            test('rejects invalid currency codes', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'INVALID',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('rejects currency codes that are too short', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'US',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('rejects currency codes that are too long', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'USDD',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('rejects empty currency string', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => '',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('rejects currency codes with numbers', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'US1',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });

            test('rejects currency codes with special characters', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'US$',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBeNull();
            });
        });

        describe('Edge Cases', function (): void {
            test('validates less common but valid currency codes', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'XAF',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert
                expect($event->request->meta['locale_resolved']['currency'])->toBe('XAF');
            });

            test('validates Swiss Franc currency code', function (): void {
                // Arrange
                $extension = new LocaleExtension(['en']);
                $request = new RequestObjectData(
                    protocol: ProtocolData::forrst(),
                    id: 'req-123',
                    call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
                    extensions: [
                        ExtensionData::request(ExtensionUrn::Locale->value, [
                            'language' => 'en',
                            'currency' => 'CHF',
                        ]),
                    ],
                );

                // Act
                $event = new RequestValidated($request);
                $extension->onRequestValidated($event);

                // Assert - CHF is valid ISO 4217 code for Swiss Franc
                expect($event->request->meta['locale_resolved']['currency'])->toBe('CHF');
            });
        });
    });
});
