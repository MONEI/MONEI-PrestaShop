<?php
namespace Monei;

use Exception;

class ApiException extends Exception
{
    private $previous;

    /**
     * Inherit linking
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     * @return void
     */
    public function __construct(
        string    $message = '',
        int       $code = 0,
        Exception $previous = null
    )
    {
        parent::__construct($message, $code);

        if (!is_null($previous)) {
            $this->previous = $previous;
        }

        header('HTTP/1.1 ' . $code . ' Bad Request');
        echo(json_encode([
            'code' => $code,
            'message' => $message,
        ]));
    }

    /**
     * Magic toString method, with custom className
     * @return string
     */
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
