<?php

namespace AutoTask;

use InvalidArgumentException;

/**
 * Define QUANDO parar de tentar.
 *
 * Total de tentativas = max_retries + 1
 * (ex.: max_retries = 3 -> 1 tentativa inicial + 3 retentativas = 4 tentativas).
 */
class StopStrategy
{
    private int $maxRetries;

    public function __construct(int $maxRetries)
    {
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('max_retries não pode ser negativo: ' . $maxRetries);
        }

        $this->maxRetries = $maxRetries;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getMaxTentativas(): int
    {
        return $this->maxRetries + 1;
    }

    /**
     * Retorna true se já foram feitas todas as tentativas permitidas.
     *
     * @param int $tentativasFeitas quantas vezes a tarefa já rodou (e falhou)
     */
    public function deveParar(int $tentativasFeitas): bool
    {
        return $tentativasFeitas >= $this->getMaxTentativas();
    }
}
