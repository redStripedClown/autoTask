<?php

namespace AutoTask;

use AutoTask\Exception\StorageException;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

class AutoTask
{
    private array $config;
    private string $storagePath;
    private string $logFile;
    private string $stateFile;
    private string $lockDir;
    private array $tasks = [];

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

    /**
     * Associa o nome de uma tarefa (chave em config/tasks.php) a uma função PHP.
     *
     * @throws InvalidArgumentException se a tarefa não estiver na configuração
     */
    public function register(string $name, callable $fn): void
    {
        if (!isset($this->config['tasks'][$name])) {
            throw new InvalidArgumentException(
                'Tarefa não configurada em config/tasks.php: ' . $name
            );
        }

        $this->tasks[$name] = $fn;
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

    /**
     * Executa UMA tarefa pelo nome, forçando a execução (não consulta deveExecutar()).
     *
     * Passos: valida -> executa com retentativas -> registra o log -> atualiza o state.json.
     * Se a tarefa falhar mesmo depois de todas as retentativas, o log e o estado são
     * gravados e a última exceção é lançada de novo para quem chamou tratar.
     *
     * Se a mesma tarefa já estiver rodando em outro processo (lock ocupado), esta
     * execução é ignorada: grava no log "Ignorada: execução anterior ainda ativa"
     * e retorna sem executar nada.
     *
     * @throws InvalidArgumentException se a tarefa não estiver na config ou não estiver registrada
     * @throws StorageException se não for possível criar o arquivo de lock
     * @throws Throwable a última exceção da tarefa, se todas as tentativas falharem
     */
    public function runTask(string $name): void
    {
        $this->executarTarefa($name);
    }

    /**
     * Faz o trabalho do runTask() e informa o que aconteceu.
     * (O runTask() é "void" como pede a issue #6; o runPending() precisa saber
     * se a tarefa rodou ou foi ignorada, por isso existe este método.)
     *
     * @return string "sucesso" ou "ignorada"
     */
    private function executarTarefa(string $name): string
    {
        if (!isset($this->config['tasks'][$name])) {
            throw new InvalidArgumentException('Tarefa não configurada em config/tasks.php: ' . $name);
        }

        if (!isset($this->tasks[$name])) {
            throw new InvalidArgumentException('Tarefa não registrada (use register()): ' . $name);
        }

        $lock = $this->adquirirLock($name);

        if ($lock === null) {
            $this->registrarLog(
                $name,
                new DateTimeImmutable(),
                0.0,
                'ignorada',
                0,
                'Ignorada: execução anterior ainda ativa'
            );
            return 'ignorada';
        }

        try {
            $retryer = Retryer::daConfig($this->config['tasks'][$name]);
            $inicio  = new DateTimeImmutable();
            $t0      = microtime(true);
            $erro    = null;

            try {
                $retryer->executar($this->tasks[$name]);
            } catch (Throwable $e) {
                $erro = $e;
            }

            $duracao = microtime(true) - $t0;

            try {
                $this->registrarLog(
                    $name,
                    $inicio,
                    $duracao,
                    $erro === null ? 'sucesso' : 'falha',
                    $retryer->getTentativas(),
                    $erro?->getMessage()
                );
            } finally {
                // O estado é atualizado tanto no sucesso quanto na falha (issue #11)
                $this->registrarExecucao($name);
            }

            if ($erro !== null) {
                throw $erro;
            }

            return 'sucesso';
        } finally {
            // O lock é liberado sempre, com sucesso, falha ou exceção
            $this->liberarLock($lock);
        }
    }

    /**
     * Tenta pegar o lock da tarefa: storage/locks/<nome>.lock
     *
     * Usa flock(LOCK_EX | LOCK_NB): "NB" significa que NÃO fica esperando; se outro
     * processo já segura o lock, devolve null na hora. Cada tarefa tem o seu arquivo,
     * então tarefas diferentes podem rodar ao mesmo tempo.
     *
     * @return resource|null o arquivo travado, ou null se a tarefa já está rodando
     * @throws StorageException se o arquivo de lock não puder ser aberto
     */
    private function adquirirLock(string $name)
    {
        // Só letras, números, _ . e - no nome do arquivo (evita nomes perigosos)
        $arquivo = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        $caminho = $this->lockDir . '/' . $arquivo . '.lock';

        // "c": cria o arquivo se não existir, sem apagar o conteúdo
        $fp = @fopen($caminho, 'c');

        if ($fp === false) {
            throw new StorageException('Não foi possível abrir o arquivo de lock: ' . $caminho);
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }

        return $fp;
    }

    /**
     * Libera o lock. (Se o processo morrer, o sistema operacional libera sozinho.)
     *
     * @param resource $fp
     */
    private function liberarLock($fp): void
    {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Percorre todas as tarefas da config e executa SÓ as que já venceram (deveExecutar()).
     *
     * Cada tarefa roda isolada: se uma falhar, as seguintes continuam normalmente.
     * O log de cada execução é gravado pelo runTask(); aqui só mostramos no terminal
     * o que aconteceu.
     *
     * @return array nome da tarefa => "sucesso", "falha", "ignorada" ou "nao_registrada"
     *               (só aparecem as tarefas que estavam vencidas)
     */
    public function runPending(): array
    {
        $resultado = [];

        foreach (($this->config['tasks'] ?? []) as $nome => $tarefa) {
            $nome = (string)$nome;

            try {
                if (!$this->deveExecutar($nome, (array)$tarefa)) {
                    continue;
                }

                if (!isset($this->tasks[$nome])) {
                    echo "[ignorada] $nome: venceu, mas não está registrada (use register())" . PHP_EOL;
                    $resultado[$nome] = 'nao_registrada';
                    continue;
                }

                $status = $this->executarTarefa($nome);

                if ($status === 'ignorada') {
                    echo "[ignorada] $nome: execução anterior ainda ativa" . PHP_EOL;
                } else {
                    echo "[sucesso] $nome" . PHP_EOL;
                }
                $resultado[$nome] = $status;
            } catch (Throwable $e) {
                echo "[falha] $nome: " . $e->getMessage() . PHP_EOL;
                $resultado[$nome] = 'falha';
            }
        }

        if (empty($resultado)) {
            echo "Nenhuma tarefa pendente." . PHP_EOL;
        }

        return $resultado;
    }

    /**
     * Registra uma execução em storage/logs.jsonl (uma linha JSON por execução).
     *
     * @param string $status "sucesso", "falha" ou "ignorada"
     * @throws InvalidArgumentException se o status for inválido
     * @throws StorageException se não for possível gravar o log
     */
    public function registrarLog(
        string $tarefa,
        DateTimeInterface $inicio,
        float $duracao,
        string $status,
        int $tentativas = 1,
        ?string $erro = null
    ): void {
        if (!in_array($status, ['sucesso', 'falha', 'ignorada'], true)) {
            throw new InvalidArgumentException('Status de log inválido: ' . $status);
        }

        $entrada = [
            'task'     => $tarefa,
            'start'    => $inicio->format(DateTimeInterface::ATOM),
            'duration' => round($duracao, 4),
            'status'   => $status,
            'attempts' => $tentativas,
            'error'    => $erro,
        ];

        // INVALID_UTF8_SUBSTITUTE evita que uma mensagem de erro com
        // caracteres estranhos faça o json_encode falhar.
        $linha = json_encode(
            $entrada,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($linha === false) {
            throw new StorageException('Não foi possível gerar o JSON do log: ' . json_last_error_msg());
        }

        // LOCK_EX trava o arquivo durante a escrita, assim execuções
        // simultâneas não misturam as linhas do log.
        if (file_put_contents($this->logFile, $linha . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new StorageException('Não foi possível gravar o log: ' . $this->logFile);
        }
    }
}
