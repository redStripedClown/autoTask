<?php

/**
 * Entrada de linha de comando do autoTask (multi-comando).
 *
 *     php run.php pending          Executa as tarefas pendentes
 *     php run.php task <nome>      Executa uma tarefa específica
 *
 * O bootstrap (núcleo/apps/config/storage/registro) e a execução de uma
 * tarefa única vivem em bootstrap.php. Aqui só ficam o parsing de $argv
 * e o comando "pending", que não existe em run-task.php.
 */

require_once __DIR__ . '/bootstrap.php';

$comando = $argv[1] ?? null;

if ($comando === null) {
    mostrarUso();
    exit(1);
}

if ($comando === 'pending') {
    $auto = autotask_bootstrap(__DIR__);
    $auto->runPending();
    exit(0);
}

if ($comando === 'task') {
    $nomeTarefa = $argv[2] ?? null;

    if ($nomeTarefa === null) {
        fwrite(STDERR, 'Erro: informe o nome da tarefa.' . PHP_EOL);
        mostrarUso();
        exit(1);
    }

    $auto = autotask_bootstrap(__DIR__);
    exit(autotask_run_task($auto, $nomeTarefa));
}

fwrite(STDERR, 'Comando inválido: ' . $comando . PHP_EOL);
mostrarUso();
exit(1);

function mostrarUso(): void
{
    echo 'Uso:' . PHP_EOL;
    echo '  php run.php pending          Executa as tarefas pendentes' . PHP_EOL;
    echo '  php run.php task <nome>      Executa uma tarefa específica' . PHP_EOL;
}