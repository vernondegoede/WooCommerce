<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Mollie\Api\Models\Errors\APIException as SdkApiException;
use Mollie\Api\Models\Errors\ErrorResponseThrowable;
use Throwable;

class ApiException extends Exception
{
    protected ?string $field = null;

    protected string $plainMessage = '';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?string $field = null,
        ?Throwable $previous = null,
        ?string $plainMessage = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->field = $field;
        $this->plainMessage = $plainMessage ?? $message;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function hasField(): bool
    {
        return $this->field !== null && $this->field !== '';
    }

    public function getPlainMessage(): string
    {
        return $this->plainMessage;
    }

    public static function fromSdkThrowable(Throwable $throwable): self
    {
        if ($throwable instanceof self) {
            return $throwable;
        }

        if ($throwable instanceof ErrorResponseThrowable) {
            $error = $throwable->container;
            $status = $error->status;
            $plainMessage = self::escapeText(
                sprintf(
                    'Error executing API call (%d: %s): %s',
                    $status,
                    $error->title,
                    $error->detail
                )
            );

            $details = '';
            $documentationHref = $error->links->documentation->href ?? null;
            if (is_string($documentationHref) && $documentationHref !== '') {
                $details .= '. Documentation: ' . $documentationHref;
            }

            $body = $error->rawResponse !== null ? (string) $error->rawResponse->getBody() : $throwable->getMessage();
            if ($body !== '') {
                $details .= '. Request body: ' . $body;
            }

            return new MollieErrorResponse(
                $plainMessage,
                self::escapeText($details),
                $status,
                self::escapeText((string) ($error->field ?? ''))
            );
        }

        if ($throwable instanceof SdkApiException) {
            $status = $throwable->statusCode;
            $decoded = json_decode($throwable->body);
            if (is_object($decoded) && isset($decoded->status, $decoded->title, $decoded->detail)) {
                $plainMessage = self::escapeText(
                    sprintf(
                        'Error executing API call (%d: %s): %s',
                        (int) $decoded->status,
                        (string) $decoded->title,
                        (string) $decoded->detail
                    )
                );
                $field = isset($decoded->field) && is_string($decoded->field) ? $decoded->field : '';
                $details = '';
                if (isset($decoded->_links->documentation->href) && is_string($decoded->_links->documentation->href)) {
                    $details .= '. Documentation: ' . $decoded->_links->documentation->href;
                }
                if ($throwable->body !== '') {
                    $details .= '. Request body: ' . $throwable->body;
                }

                return new MollieErrorResponse(
                    $plainMessage,
                    self::escapeText($details),
                    $status,
                    self::escapeText($field)
                );
            }

            $plainMessage = self::escapeText($throwable->getMessage());
            $details = $throwable->body !== '' ? self::escapeText('. Request body: ' . $throwable->body) : '';

            return new self(
                $plainMessage . $details,
                $status,
                null,
                $throwable,
                $plainMessage
            );
        }

        if ($throwable instanceof GuzzleException) {
            $message = self::escapeText($throwable->getMessage());

            return new self(
                $message,
                (int) $throwable->getCode(),
                null,
                $throwable,
                $message
            );
        }

        $message = self::escapeText($throwable->getMessage());

        return new self(
            $message,
            (int) $throwable->getCode(),
            null,
            $throwable,
            $message
        );
    }

    private static function escapeText(string $value): string
    {
        if (function_exists('esc_html')) {
            return (string) esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
