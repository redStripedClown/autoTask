<?php

namespace AutoTask;

use InvalidArgumentException;

/**
 * Define QUANTO esperar entre uma tentativa e outra.
 *
 * - Backoff progressivo: a espera dobra a cada falha (100ms, 200ms, 400ms...).
 * - Jitter: soma um valor aleatório em milissegundos, para que várias tarefas
 *   que falharam juntas não tentem de novo exatamente ao mesmo tempo
 *   (evita o "thundering herd").
 */
class WaitStrategy
{
    private int $baseMs;
    private int $jitterMaxMs;
    private int $maxMs;

    /**
     * @param int $baseMs      espera da 1ª falha, em ms
     * @param int $jitterMaxMs jitter máximo em ms (sorteado entre 0 e este valor)
     * @param int $maxMs       teto da espera (sem o jitter), para o backoff não crescer sem limite
     */
    public function __construct(int $baseMs = 100, int $jitterMaxMs = 100, int $maxMs = 30000)
    {
        if ($baseMs < 0 || $jitterMaxMs < 0 || $maxMs < 0) {
            throw new InvalidArgumentException('Os tempos da WaitStrategy não podem ser negativos');
        }

        $this->baseMs      = $baseMs;
        $this->jitterMaxMs = $jitterMaxMs;
        $this->maxMs       = $maxMs;
    }

    /**
     * Calcula a espera (em ms) depois de uma falha.
     *
     * @param int $tentativasFeitas quantas tentativas já falharam (1 = primeira falha)
     */
    public function calcularEspera(int $tentativasFeitas): int
    {
        $expoente = max(0, $tentativasFeitas - 1);

        // Limita o expoente para evitar estouro de inteiro em valores absurdos
        $backoff = $this->baseMs * (2 ** min($expoente, 30));
        $backoff = min($backoff, $this->maxMs);

        $jitter = $this->jitterMaxMs > 0 ? random_int(0, $this->jitterMaxMs) : 0;

        return $backoff + $jitter;
    }

    /**
     * Espera de verdade (bloqueia o script) e devolve quantos ms esperou.
     */
    public function esperar(int $tentativasFeitas): int
    {
        $ms = $this->calcularEspera($tentativasFeitas);
        usleep($ms * 1000);

        return $ms;
    }
}
