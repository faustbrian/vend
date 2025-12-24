<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Discovery\ServerExtensionDeclarationData;

describe('ServerExtensionDeclarationData', function (): void {
    describe('Happy Paths', function (): void {
        test('creates instance with required fields', function (): void {
            // Arrange & Act
            $declaration = new ServerExtensionDeclarationData(
                urn: 'urn:cline:forrst:ext:async',
                version: '1.0.0',
            );

            // Assert
            expect($declaration->urn)->toBe('urn:cline:forrst:ext:async')
                ->and($declaration->version)->toBe('1.0.0');
        });

        test('supports various URN formats', function (): void {
            // Arrange & Act
            $declaration = new ServerExtensionDeclarationData(
                urn: 'urn:cline:forrst:ext:caching',
                version: '2.1.0',
            );

            // Assert
            expect($declaration->urn)->toBe('urn:cline:forrst:ext:caching')
                ->and($declaration->version)->toBe('2.1.0');
        });

        test('supports prerelease versions', function (): void {
            // Arrange & Act
            $declaration = new ServerExtensionDeclarationData(
                urn: 'urn:cline:forrst:ext:experimental',
                version: '0.1.0-alpha.1',
            );

            // Assert
            expect($declaration->version)->toBe('0.1.0-alpha.1');
        });

        test('toArray includes all fields', function (): void {
            // Arrange
            $declaration = new ServerExtensionDeclarationData(
                urn: 'urn:cline:forrst:ext:batching',
                version: '1.2.3',
            );

            // Act
            $array = $declaration->toArray();

            // Assert
            expect($array)->toHaveKey('urn')
                ->and($array)->toHaveKey('version')
                ->and($array['urn'])->toBe('urn:cline:forrst:ext:batching')
                ->and($array['version'])->toBe('1.2.3');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles extension with complex URN namespace', function (): void {
            // Arrange & Act
            $declaration = new ServerExtensionDeclarationData(
                urn: 'urn:cline:forrst:ext:custom-feature',
                version: '1.0.0',
            );

            // Assert
            expect($declaration->urn)->toBe('urn:cline:forrst:ext:custom-feature');
        });

        test('handles version with build metadata', function (): void {
            // Arrange & Act
            $declaration = new ServerExtensionDeclarationData(
                urn: 'urn:cline:forrst:ext:build',
                version: '1.0.0+build.123',
            );

            // Assert
            expect($declaration->version)->toBe('1.0.0+build.123');
        });
    });
});
