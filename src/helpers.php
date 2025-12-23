<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst;

use Cline\Forrst\Data\ProtocolData;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

use function array_filter;
use function function_exists;
use function Pest\Laravel\postJson;
use function route;

if (!function_exists('post_forrst') && function_exists('Pest\Laravel\postJson')) {
    /**
     * Helper function for testing Forrst requests in Pest tests.
     *
     * Sends a Forrst request to the default RPC endpoint with proper protocol formatting.
     * Automatically includes the required protocol version field and provides a default
     * ULID request ID for testing. Only available when Pest Laravel is installed.
     *
     * This helper simplifies testing by handling Forrst request envelope construction,
     * allowing tests to focus on function arguments and response assertions.
     *
     * ```php
     * // Simple function call without arguments
     * post_forrst('urn:acme:forrst:fn:users:list')
     *     ->assertOk()
     *     ->assertJsonStructure(['result']);
     *
     * // Function call with arguments
     * post_forrst('urn:acme:forrst:fn:users:get', ['id' => 1])
     *     ->assertOk()
     *     ->assertJsonPath('result.id', 1);
     *
     * // Function call with version and custom request ID
     * post_forrst('urn:acme:forrst:fn:users:create', ['name' => 'John'], '1.0', 'custom-id-123')
     *     ->assertOk()
     *     ->assertJsonPath('id', 'custom-id-123');
     * ```
     *
     * @see https://docs.cline.sh/forrst/
     * @param  string                    $function  The function URN to invoke
     *                                              (e.g., "urn:acme:forrst:fn:users:list")
     * @param  null|array<string, mixed> $arguments Optional associative array of arguments
     *                                              to pass to the function
     * @param  null|string               $version   Optional semantic version string to specify
     *                                              which function version to invoke
     * @param  null|string               $id        Optional custom request ID for tracking requests,
     *                                              defaults to a ULID if not provided
     * @param  string                    $routeName Optional route name to post to, defaults to 'rpc'
     * @return TestResponse<Response>    Laravel test response for fluent assertion chaining
     */
    function post_forrst(
        string $function,
        ?array $arguments = null,
        ?string $version = null,
        ?string $id = null,
        string $routeName = 'rpc',
    ): TestResponse {
        if ($id === null) {
            $id = (string) \Illuminate\Support\Str::ulid();
        }

        return postJson(
            route($routeName),
            array_filter([
                'protocol' => ProtocolData::forrst()->toArray(),
                'id' => $id,
                'call' => array_filter([
                    'function' => $function,
                    'version' => $version,
                    'arguments' => $arguments,
                ]),
            ]),
        );
    }
}
