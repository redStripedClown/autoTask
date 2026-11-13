<?php

namespace AutoTask;

use Throwable;

/**
 * Coordena a execução com retentativas:
 *   1. executa a função;
 *   2. se falhar, pergunta à StopStrategy se deve parar;
 *   3. se não deve parar, pede à WaitStrategy para esperar e tenta de novo;
 *   4. se esgotar as tentativas, propaga a ÚLTIMA exceção.
 */
class Retryer
{
    private StopStrategy $stop;
    private WaitStrategy $wait;
    private int $tentativas = 0;

    public function __construct(StopStrategy $stop, WaitStrategy $wait)
    {
        $this->stop = $stop;
        $this->wait = $wait;
    }

    /**
     * Monta um Retryer a partir da configuração de UMA tarefa
     * (o array em $config['tasks'][nome]). Se não houver max_retries, não retenta.
     */
    public static function daConfig(array $tarefa): self
    {
        $maxRetries = (int)($tarefa['max_retries'] ?? 0);

        return new self(new StopStrategy($maxRetries), new WaitStrategy());
    }

    /**
     * Executa $fn com retentativas e devolve o retorno dela.
     *
     * @throws Throwable a última exceção, se todas as tentativas falharem
     */
    public function executar(callable $fn): mixed
    {
        $this->tentativas = 0;

        while (true) {
            $this->tentativas++;

            try {
                return $fn();
            } catch (Throwable $e) {
                if ($this->stop->deveParar($this->tentativas)) {
                    throw $e;
                }

                $this->wait->esperar($this->tentativas);
            }
        }
    }

    /**
     * Quantas tentativas a última chamada de executar() fez
     * (vale tanto para sucesso quanto para falha). Use no campo "attempts" do log.
     */
    public function getTentativas(): int
    {
        return $this->tentativas;
    }
}
