<?php

namespace App\Data;

use Carbon\CarbonInterface;

readonly class QuickBooksTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonInterface $accessTokenExpiresAt,
        public CarbonInterface $refreshTokenExpiresAt,
    ) {
        //
    }

    /**
     * Build the token set from an Intuit token endpoint response.
     *
     * @param  array{access_token: string, refresh_token: string, expires_in: int|string, x_refresh_token_expires_in: int|string}  $payload
     */
    public static function fromTokenResponse(array $payload): self
    {
        return new self(
            accessToken: $payload['access_token'],
            refreshToken: $payload['refresh_token'],
            accessTokenExpiresAt: now()->addSeconds((int) $payload['expires_in']),
            refreshTokenExpiresAt: now()->addSeconds((int) $payload['x_refresh_token_expires_in']),
        );
    }

    /**
     * Get the attributes to persist on a QuickBooks connection.
     *
     * @return array{access_token: string, refresh_token: string, access_token_expires_at: CarbonInterface, refresh_token_expires_at: CarbonInterface}
     */
    public function toConnectionAttributes(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt,
        ];
    }
}
