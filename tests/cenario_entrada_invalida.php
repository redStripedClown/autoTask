
<?php

use AutoTask\AutoTask;

$raiz = dirname(__DIR__);

require_once $raiz . '/src/Exception/StorageException.php';
require_once $raiz . '/src/AutoTask.php';
require_once $raiz . '/src/StopStrategy.php';
require_once $raiz . '/src/WaitStrategy.php';
require_once $raiz . '/src/Retryer.php';

$falhas = 0;

function verificar(string $descricao, bool $ok): void
{
    global $falhas;

    echo ($ok ? '[OK] ' : '[FALHOU] ') . $descricao . "\n";

    if (!$ok) {
        $falhas++;
    }
}

// Tarefa inexistente
exec(
    PHP_BINARY . ' ' . escapeshellarg($raiz . '/run.php') . ' task nao_existe 2>&1',
    $saida,
    $codigo
);

verificar(
    'tarefa inexistente retorna erro',
    $codigo === 1 && str_contains(implode("\n", $saida), 'não configurada')
);

// Execução sem argumentos
$saida = [];

exec(
    PHP_BINARY . ' ' . escapeshellarg($raiz . '/run.php') . ' 2>&1',
    $saida,
    $codigo
);

verificar(
    'execução sem argumentos mostra o uso correto',
    $codigo === 1 && str_contains(implode("\n", $saida), 'Uso:')
);

// Registro de tarefa não configurada
$storage = sys_get_temp_dir() . '/autotask_teste_entrada_invalida';
@mkdir($storage, 0775, true);

$auto = new AutoTask(require $raiz . '/config/tasks.php', $storage);
$erro = false;

try {
    $auto->register('fora', function (): void {});
} catch (InvalidArgumentException $e) {
    $erro = true;
}

verificar('tarefa não configurada lança InvalidArgumentException', $erro);

echo "\n" . ($falhas === 0 ? 'Todos os testes passaram.' : "$falhas falha(s).") . "\n";
exit($falhas ? 1 : 0);
