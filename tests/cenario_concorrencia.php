
<?php

use AutoTask\AutoTask;

$raiz = dirname(__DIR__);

require_once $raiz . '/src/Exception/StorageException.php';
require_once $raiz . '/src/AutoTask.php';
require_once $raiz . '/src/StopStrategy.php';
require_once $raiz . '/src/WaitStrategy.php';
require_once $raiz . '/src/Retryer.php';
require_once $raiz . '/apps/limpeza/tasks.php';

$falhas = 0;

function verificar(string $descricao, bool $ok): void
{
    global $falhas;

    echo ($ok ? '[OK] ' : '[FALHOU] ') . $descricao . "\n";

    if (!$ok) {
        $falhas++;
    }
}

$storage = sys_get_temp_dir() . '/autotask_teste_concorrencia';

@mkdir($storage, 0775, true);
@unlink($storage . '/logs.jsonl');
@unlink($storage . '/state.json');

$auto = new AutoTask(require $raiz . '/config/tasks.php', $storage);
$auto->register('hello_world', 'helloWorld');

$fp = fopen($storage . '/locks/hello_world.lock', 'c');
flock($fp, LOCK_EX | LOCK_NB);

ob_start();
$auto->runTask('hello_world');
$saida = ob_get_clean();

$logs = file($storage . '/logs.jsonl', FILE_IGNORE_NEW_LINES);
$entrada = json_decode($logs[0] ?? '{}', true);

verificar('tarefa bloqueada não foi executada', $saida === '');
verificar('execução foi ignorada', ($entrada['status'] ?? null) === 'ignorada');
verificar('log informa execução anterior ativa', ($entrada['error'] ?? null) === 'Ignorada: execução anterior ainda ativa');

flock($fp, LOCK_UN);

ob_start();
$auto->runTask('hello_world');
$saida = ob_get_clean();

verificar('tarefa executa após liberar o bloqueio', str_contains($saida, 'Hello World!'));
verificar('bloqueio foi liberado após a execução', flock($fp, LOCK_EX | LOCK_NB));

flock($fp, LOCK_UN);
fclose($fp);

echo "\n" . ($falhas === 0 ? 'Todos os testes passaram.' : "$falhas falha(s).") . "\n";
exit($falhas ? 1 : 0);
