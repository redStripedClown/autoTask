<?php

namespace AutoTask;

use AutoTask\Exception\StorageException;
use DateTimeImmutable;
use DateTimeInterface;

class AutoTask
{
    private array $config;
    private string $storagePath;
    private string $logFile;
    private string $stateFile;
    private string $lockDir;

    public function __construct(array $config, string $storagePath)
    {
        $storagePath = rtrim($storagePath, "/\\");

        if (!is_dir($storagePath)) {
            throw new StorageException('O diretório de storage não existe: ' . $storagePath);
        }

        if (!is_writable($storagePath)) {
            throw new StorageException('O diretório de storage não é gravável: ' . $storagePath);
        }

        $this->config      = $config;
        $this->storagePath = $storagePath;
        $this->logFile     = $storagePath . '/logs.jsonl';
        $this->stateFile   = $storagePath . '/state.json';
        $this->lockDir     = $storagePath . '/locks';

        if (!is_dir($this->lockDir)) {
            if (!@mkdir($this->lockDir, 0775, true) && !is_dir($this->lockDir)) {
                throw new StorageException('Não foi possível criar o diretório de locks: ' . $this->lockDir);
            }
        }

        if (!is_writable($this->lockDir)) {
            throw new StorageException('O diretório de locks não é gravável: ' . $this->lockDir);
        }
    }

    public function deveExecutar(string $idTarefa, array $tarefa): bool
    {
        $estado = $this->lerEstado();
        $ultimaExecucao = $estado[$idTarefa] ?? null;
        $ultimaExecucaoEm = $ultimaExecucao !== null
            ? new DateTimeImmutable($ultimaExecucao)
            : null;

        $agora = new DateTimeImmutable();
        $frequencia = $tarefa['frequency'] ?? [];
        $tipo = $frequencia['type'] ?? null;

        if ($tipo === 'interval') {
            $minutos = (int)($frequencia['minutes'] ?? 0);
            if ($minutos <= 0) {
                return false;
            }
            if ($ultimaExecucaoEm === null) {
                return true;
            }
            return ($agora->getTimestamp() - $ultimaExecucaoEm->getTimestamp()) >= ($minutos * 60);
        }

        if ($tipo === 'daily') {
            $horario = $frequencia['time'] ?? '00:00';
            [$hora, $minuto] = array_map('intval', explode(':', $horario));
            $horarioAlvo = $agora->setTime($hora, $minuto, 0);

            if ($agora < $horarioAlvo) {
                return false;
            }
            if ($ultimaExecucaoEm === null) {
                return true;
            }
            return $ultimaExecucaoEm->format('Y-m-d') !== $agora->format('Y-m-d');
        }

        if ($tipo === 'times') {
            $horarios = $frequencia['times'] ?? [];
            if (empty($horarios)) {
                return false;
            }

            $horarioMaisRecente = null;
            foreach ($horarios as $horario) {
                [$hora, $minuto] = array_map('intval', explode(':', $horario));
                $horarioAlvo = $agora->setTime($hora, $minuto, 0);
                if ($agora >= $horarioAlvo
                    && ($horarioMaisRecente === null || $horarioAlvo > $horarioMaisRecente)
                ) {
                    $horarioMaisRecente = $horarioAlvo;
                }
            }

            if ($horarioMaisRecente === null) {
                return false;
            }
            if ($ultimaExecucaoEm === null) {
                return true;
            }
            return $ultimaExecucaoEm < $horarioMaisRecente;
        }

        return false;
    }

    private function lerEstado(): array
    {
        if (!is_file($this->stateFile)) {
            file_put_contents($this->stateFile, '{}');
            return [];
        }

        $conteudo = file_get_contents($this->stateFile);
        $dados = json_decode($conteudo, true);

        if (!is_array($dados)) {
            file_put_contents($this->stateFile, '{}');
            return [];
        }

        return $dados;
    }

    public function registrarExecucao(string $idTarefa): void
    {
        $estado = $this->lerEstado();
        $estado[$idTarefa] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        file_put_contents(
            $this->stateFile,
            json_encode(
                (object) $estado,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) . PHP_EOL
        );
    }

    public function executar(string $idTarefa, callable $acao): void
    {
        try {
            $acao();
        } finally {
            $this->registrarExecucao($idTarefa);
        }
    }
}
