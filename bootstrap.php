<?php

use AutoTask\AutoTask;
use AutoTask\Exception\StorageException;

/**
 * Carrega tudo o que o CLI precisa e devolve um AutoTask pronto:
 *   - núcleo (Composer opcional: vendor/autoload.php OU require_once manual)
 *   - apps em apps/<app>/tasks.php (via glob, sem caminho fixo)
 *   - config/tasks.php
 *   - cria storage/ se preciso e verifica o mkdir
 *   - instancia AutoTask (com try/catch de StorageException)
 *   - registra tarefas por convenção snake_case → camelCase, se a função existir
 *
 * Em erro de ambiente, imprime mensagem em STDERR e encerra com exit(1) —
 * comportamento padrão dos entry points CLI, então não precisa ser repetido
 * em cada script.
 */
function autotask_bootstrap(string $raiz): AutoTask
{
    // 1) Núcleo
    if (is_file($raiz . '/vendor/autoload.php')) {
        require_once $raiz . '/vendor/autoload.php';
    } else {
        require_once $raiz . '/src/Exception/StorageException.php';
        require_once $raiz . '/src/AutoTask.php';
        require_once $raiz . '/src/StopStrategy.php';
        require_once $raiz . '/src/WaitStrategy.php';
        require_once $raiz . '/src/Retryer.php';
    }

    // 2) Apps (glob — novos apps entram sem editar nada)
    foreach (glob($raiz . '/apps/*/tasks.php') ?: [] as $appFile) {
        require_once $appFile;
    }

    // 3) Config
    $config = require $raiz . '/config/tasks.php';

    // 4) Storage
    $storagePath = $raiz . '/storage';
    if (!is_dir($storagePath)) {
        if (!@mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
            fwrite(STDERR, 'Erro: não foi possível criar a pasta storage/.' . PHP_EOL);
            exit(1);
        }
    }

    // 5) Instancia
    try {
        $auto = new AutoTask($config, $storagePath);
    } catch (StorageException $e) {
        fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    // 6) Registro por convenção
    foreach (array_keys($config['tasks'] ?? []) as $nomeTarefa) {
        $funcao = snakeParaCamel($nomeTarefa);
        if (function_exists($funcao)) {
            $auto->register($nomeTarefa, $funcao);
        }
    }

    return $auto;
}

/**
 * Executa UMA tarefa (forçando a execução, via runTask()) e devolve o
 * exit code: 0 em sucesso, 1 em falha. Em falha, imprime mensagem em
 * STDERR antes de devolver.
 *
 * Esta é a lógica compartilhada entre `php run.php task <nome>` e
 * `php run-task.php <nome>`. É o único ponto que chama runTask() com
 * try/catch — duplicar isso nos entry points seria a duplicação a evitar.
 */
function autotask_run_task(AutoTask $auto, string $nome): int
{
    try {
        $auto->runTask($nome);
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Erro ao executar a tarefa "' . $nome . '": ' . $e->getMessage() . PHP_EOL);
        return 1;
    }
}

/**
 * Converte o nome lógico da tarefa (snake_case) para o nome da função PHP
 * (camelCase): hello_world → helloWorld, limpeza_logs → limpezaLogs.
 */
function snakeParaCamel(string $nome): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $nome))));
}