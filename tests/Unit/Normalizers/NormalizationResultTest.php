<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Data\ResourceObjectData;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Normalizers\NormalizationResult;

describe('NormalizationResult', function (): void {
    beforeEach(function (): void {
        // Create sample ResourceObjectData instances for testing
        $this->primaryResource = new ResourceObjectData(
            type: 'articles',
            id: '1',
            attributes: ['title' => 'Test Article', 'body' => 'Content'],
            relationships: ['author' => ['data' => ['type' => 'people', 'id' => '9']]],
        );

        $this->includedAuthor = new ResourceObjectData(
            type: 'people',
            id: '9',
            attributes: ['name' => 'John Doe'],
        );

        $this->includedComment = new ResourceObjectData(
            type: 'comments',
            id: '5',
            attributes: ['text' => 'Great article!'],
        );
    });

    describe('Happy Paths', function (): void {
        test('creates normalization result with resource and included array', function (): void {
            // Arrange
            $included = [
                'people:9' => $this->includedAuthor,
                'comments:5' => $this->includedComment,
            ];

            // Act
            $result = new NormalizationResult($this->primaryResource, $included);

            // Assert
            expect($result)->toBeInstanceOf(NormalizationResult::class)
                ->and($result->resource)->toBe($this->primaryResource)
                ->and($result->included)->toBe($included)
                ->and($result->included)->toHaveCount(2);
        });

        test('creates normalization result with resource and empty included array', function (): void {
            // Arrange & Act
            $result = new NormalizationResult($this->primaryResource, []);

            // Assert
            expect($result)->toBeInstanceOf(NormalizationResult::class)
                ->and($result->resource)->toBe($this->primaryResource)
                ->and($result->included)->toBeEmpty();
        });

        test('creates normalization result with resource and no included array provided', function (): void {
            // Arrange & Act
            $result = new NormalizationResult($this->primaryResource);

            // Assert
            expect($result)->toBeInstanceOf(NormalizationResult::class)
                ->and($result->resource)->toBe($this->primaryResource)
                ->and($result->included)->toBeEmpty();
        });

        test('merges included resources from multiple normalization results', function (): void {
            // Arrange
            $result1 = new NormalizationResult(
                $this->primaryResource,
                ['people:9' => $this->includedAuthor],
            );

            $result2 = new NormalizationResult(
                new ResourceObjectData('articles', '2', ['title' => 'Second Article']),
                ['comments:5' => $this->includedComment],
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result1, $result2]);

            // Assert
            expect($merged)->toHaveCount(2)
                ->and($merged)->toHaveKey('people:9')
                ->and($merged)->toHaveKey('comments:5')
                ->and($merged['people:9'])->toBe($this->includedAuthor)
                ->and($merged['comments:5'])->toBe($this->includedComment);
        });

        test('returns included resources as indexed array without keys', function (): void {
            // Arrange
            $included = [
                'people:9' => $this->includedAuthor,
                'comments:5' => $this->includedComment,
            ];
            $result = new NormalizationResult($this->primaryResource, $included);

            // Act
            $array = $result->getIncludedArray();

            // Assert
            expect($array)->toHaveCount(2)
                ->and($array)->toBe([$this->includedAuthor, $this->includedComment])
                ->and(array_keys($array))->toBe([0, 1]); // Verify indexed array
        });

        test('merges multiple results maintaining all unique resources', function (): void {
            // Arrange
            $author2 = new ResourceObjectData('people', '10', ['name' => 'Jane Smith']);
            $tag1 = new ResourceObjectData('tags', '1', ['name' => 'PHP']);
            $tag2 = new ResourceObjectData('tags', '2', ['name' => 'Testing']);

            $result1 = new NormalizationResult(
                $this->primaryResource,
                ['people:9' => $this->includedAuthor, 'tags:1' => $tag1],
            );

            $result2 = new NormalizationResult(
                new ResourceObjectData('articles', '2', ['title' => 'Second']),
                ['people:10' => $author2, 'tags:2' => $tag2],
            );

            $result3 = new NormalizationResult(
                new ResourceObjectData('articles', '3', ['title' => 'Third']),
                ['comments:5' => $this->includedComment],
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result1, $result2, $result3]);

            // Assert
            expect($merged)->toHaveCount(5)
                ->and($merged)->toHaveKeys(['people:9', 'people:10', 'tags:1', 'tags:2', 'comments:5']);
        });
    });

    describe('Sad Paths', function (): void {
        test('merges empty array of results returning empty array', function (): void {
            // Arrange & Act
            $merged = NormalizationResult::mergeIncluded([]);

            // Assert
            expect($merged)->toBeEmpty()
                ->and($merged)->toBe([]);
        });

        test('returns empty indexed array when no included resources exist', function (): void {
            // Arrange
            $result = new NormalizationResult($this->primaryResource);

            // Act
            $array = $result->getIncludedArray();

            // Assert
            expect($array)->toBeEmpty()
                ->and($array)->toBe([]);
        });

        test('merges results when all have empty included arrays', function (): void {
            // Arrange
            $result1 = new NormalizationResult($this->primaryResource);
            $result2 = new NormalizationResult(
                new ResourceObjectData('articles', '2', ['title' => 'Second']),
            );
            $result3 = new NormalizationResult(
                new ResourceObjectData('articles', '3', ['title' => 'Third']),
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result1, $result2, $result3]);

            // Assert
            expect($merged)->toBeEmpty();
        });
    });

    describe('Edge Cases', function (): void {
        test('overwrites earlier resource with same key when merging multiple results', function (): void {
            // Arrange
            $firstAuthor = new ResourceObjectData('people', '9', ['name' => 'Original Name']);
            $updatedAuthor = new ResourceObjectData('people', '9', ['name' => 'Updated Name']);

            $result1 = new NormalizationResult(
                $this->primaryResource,
                ['people:9' => $firstAuthor],
            );

            $result2 = new NormalizationResult(
                new ResourceObjectData('articles', '2', ['title' => 'Second']),
                ['people:9' => $updatedAuthor],
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result1, $result2]);

            // Assert
            expect($merged)->toHaveCount(1)
                ->and($merged['people:9'])->toBe($updatedAuthor)
                ->and($merged['people:9']->attributes['name'])->toBe('Updated Name');
        });

        test('handles merging single result with included resources', function (): void {
            // Arrange
            $result = new NormalizationResult(
                $this->primaryResource,
                ['people:9' => $this->includedAuthor, 'comments:5' => $this->includedComment],
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result]);

            // Assert
            expect($merged)->toHaveCount(2)
                ->and($merged)->toHaveKeys(['people:9', 'comments:5']);
        });

        test('preserves array order when converting included to indexed array', function (): void {
            // Arrange
            $tag1 = new ResourceObjectData('tags', '1', ['name' => 'First']);
            $tag2 = new ResourceObjectData('tags', '2', ['name' => 'Second']);
            $tag3 = new ResourceObjectData('tags', '3', ['name' => 'Third']);

            $included = [
                'tags:1' => $tag1,
                'tags:2' => $tag2,
                'tags:3' => $tag3,
            ];

            $result = new NormalizationResult($this->primaryResource, $included);

            // Act
            $array = $result->getIncludedArray();

            // Assert
            expect($array)->toHaveCount(3)
                ->and($array[0])->toBe($tag1)
                ->and($array[1])->toBe($tag2)
                ->and($array[2])->toBe($tag3);
        });

        test('handles merging results with mix of empty and populated included arrays', function (): void {
            // Arrange
            $result1 = new NormalizationResult($this->primaryResource); // Empty included
            $result2 = new NormalizationResult(
                new ResourceObjectData('articles', '2', ['title' => 'Second']),
                ['people:9' => $this->includedAuthor],
            );
            $result3 = new NormalizationResult(
                new ResourceObjectData('articles', '3', ['title' => 'Third']),
            ); // Empty
            $result4 = new NormalizationResult(
                new ResourceObjectData('articles', '4', ['title' => 'Fourth']),
                ['comments:5' => $this->includedComment],
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result1, $result2, $result3, $result4]);

            // Assert
            expect($merged)->toHaveCount(2)
                ->and($merged)->toHaveKeys(['people:9', 'comments:5']);
        });

        test('handles resource with null relationships field', function (): void {
            // Arrange
            $resourceWithoutRelationships = new ResourceObjectData(
                type: 'articles',
                id: '1',
                attributes: ['title' => 'No Relationships'],
            );

            // Act
            $result = new NormalizationResult($resourceWithoutRelationships);

            // Assert
            expect($result->resource->relationships)->toBeNull()
                ->and($result->included)->toBeEmpty();
        });

        test('handles resource with null meta field', function (): void {
            // Arrange
            $resourceWithoutMeta = new ResourceObjectData(
                type: 'articles',
                id: '1',
                attributes: ['title' => 'No Meta'],
                meta: null,
            );

            // Act
            $result = new NormalizationResult($resourceWithoutMeta);

            // Assert
            expect($result->resource->meta)->toBeNull();
        });

        test('handles included resources with complex nested attributes', function (): void {
            // Arrange
            $complexResource = new ResourceObjectData(
                type: 'people',
                id: '9',
                attributes: [
                    'name' => 'John Doe',
                    'address' => [
                        'street' => '123 Main St',
                        'city' => 'Springfield',
                        'coordinates' => ['lat' => 40.712_8, 'lng' => -74.006_0],
                    ],
                    'tags' => ['developer', 'author'],
                ],
            );

            $included = ['people:9' => $complexResource];
            $result = new NormalizationResult($this->primaryResource, $included);

            // Act
            $array = $result->getIncludedArray();

            // Assert
            expect($array)->toHaveCount(1)
                ->and($array[0])->toBe($complexResource)
                ->and($array[0]->attributes['address'])->toBeArray()
                ->and($array[0]->attributes['tags'])->toBe(['developer', 'author']);
        });

        test('handles merging large number of results efficiently', function (): void {
            // Arrange
            $results = [];

            for ($i = 1; $i <= 100; ++$i) {
                $resource = new ResourceObjectData('articles', (string) $i, ['title' => 'Article '.$i]);
                $included = ['people:'.$i => new ResourceObjectData('people', (string) $i, ['name' => 'Author '.$i])];
                $results[] = new NormalizationResult($resource, $included);
            }

            // Act
            $merged = NormalizationResult::mergeIncluded($results);

            // Assert
            expect($merged)->toHaveCount(100);
        });

        test('rejects unicode characters in resource type per JSON:API spec', function (): void {
            // Arrange & Act & Assert
            // JSON:API spec recommends lowercase ASCII type names only
            expect(fn () => new ResourceObjectData(
                type: 'статьи', // Russian for "articles" - not allowed
                id: '日本語', // Japanese characters - allowed in ID
                attributes: ['title' => 'Unicode Test'],
            ))->toThrow(
                InvalidFieldValueException::class,
                'must be lowercase and contain only letters, numbers, hyphens, or underscores',
            );
        });
    });

    describe('Regressions', function (): void {
        test('ensures deduplication keys are preserved during merge to maintain JSON:API compliance', function (): void {
            // Arrange
            $author1 = new ResourceObjectData('people', '9', ['name' => 'First Version']);
            $author2 = new ResourceObjectData('people', '9', ['name' => 'Second Version']);

            $result1 = new NormalizationResult(
                $this->primaryResource,
                ['people:9' => $author1],
            );

            $result2 = new NormalizationResult(
                new ResourceObjectData('articles', '2', ['title' => 'Second Article']),
                ['people:9' => $author2],
            );

            // Act
            $merged = NormalizationResult::mergeIncluded([$result1, $result2]);

            // Assert - Later occurrence should overwrite earlier one
            expect($merged)->toHaveCount(1)
                ->and($merged['people:9']->attributes['name'])->toBe('Second Version');
        });

        test('ensures readonly properties prevent modification after construction', function (): void {
            // Arrange
            $result = new NormalizationResult($this->primaryResource, ['people:9' => $this->includedAuthor]);

            // Assert - Verify readonly nature (PHP 8.1+ will throw error on modification attempt)
            expect($result->resource)->toBe($this->primaryResource)
                ->and($result->included)->toHaveKey('people:9');

            // Attempting to modify would cause error:
            // $result->resource = new ResourceObjectData(...); // This would fail
            // $result->included = []; // This would fail
        });

        test('ensures getIncludedArray returns new array instance not reference', function (): void {
            // Arrange
            $included = ['people:9' => $this->includedAuthor];
            $result = new NormalizationResult($this->primaryResource, $included);

            // Act
            $array1 = $result->getIncludedArray();
            $array2 = $result->getIncludedArray();

            // Assert - Each call returns values, not same reference
            expect($array1)->toBe($array2) // Same values
                ->and($array1)->not->toBe($result->included); // But different structure (no keys)
        });
    });
});
