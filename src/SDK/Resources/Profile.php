<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use Mollie\Api\Models\Components\ProfileResponse;

class Profile
{
    public string $resource = 'profile';
    public string $id = '';
    public string $mode = 'live';
    public string $name = '';
    public string $website = '';
    public string $email = '';
    public string $phone = '';
    public string $status = '';
    public string $createdAt = '';

    private ?ProfileResponse $raw = null;

    public static function fromResponse(ProfileResponse $response): self
    {
        $profile = new self();
        $profile->raw = $response;
        $profile->resource = $response->resource;
        $profile->id = $response->id;
        $profile->mode = $response->mode->value;
        $profile->name = $response->name;
        $profile->website = $response->website;
        $profile->email = $response->email;
        $profile->phone = $response->phone;
        $profile->status = $response->status->value;
        $profile->createdAt = $response->createdAt;

        return $profile;
    }

    public function raw(): ?ProfileResponse
    {
        return $this->raw;
    }
}
