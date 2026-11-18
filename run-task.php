<?php

/**
 * Entry point dedicado para execução manual de UMA tarefa.
 *
 *     php run-task.php <nome>
 *
 * Equivale a `php run.php task <nome>`, mas sem a máquina de comandos.
 * Bootstrap e execução vêm de bootstrap.php — não há lógica duplicada.
 */

require_once __DIR__ . '/bootstrap.php';

$nomeTarefa = $argv[1] ?? null;

if ($nomeTarefa === null) {
    fwrite(STDERR, 'Erro: informe o nome da tarefa.' . PHP_EOL);
    mostrarUsoRunTask();
    exit(1);
}

$auto = autotask_bootstrap(__DIR__);
exit(autotask_run_task($auto, $nomeTarefa));

function mostrarUsoRunTask(): void
{
    echo 'Uso:' . PHP_EOL;
    echo '  php run-task.php <nome>      Executa a tarefa <nome>' . PHP_EOL;
    echo PHP_EOL;
    echo 'Equivale a: php run.php task <nome>' . PHP_EOL;
}