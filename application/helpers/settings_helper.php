<?php

if ( ! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author      InvoicePlane Developers & Contributors
 * @copyright   Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license     https://invoiceplane.com/license.txt
 * @link        https://invoiceplane.com
 */

/**
 * Get a setting value.
 *
 * @param string $setting_key
 * @param mixed  $default
 * @param bool   $escape
 *
 * @return string
 */
function get_setting($setting_key, $default = '', $escape = false)
{
    $CI    = & get_instance();
    $value = $CI->mdl_settings->setting($setting_key, $default);

    return $escape ? htmlsc($value) : $value;
}

/**
 * Get a non-negative integer setting value.
 *
 * Falls back to the provided default when the stored setting is empty or
 * invalid. Optionally logs the invalid value to help diagnose broken
 * configuration on existing installs.
 */
function get_non_negative_integer_setting(string $setting_key, int $default, bool $log_invalid = true): int
{
    $raw_value = get_setting($setting_key, (string) $default);
    $value     = filter_var(
        $raw_value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 0,
            ],
        ]
    );

    if ($value === false) {
        if ($log_invalid) {
            $CI = & get_instance();
            $CI->load->helper('file_security');

            log_message(
                'warning',
                sprintf(
                    'Invalid %s setting value: %s. Falling back to %d.',
                    sanitize_for_logging($setting_key),
                    sanitize_for_logging((string) $raw_value),
                    $default
                )
            );
        }

        return $default;
    }

    return (int) $value;
}

/**
 * Get the settings for a payment gateway.
 *
 * @param string $gateway
 *
 * @return array
 */
function get_gateway_settings($gateway)
{
    $CI = & get_instance();

    return $CI->mdl_settings->gateway_settings($gateway);
}

/**
 * Compares the two given values and outputs selected="selected"
 * if the values match or the operation is true for the single value.
 *
 * Examples
 * check_select($option_key, 'key_1')           Checks if $option_key equals (==) 'key_1'.
 * check_select($option_key, 'key_1', '!=')     Checks if $option_key not equals (!=) 'key_1'.
 * check_select($option_key)                    The same like if ($option_key) { ...
 * check_select($option_key, null, 'e')         Checks if the $option_key value is empty.
 * check_select($option_key != 'key_1')         If the first param is bool, it is used to validate the select
 *
 * @param string|int      $value1
 * @param string|int|null $value2
 * @param string          $operator
 * @param bool            $checked
 */
function check_select($value1, $value2 = null, $operator = '==', $checked = false): void
{
    $select = $checked ? 'checked="checked"' : 'selected="selected"';

    // Instant-validate if $value1 is a bool value
    if (is_bool($value1) && $value2 === null) {
        echo $value1 ? $select : '';

        return;
    }

    switch ($operator) {
        case '==':
            $echo_selected = $value1 == $value2;
            break;
        case '!=':
            $echo_selected = $value1 != $value2;
            break;
        case 'e':
        case '!e':
            $echo_selected = empty($value1);
            break;
        default:
            $echo_selected = (bool) $value1;
            break;
    }

    echo $echo_selected ? $select : '';
}
