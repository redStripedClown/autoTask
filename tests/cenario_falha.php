
<?php

use AutoTask\AutoTask;
use AutoTask\WaitStrategy;

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

    echo ($ok ? '[OK]    ' : '[FALHOU] ') . $descricao . "\n";

    if (!$ok) {
        $falhas++;
    }
}

$storage = sys_get_temp_dir() . '/autotask_teste_cenario_falha';

if (!is_dir($storage)) {
    mkdir($storage, 0775, true);
}

@unlink($storage . '/logs.jsonl');
@unlink($storage . '/state.json');

$config = require $raiz . '/config/tasks.php';

$auto = new AutoTask($config, $storage);
$auto->register('tarefa_com_erro', 'tarefaComErro');

$msg = null;

try {
    $auto->runTask('tarefa_com_erro');
} catch (Throwable $e) {
    $msg = $e->getMessage();
}

$logs = file($storage . '/logs.jsonl', FILE_IGNORE_NEW_LINES);
$entrada = json_decode($logs[0] ?? '{}', true);

verificar('runTask relança a exceção', $msg !== null);
verificar('status é falha', ($entrada['status'] ?? null) === 'falha');
verificar('foram realizadas 4 tentativas', ($entrada['attempts'] ?? null) === 4);
verificar('erro corresponde à exceção', $msg !== null && ($entrada['error'] ?? null) === $msg);
verificar('duração entre 0,7 e 1,5 segundos', ($entrada['duration'] ?? 0) >= 0.7 && ($entrada['duration'] ?? 99) < 1.5);

$ok = true;

foreach ([1 => [100, 200], 2 => [200, 300], 3 => [400, 500]] as $tentativa => [$min, $max]) {
    for ($i = 0; $i < 50; $i++) {
        $valor = (new WaitStrategy())->calcularEspera($tentativa);

        if ($valor < $min || $valor > $max) {
            $ok = false;
        }
    }
}

verificar('calcularEspera respeita as faixas esperadas', $ok);
verificar('logs.jsonl tem exatamente 1 entrada', count($logs) === 1);

echo "\n" . ($falhas === 0 ? 'Todos os testes passaram.' : "$falhas verificação(ões) falharam.") . "\n";

exit($falhas === 0 ? 0 : 1);

