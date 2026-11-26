<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use BackedEnum;
use Mollie\Api\Utils\JSON;

/**
 * Normalizes Speakeasy SDK DTOs into plain stdClass/array structures for WordPress transients
 * and legacy property accessors.
 */
final class Normalizer
{
    /**
     * @param mixed $value
     * @return mixed
     */
    public static function toPlainObject($value)
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::toPlainObject($v);
            }

            return $result;
        }

        if (is_object($value)) {
            $serializer = JSON::createSerializer();
            $json = $serializer->serialize($value, 'json');

            return json_decode($json, false);
        }

        return $value;
    }

    /**
     * @param object $dto
     * @return array<string, mixed>
     */
    public static function toPlainMethodArray(object $dto): array
    {
        $plain = self::toPlainObject($dto);
        if (!is_object($plain)) {
            return [];
        }

        return get_object_vars($plain);
    }
}
