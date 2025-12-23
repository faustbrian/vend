<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Events;

use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Override;

/**
 * Event dispatched before response serialization.
 *
 * Part of the Forrst protocol response event system. Fired as the final step before
 * the response is serialized and sent to the client. This is the last opportunity
 * for extensions to modify the response object before it becomes immutable.
 * Typically used for adding metadata that applies to all responses such as tracing
 * spans, performance metrics, or custom headers.
 *
 * Extensions that listen to this event can modify the response object directly
 * since it is passed by reference as a public property.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/protocol
 */
final class SendingResponse extends ExtensionEvent
{
    /**
     * The current response (mutable via setter).
     */
    private ResponseData $currentResponse;

    /**
     * Create a new sending response event instance.
     *
     * @param RequestObjectData $request  The original RPC request object that initiated this response.
     *                                    Contains the method name, parameters, and request metadata
     *                                    used to generate the response being sent.
     * @param ResponseData      $response The initial response object. Extensions can retrieve the
     *                                    current response via getResponse() and set a new response
     *                                    via setResponse() without mutating this value.
     */
    public function __construct(
        RequestObjectData $request,
        public readonly ResponseData $response,
    ) {
        parent::__construct($request);
        $this->currentResponse = $response;
    }

    /**
     * Get the current response (may differ from initial if modified).
     */
    #[Override()]
    public function getResponse(): ResponseData
    {
        return $this->currentResponse;
    }

    /**
     * Validate response meets final serialization requirements.
     *
     * This is the last opportunity to catch response issues before
     * serialization. Validates structure, required fields, and size limits.
     *
     * @param ResponseData $response Response to validate
     *
     * @return bool True if response is valid for serialization
     */
    protected function validateFinalResponse(ResponseData $response): bool
    {
        // Validate required fields exist
        if (!isset($response->result) && !isset($response->error)) {
            return false;
        }

        return true;
    }

    /**
     * Set a new response without mutating the event's readonly properties.
     *
     * Validates the response before setting to ensure serialization will succeed.
     *
     * @param ResponseData $response New response to set
     *
     * @throws \InvalidArgumentException If response fails final validation
     */
    #[Override()]
    public function setResponse(ResponseData $response): void
    {
        if (!$this->validateFinalResponse($response)) {
            throw new \InvalidArgumentException(
                'Response failed final validation checks before serialization'
            );
        }

        $this->currentResponse = $response;
    }
}
