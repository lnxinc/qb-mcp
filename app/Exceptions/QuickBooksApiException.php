<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Client\Response;
use Throwable;

class QuickBooksApiException extends Exception
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Build the exception from a failed QuickBooks API response.
     */
    public static function fromResponse(Response $response): self
    {
        $errors = $response->json('Fault.Error');
        $errors = is_array($errors) ? array_values(array_filter($errors, 'is_array')) : [];

        $messages = array_map(function (array $error): string {
            $message = (string) ($error['Message'] ?? 'Unknown error');

            if (filled($error['Detail'] ?? null)) {
                $message .= ': '.$error['Detail'];
            }

            if (filled($error['code'] ?? null)) {
                $message .= " (code {$error['code']})";
            }

            return $message;
        }, $errors);

        $message = $messages === []
            ? "QuickBooks API request failed with HTTP status {$response->status()}."
            : 'QuickBooks API error: '.implode(' | ', $messages);

        return new self($message, $response->status(), $errors);
    }
}
