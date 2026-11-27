<?php

declare(strict_types=1);

namespace Mollie\WooCommerce\SDK\Resources;

use BackedEnum;
use Mollie\Api\Models\Components\Amount;
use Mollie\Api\Models\Components\AmountNullable;
use Mollie\Api\Models\Components\Url;
use Mollie\Api\Models\Components\UrlNullable;
use Mollie\Api\Utils\JSON;
use Speakeasy\Serializer\Serializer;
use stdClass;

/**
 * Normalizes Speakeasy SDK DTOs into plain stdClass/array structures for WordPress transients
 * and legacy property accessors, and provides a process-reused serializer instance.
 */
final class Normalizer
{
    /**
     * Return a reused Speakeasy serializer instance to avoid rebuilding reflection metadata
     * on every API call.
     */
    public static function serializer(): Serializer
    {
        static $serializer = null;
        if (!$serializer instanceof Serializer) {
            $serializer = JSON::createSerializer();
        }

        return $serializer;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function toPlainObject($value)
    {
        if ($value === null || is_scalar($value) || $value instanceof stdClass) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Amount || $value instanceof AmountNullable) {
            return (object) [
                'currency' => $value->currency,
                'value' => $value->value,
            ];
        }

        if ($value instanceof Url || $value instanceof UrlNullable) {
            return (object) [
                'href' => $value->href,
                'type' => $value->type,
            ];
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::toPlainObject($v);
            }

            return $result;
        }

        if (is_object($value)) {
            $json = self::serializer()->serialize($value, 'json');

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
