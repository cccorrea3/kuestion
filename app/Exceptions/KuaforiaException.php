<?php

namespace App\Exceptions;

use Exception;

class KuaforiaException extends Exception
{
    public function __construct(
        string $message = 'Error al consultar Kuaforia',
        int $code = 502,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
