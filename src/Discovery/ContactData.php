<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * Contact information for the API service or team.
 *
 * Provides contact details for the individuals or teams responsible for the API,
 * enabling API consumers to reach out for support, questions, or collaboration.
 * Typically included in discovery documents to facilitate communication.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 */
final class ContactData extends Data
{
    /**
     * Create contact data with normalized inputs.
     *
     * Normalizes inputs before construction:
     * - Trims whitespace from name and email
     * - Converts email to lowercase (case-insensitive per RFC 5321)
     * - Removes trailing slashes from URLs
     *
     * @param null|string $name  Contact name
     * @param null|string $url   Contact URL
     * @param null|string $email Contact email
     *
     * @return self
     */
    public static function create(
        ?string $name = null,
        ?string $url = null,
        ?string $email = null,
    ): self {
        return new self(
            name: $name !== null ? trim($name) : null,
            url: $url !== null ? rtrim($url, '/') : null,
            email: $email !== null ? strtolower(trim($email)) : null,
        );
    }

    /**
     * Create contact with email only.
     *
     * @param string $email Contact email address
     *
     * @return self
     */
    public static function email(string $email): self
    {
        return self::create(email: $email);
    }

    /**
     * Create contact with name and email.
     *
     * @param string $name  Contact name
     * @param string $email Contact email address
     *
     * @return self
     */
    public static function person(string $name, string $email): self
    {
        return self::create(name: $name, email: $email);
    }

    /**
     * Create contact with all information.
     *
     * @param string $name  Contact name
     * @param string $url   Contact URL
     * @param string $email Contact email address
     *
     * @return self
     */
    public static function full(string $name, string $url, string $email): self
    {
        return self::create(name: $name, url: $url, email: $email);
    }

    /**
     * Create contact for a team.
     *
     * @param string $teamName Team name
     * @param string $url      Team documentation or support URL
     * @param string $email    Team email address
     *
     * @return self
     */
    public static function team(string $teamName, string $url, string $email): self
    {
        return self::create(name: $teamName, url: $url, email: $email);
    }

    /**
     * Create a new contact information instance.
     *
     * @param null|string $name  The name of the contact person, team, or organization responsible
     *                           for the API. Used for identification in documentation and support
     *                           communications (e.g., "API Team", "John Smith").
     * @param null|string $url   The URL to a web page, documentation site, or contact form where
     *                           additional information can be found or support requests can be submitted.
     *                           Must be a valid HTTP/HTTPS URL.
     * @param null|string $email The email address for contacting the API team or responsible individual.
     *                           Used for support inquiries, bug reports, and general communication.
     *                           Should be a monitored email address.
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $url = null,
        public readonly ?string $email = null,
    ) {
        $this->validate();
    }

    /**
     * Validate contact information fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->name !== null) {
            $this->validateName();
        }

        if ($this->url !== null) {
            $this->validateUrl();
        }

        if ($this->email !== null) {
            $this->validateEmail();
        }

        $this->validateAtLeastOneFieldPresent();
    }

    /**
     * Validate the name field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateName(): void
    {
        $trimmedName = trim($this->name);

        if ($trimmedName === '') {
            throw new \InvalidArgumentException('Contact name cannot be empty or whitespace only');
        }

        if (mb_strlen($trimmedName) > 255) {
            throw new \InvalidArgumentException(
                sprintf('Contact name cannot exceed 255 characters, got %d', mb_strlen($trimmedName)),
            );
        }

        // Prevent HTML/script injection
        if ($trimmedName !== strip_tags($trimmedName)) {
            throw new \InvalidArgumentException('Contact name cannot contain HTML tags');
        }
    }

    /**
     * Validate the URL field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateUrl(): void
    {
        if (!filter_var($this->url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid contact URL format: %s', $this->url),
            );
        }

        $parsedUrl = parse_url($this->url);

        if (!isset($parsedUrl['scheme']) || !\in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Contact URL must use HTTP or HTTPS protocol, got: %s', $parsedUrl['scheme'] ?? 'none'),
            );
        }

        // Prevent javascript: and data: URLs
        $scheme = strtolower($parsedUrl['scheme']);

        if (\in_array($scheme, ['javascript', 'data', 'vbscript', 'file'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Contact URL scheme "%s" is not allowed for security reasons', $scheme),
            );
        }
    }

    /**
     * Validate the email field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateEmail(): void
    {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid email address format: %s', $this->email),
            );
        }

        // Additional RFC 5322 validation
        if (mb_strlen($this->email) > 254) {
            throw new \InvalidArgumentException(
                sprintf('Email address cannot exceed 254 characters, got %d', mb_strlen($this->email)),
            );
        }
    }

    /**
     * Validate that at least one contact method is provided.
     *
     * @throws \InvalidArgumentException
     */
    private function validateAtLeastOneFieldPresent(): void
    {
        if ($this->name === null && $this->url === null && $this->email === null) {
            throw new \InvalidArgumentException(
                'ContactData requires at least one field (name, url, or email) to be provided',
            );
        }
    }
}
