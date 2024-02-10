<?php


namespace Monei\Traits;

use Monei\ApiException;

trait ValidationHelpers
{
    /**
     * Return an array if the string provided is a valid JSON
     * @param string $json
     * @return array
     * @throws ApiException
     */
    public function vJSON(string $json): array
    {
        $has_error = true;
        $json_is_valid = json_decode($json, true);

        // Switch and check possible JSON errors
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $has_error = false;
                $error_msg = ''; // JSON is valid // No error has occurred
                break;
            case JSON_ERROR_DEPTH:
                $error_msg = 'The maximum stack depth has been exceeded.';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error_msg = 'Invalid or malformed JSON.';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error_msg = 'Control character error, possibly incorrectly encoded.';
                break;
            case JSON_ERROR_SYNTAX:
                $error_msg = 'Syntax error, malformed JSON.';
                break;
            // PHP >= 5.3.3
            case JSON_ERROR_UTF8:
                $error_msg = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
                break;
            // PHP >= 5.5.0
            case JSON_ERROR_RECURSION:
                $error_msg = 'One or more recursive references in the value to be encoded.';
                break;
            // PHP >= 5.5.0
            case JSON_ERROR_INF_OR_NAN:
                $error_msg = 'One or more NAN or INF values in the value to be encoded.';
                break;
            case JSON_ERROR_UNSUPPORTED_TYPE:
                $error_msg = 'A value of a type that cannot be encoded was given.';
                break;
            default:
                $error_msg = 'Unknown JSON error occured.';
                break;
        }

        if ($has_error) {
            throw new ApiException($error_msg, 1);
        }

        if (!is_array($json_is_valid)) {
            throw new ApiException('Not a JSON array', 1);
        }

        return $json_is_valid;
    }

    /**
     * Returns bool for JSON validation
     * @param string $json
     * @return bool
     */
    public function isJSON(string $json): bool
    {
        $is_valid = false;
        json_decode($json);
        // Switch and check possible JSON errors
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $is_valid = true;
                break;
        }

        return $is_valid;
    }
}
