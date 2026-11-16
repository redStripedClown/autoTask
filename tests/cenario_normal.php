<?php

/**
 * Teste: cenário normal (hello_world)  -  issue #17
 *
 * Como rodar (na raiz do projeto):
 *     php tests/cenario_normal.php
 *
 * Verifica que a tarefa hello_world executa com sucesso:
 *   - a saída contém "Hello World!"
 *   - logs.jsonl recebe uma entrada com status "sucesso" e attempts 1
 *   - state.json é atualizado
 *
 * O teste usa uma pasta temporária como storage, então NÃO mexe nos arquivos
 * reais de storage/. Termina com código 0 se tudo passou e 1 se algo falhou.
 */

use AutoTask\AutoTask;

$raiz = dirname(__DIR__);

require_once $raiz . '/src/Exception/StorageException.php';
require_once $raiz . '/src/AutoTask.php';
require_once $raiz . '/src/StopStrategy.php';
require_once $raiz . '/src/WaitStrategy.php';
require_once $raiz . '/src/Retryer.php';
require_once $raiz . '/apps/limpeza/tasks.php';

// Enquanto apps/limpeza/tasks.php (issue #14) não tiver a função, usamos uma igual.
if (!function_exists('helloWorld')) {
    function helloWorld(): void
    {
        echo "Hello World!\n";
    }
}

$falhas = 0;

function verificar(string $descricao, bool $ok): void
{
    global $falhas;
    echo ($ok ? '[OK]    ' : '[FALHOU] ') . $descricao . PHP_EOL;
    if (!$ok) {
        $falhas++;
    }
}

// Pasta de storage temporária, sempre vazia no começo
$storage = sys_get_temp_dir() . '/autotask_teste_cenario_normal';
if (!is_dir($storage)) {
    mkdir($storage, 0775, true);
}
@unlink($storage . '/logs.jsonl');
@unlink($storage . '/state.json');

$config = require $raiz . '/config/tasks.php';
$auto   = new AutoTask($config, $storage);
$auto->register('hello_world', 'helloWorld');

// Executa as tarefas pendentes e captura tudo o que foi impresso
ob_start();
$auto->runPending();
$saida = ob_get_clean();

verificar('a saída contém "Hello World!"', str_contains($saida, 'Hello World!'));

$logs = is_file($storage . '/logs.jsonl')
    ? file($storage . '/logs.jsonl', FILE_IGNORE_NEW_LINES)
    : [];
$entrada = isset($logs[0]) ? json_decode($logs[0], true) : null;

verificar('logs.jsonl tem exatamente 1 entrada', count($logs) === 1);
verificar('a entrada é de hello_world', ($entrada['task'] ?? null) === 'hello_world');
verificar('status é "sucesso"', ($entrada['status'] ?? null) === 'sucesso');
verificar('attempts é 1', ($entrada['attempts'] ?? null) === 1);
verificar('error é null', array_key_exists('error', $entrada ?? []) && $entrada['error'] === null);

$estado = is_file($storage . '/state.json')
    ? json_decode(file_get_contents($storage . '/state.json'), true)
    : [];

verificar('state.json registra a última execução de hello_world', isset($estado['hello_world']));

// Logo depois a tarefa não deve estar vencida (interval de 1 minuto)
ob_start();
$auto->runPending();
ob_end_clean();
$logsDepois = file($storage . '/logs.jsonl', FILE_IGNORE_NEW_LINES);
verificar('rodar de novo logo em seguida NÃO executa a tarefa outra vez', count($logsDepois) === 1);

echo PHP_EOL . ($falhas === 0 ? 'Todos os testes passaram.' : "$falhas verificação(ões) falharam.") . PHP_EOL;
exit($falhas === 0 ? 0 : 1);
