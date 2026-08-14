<?php

namespace App\Exceptions;

use Exception;

class KuaforiaMcpException extends Exception
{
    public function __construct(
        string $message = 'Error al consultar señales de Kuaforia vía MCP',
        int $code = 502,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
