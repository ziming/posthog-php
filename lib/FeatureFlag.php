<?php

namespace PostHog;

const LONG_SCALE = 0xfffffffffffffff;

class FeatureFlag
{
    public static function matchProperty($property, $propertyValues)
    {
        $key = $property["key"];
        $operator = $property["operator"] ?? "exact";
        $value = $property["value"];

        if (!array_key_exists($key, $propertyValues)) {
            throw new InconclusiveMatchException("Can't match properties without a given property value");
        }

        if ($operator == "is_not_set") {
            throw new InconclusiveMatchException("can't match properties with operator is_not_set");
        }

        $overrideValue = $propertyValues[$key];

        if ($operator == "exact") {
            return FeatureFlag::computeExactMatch($value, $overrideValue);
        }

        if ($operator == "is_not") {
            return !FeatureFlag::computeExactMatch($value, $overrideValue);
        }

        if ($operator == "is_set") {
            return array_key_exists($key, $propertyValues);
        }

        if ($operator == "icontains") {
            return strpos(strtolower(strval($overrideValue)), strtolower(strval($value))) !== false;
        }

        if ($operator == "not_icontains") {
            return strpos(strtolower(strval($overrideValue)), strtolower(strval($value))) == false;
        }

        if (in_array($operator, ["regex", "not_regex"])) {
            $regexValue = FeatureFlag::prepareValueForRegex($value);
            if (FeatureFlag::isRegularExpression($regexValue)) {
                $returnValue = preg_match($regexValue, $overrideValue) ? true : false;
                if ($operator == "regex") {
                    return $returnValue;
                } else {
                    return !$returnValue;
                }
            } else {
                return false;
            }
        }

        if (in_array($operator, ["gt", "gte", "lt", "lte"])) {
            $parsedValue = null;

            if (is_numeric($value)) {
                $parsedValue = floatval($value);
            }

            if (!is_null($parsedValue) && !is_null($overrideValue)) {
                if (is_string($overrideValue)) {
                    return FeatureFlag::compare($overrideValue, strval($value), $operator);
                } else {
                    return FeatureFlag::compare($overrideValue, $parsedValue, $operator, "numeric");
                }
            } else {
                return FeatureFlag::compare(strval($overrideValue), strval($value), $operator);
            }
        }

        if (in_array($operator, ["is_date_before", "is_date_after", "is_relative_date_before", "is_relative_date_after"])) {
            if ($operator == 'is_relative_date_before' || $operator == 'is_relative_date_after') {
                $parsedDate = FeatureFlag::relativeDateParseForFeatureFlagMatching($value);
            } else {
                $parsedDate = FeatureFlag::convertToDateTime($value);
            }

            if (is_null($parsedDate)) {
                throw new InconclusiveMatchException("The date set on the flag is not a valid format");
            }

            $overrideDate = FeatureFlag::convertToDateTime($overrideValue);
            if ($operator == 'is_date_before' || $operator == 'is_relative_date_before') {
                return $overrideDate < $parsedDate;
            } else {
                return $overrideDate > $parsedDate;
            }
        }

        return false;
    }

    public static function relativeDateParseForFeatureFlagMatching($value)
    {
        $regex = "/^(?<number>[0-9]+)(?<interval>[a-z])$/";
        $parsedDt = new \DateTime("now", new \DateTimeZone("UTC"));
        if (preg_match($regex, $value, $matches)) {
            $number = intval($matches["number"]);

            if ($number >= 10_000) {
                // Guard against overflow, disallow numbers greater than 10_000
                return null;
            }

            $interval = $matches["interval"];
            if ($interval == "h") {
                $parsedDt->sub(new \DateInterval("PT{$number}H"));
            } elseif ($interval == "d") {
                $parsedDt->sub(new \DateInterval("P{$number}D"));
            } elseif ($interval == "w") {
                $parsedDt->sub(new \DateInterval("P{$number}W"));
            } elseif ($interval == "m") {
                $parsedDt->sub(new \DateInterval("P{$number}M"));
            } elseif ($interval == "y") {
                $parsedDt->sub(new \DateInterval("P{$number}Y"));
            } else {
                return null;
            }

            return $parsedDt;
        } else {
            return null;
        }

    }

    private static function convertToDateTime($value) {
        if ($value instanceof \DateTime) {
            return $value;
        } elseif (is_string($value)) {
            try {
                $date = new \DateTime($value);
                if (!is_nan($date->getTimestamp())) {
                    return $date;
                }
            } catch (Exception $e) {
                throw new InconclusiveMatchException("{$value} is in an invalid date format");
            }
        } else {
            throw new InconclusiveMatchException("The date provided {$value} must be a string or date object");
        }
    }

    private static function computeExactMatch($value, $overrideValue)
    {
        if (is_array($value)) {
            return in_array(strtolower(strval($overrideValue)), array_map('strtolower', $value));
        }
        return strtolower(strval($value)) == strtolower(strval($overrideValue));
    }

    private static function compare($lhs, $rhs, $operator, $type = "string")
    {
        // If type is string, we use strcmp to compare the two strings
        // If type is numeric, we use <=> to compare the two numbers

        if ($type == "string") {
            $comparison = strcmp($lhs, $rhs);
        } else {
            $comparison = $lhs <=> $rhs;
        }

        if ($operator == "gt") {
            return $comparison > 0;
        } elseif ($operator == "gte") {
            return $comparison >= 0;
        } elseif ($operator == "lt") {
            return $comparison < 0;
        } elseif ($operator == "lte") {
            return $comparison <= 0;
        }

        throw new \Exception("Invalid operator: " . $operator);
    }

    private static function hash($key, $distinctId, $salt = "")
    {
        $hashKey = sprintf("%s.%s%s", $key, $distinctId, $salt);
        $hashVal = base_convert(substr(sha1($hashKey), 0, 15), 16, 10);

        return $hashVal / LONG_SCALE;
    }

    private static function getMatchingVariant($flag, $distinctId)
    {
        $variants = FeatureFlag::variantLookupTable($flag);

        foreach ($variants as $variant) {
            if (
                FeatureFlag::hash($flag["key"], $distinctId, "variant") >= $variant["value_min"]
                && FeatureFlag::hash($flag["key"], $distinctId, "variant") < $variant["value_max"]
            ) {
                return $variant["key"];
            }
        }

        return null;
    }

    private static function variantLookupTable($featureFlag)
    {
        $lookupTable = [];
        $valueMin = 0;
        $multivariates = (($featureFlag['filters'] ?? [])['multivariate'] ?? [])['variants'] ?? [];

        foreach ($multivariates as $variant) {
            $valueMax = $valueMin + $variant["rollout_percentage"] / 100;

            array_push($lookupTable, [
                "value_min" => $valueMin,
                "value_max" => $valueMax,
                "key" => $variant["key"]
            ]);
            $valueMin = $valueMax;
        }

        return $lookupTable;
    }

    private static function compareFlagConditions($conditionA, $conditionB)
    {
        $AhasVariantOverride = isset($conditionA["variant"]);
        $BhasVariantOverride = isset($conditionB["variant"]);

        if ($AhasVariantOverride && $BhasVariantOverride) {
            return 0;
        } elseif ($AhasVariantOverride) {
            return -1;
        } elseif ($BhasVariantOverride) {
            return 1;
        } else {
            return 0;
        }
    }

    public static function matchFeatureFlagProperties($flag, $distinctId, $properties)
    {
        $flagConditions = ($flag["filters"] ?? [])["groups"] ?? [];
        $isInconclusive = false;

        // Add index to each condition to make stable sort possible
        $flagConditionsWithIndexes = array();
        $i = 0;
        foreach ($flagConditions as $key => $value) {
            $flagConditionsWithIndexes[] = array($value, $i);
            $i++;
        }
        // # Stable sort conditions with variant overrides to the top.
        // # This ensures that if overrides are present, they are
        // # evaluated first, and the variant override is applied to the first matching condition.
        usort(
            $flagConditionsWithIndexes,
            function ($conditionA, $conditionB) {
                $AhasVariantOverride = isset($conditionA[0]["variant"]);
                $BhasVariantOverride = isset($conditionB[0]["variant"]);

                if ($AhasVariantOverride && $BhasVariantOverride) {
                    return $conditionA[1] - $conditionB[1];
                } elseif ($AhasVariantOverride) {
                    return -1;
                } elseif ($BhasVariantOverride) {
                    return 1;
                } else {
                    return $conditionA[1] - $conditionB[1];
                }
            }
        );

        foreach ($flagConditionsWithIndexes as $conditionWithIndex) {
            $condition = $conditionWithIndex[0];
            try {
                if (FeatureFlag::isConditionMatch($flag, $distinctId, $condition, $properties)) {
                    $variantOverride = $condition["variant"] ?? null;
                    $flagVariants = (($flag["filters"] ?? [])["multivariate"] ?? [])["variants"] ?? [];
                    $variantKeys = array_map(function ($variant) {
                        return $variant["key"];
                    }, $flagVariants);

                    if ($variantOverride && in_array($variantOverride, $variantKeys)) {
                        return $variantOverride;
                    } else {
                        return FeatureFlag::getMatchingVariant($flag, $distinctId) ?? true;
                    }
                }
            } catch (InconclusiveMatchException $e) {
                $isInconclusive = true;
            }
        }

        if ($isInconclusive) {
            throw new InconclusiveMatchException("Can't determine if feature flag is enabled or not with given properties"); //phpcs:ignore
        }

        return false;
    }

    private static function isConditionMatch($featureFlag, $distinctId, $condition, $properties)
    {
        $rolloutPercentage = array_key_exists("rollout_percentage", $condition) ? $condition["rollout_percentage"] : null;

        if (count($condition['properties'] ?? []) > 0) {
            foreach ($condition['properties'] as $property) {
                if (!FeatureFlag::matchProperty($property, $properties)) {
                    return false;
                }
            }

            if (is_null($rolloutPercentage)) {
                return true;
            }
        }

        if (!is_null($rolloutPercentage) && FeatureFlag::hash($featureFlag["key"], $distinctId) > ($rolloutPercentage / 100)) { //phpcs:ignore
            return false;
        }

        return true;
    }

    private static function isRegularExpression($string)
    {
        set_error_handler(function () {
        }, E_WARNING);
        $isRegularExpression = preg_match($string, "") !== false;
        restore_error_handler();
        return $isRegularExpression;
    }

    private static function prepareValueForRegex($value)
    {
        $regex = $value;

        // If delimiter already exists, do nothing
        if (FeatureFlag::isRegularExpression($regex)) {
            return $regex;
        }

        if (substr($regex, 0, 1) != "/") {
            $regex = "/" . $regex;
        }

        if (substr($regex, -1) != "/") {
            $regex = $regex . "/";
        }

        return $regex;
    }
}
