<?php

/**
 * Ponto de entrada de linha de comando do autoTask.
 *
 * Uso:
 *     php run.php pending          Executa as tarefas pendentes (runPending())
 *     php run.php task <nome>      Executa uma tarefa específica (runTask())
 *
 * Exit codes:
 *     0  sucesso (pending concluído, ou task executada sem exceção)
 *     1  uso inválido, comando desconhecido, tarefa inexistente, storage inválido
 *        ou falha da própria tarefa
 */

use AutoTask\AutoTask;
use AutoTask\Exception\StorageException;

$raiz = __DIR__;


/* =========================================================================
 * 1) CARREGAMENTO DO NÚCLEO — Composer OPCIONAL
 * -------------------------------------------------------------------------
 * O QUE FAZ:
 *   Se existir o arquivo `vendor/autoload.php` (gerado pelo Composer),
 *   carrega-o. Caso contrário, carrega cada arquivo do `src/` manualmente
 *   com `require_once`.
 *
 * DE ONDE VEM:
 *   - `vendor/autoload.php` é o arquivo que o Composer cria quando você roda
 *     `composer install` ou `composer dump-autoload`. Ele lê o `composer.json`
 *     → chave `autoload` → `psr-4` (`"AutoTask\\": "src/"`) e passa a saber
 *     que a classe `AutoTask\AutoTask` mora em `src/AutoTask.php`, a classe
 *     `AutoTask\Retryer` em `src/Retryer.php`, e assim por diante. A partir
 *     daí, um simples `new AutoTask(...)` dispara o autoloader, que faz o
 *     `require` do arquivo certo.
 *   - Sem Composer, esse autoloader NÃO existe. Por isso o bloco `else`
 *     lista cada arquivo na ordem em que dependem uns dos outros:
 *         StorageException → AutoTask → StopStrategy → WaitStrategy → Retryer
 *     (AutoTask usa Retryer; Retryer usa StopStrategy e WaitStrategy; etc.)
 *
 * POR QUÊ:
 *   - O README deixa claro, na seção "Com ou sem Composer", que o projeto
 *     funciona das duas formas. Sem esse `if`, quem clonasse o repositório e
 *     rodasse `php run.php pending` sem `composer install` receberia
 *     "Class 'AutoTask\AutoTask' not found".
 *   - Com `require_once` (em vez de `require`), se um arquivo já tiver sido
 *     carregado — por exemplo porque outro arquivo o incluiu antes — o PHP
 *     não recarrega e não dá erro de "redeclare".
 *   - `is_file()` (em vez de `file_exists()`) só dá `true` para arquivos
 *     regulares; se existir um diretório com o nome `autoload.php` por
 *     acidente, não tentamos incluí-lo.
 * ========================================================================= */
if (is_file($raiz . '/vendor/autoload.php')) {
    require_once $raiz . '/vendor/autoload.php';
} else {
    require_once $raiz . '/src/Exception/StorageException.php';
    require_once $raiz . '/src/AutoTask.php';
    require_once $raiz . '/src/StopStrategy.php';
    require_once $raiz . '/src/WaitStrategy.php';
    require_once $raiz . '/src/Retryer.php';
}


/* =========================================================================
 * 2) CARREGAMENTO DOS APPS — via glob(), sem caminho fixo
 * -------------------------------------------------------------------------
 * O QUE FAZ:
 *   Procura em `apps/` todas as subpastas que tenham um arquivo `tasks.php`
 *   e inclui cada uma com `require_once`. Não há lista fixa de apps: se
 *   amanhã alguém criar `apps/backup/tasks.php`, ele é carregado
 *   automaticamente, sem editar o `run.php`.
 *
 * DE ONDE VEM:
 *   - A estrutura do projeto (README, "Estrutura do projeto") define:
 *         apps/
 *         ├── limpeza/tasks.php
 *         └── relatorios/tasks.php
 *     Cada `apps/<app>/tasks.php` apenas DECLARA funções PHP — a associação
 *     entre o nome lógico da tarefa (chave de `config/tasks.php`) e a função
 *     PHP é feita na etapa 5, por convenção de nome.
 *   - `glob()` é uma função nativa do PHP que devolve um array com todos os
 *     caminhos que casam com um padrão shell. `glob('apps/*/tasks.php')`
 *     usa o `*` como "qualquer nome de pasta de um nível" e devolve algo
 *     como:
 *         [ '/raiz/apps/limpeza/tasks.php',
 *           '/raiz/apps/relatorios/tasks.php' ]
 *   - O `?: []` no final é um *null coalescing*: `glob()` retorna `false`
 *     se nada casar ou se a pasta `apps/` não existir; `?: []` troca esse
 *     `false` por um array vazio, e o `foreach` simplesmente não itera.
 *
 * POR QUÊ:
 *   - Sem `glob()`, o `run.php` precisaria ter uma linha por app:
 *         require_once __DIR__ . '/apps/limpeza/tasks.php';
 *         require_once __DIR__ . '/apps/relatorios/tasks.php';
 *     Toda vez que um app novo entrasse (ou um saísse), alguém teria que
 *     lembrar de editar o `run.php` — um ponto de falha fácil de esquecer.
 *   - Com `glob()`, a fonte da verdade passa a ser o sistema de arquivos:
 *     existe pasta com `tasks.php` dentro de `apps/`, está carregado. O
 *     `run.php` fica independente de quantos apps existem.
 *   - `require_once` (não `require`) porque, se algum teste já tiver
 *     incluído `apps/limpeza/tasks.php` antes de chamar o `run.php`, o PHP
 *     não tenta redeclarar as funções e dá erro. O `_once` protege contra
 *     dupla inclusão.
 * ========================================================================= */
foreach (glob($raiz . '/apps/*/tasks.php') ?: [] as $appFile) {
    require_once $appFile;
}


/* =========================================================================
 * 3) CONFIGURAÇÃO
 * -------------------------------------------------------------------------
 * Carrega `config/tasks.php`, que devolve um array com `['tasks' => [...]]`.
 * Cada chave é o nome lógico da tarefa (o mesmo usado em register() e em
 * runTask()). O README documenta os tipos de frequência (`interval`,
 * `daily`, `times`) e o campo `max_retries`.
 * ========================================================================= */
$config = require $raiz . '/config/tasks.php';


/* =========================================================================
 * 4) STORAGE — criação automática e verificação do mkdir
 * -------------------------------------------------------------------------
 * O QUE FAZ:
 *   - Se `storage/` não existir, cria com `mkdir(..., recursive: true)`.
 *   - Verifica o retorno do `mkdir`: se ele falhar (sem permissão, disco
 *     cheio, etc.) E a pasta continuar inexistente, aborta com exit 1.
 *
 * POR QUÊ:
 *   - O construtor de `AutoTask` (src/AutoTask.php) EXIGE que a pasta de
 *     storage exista e seja gravável; caso contrário lança
 *     `StorageException`. Sem este bloco, o primeiro contato do usuário com
 *     o projeto seria uma exceção crua.
 *   - A verificação dupla `!@mkdir(...) && !is_dir(...)` cobre a corrida
 *     clássica: dois processos rodando `php run.php pending` ao mesmo tempo;
 *     um cria a pasta, o outro recebe `false` do `mkdir` (porque ela já
 *     existe), mas o `&& !is_dir(...)` confirma que agora ela está lá e o
 *     código segue em vez de abortar por engano.
 *   - O `@` suprime o warning do PHP; usamos a checagem booleana em vez
 *     disso para dar uma mensagem clara em STDERR.
 * ========================================================================= */
$storagePath = $raiz . '/storage';
if (!is_dir($storagePath)) {
    if (!@mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
        fwrite(STDERR, 'Erro: não foi possível criar a pasta storage/.' . PHP_EOL);
        exit(1);
    }
}


/* =========================================================================
 * 5) INSTANCIAÇÃO — try/catch (StorageException)
 * -------------------------------------------------------------------------
 * O QUE FAZ:
 *   Envolve `new AutoTask(...)` num `try/catch` que só trata
 *   `StorageException`. Se o construtor reclamar (pasta não gravável,
 *   `locks/` não pode ser criada, etc.), imprime mensagem amigável e sai
 *   com código 1.
 *
 * POR QUÊ:
 *   - `AutoTask::__construct` valida o storage e lança `StorageException`
 *     em cinco situações diferentes. Sem o `catch`, o usuário via stack
 *     trace em vez de "Erro: <motivo>".
 *   - O `catch` é específico (`StorageException`, não `\Throwable`) para
 *     NÃO mascarar outros bugs: um `TypeError` ou `Error` do próprio código
 *     deve continuar subindo e aparecer como está, porque é falha de
 *     programação, não de ambiente.
 * ========================================================================= */
try {
    $auto = new AutoTask($config, $storagePath);
} catch (StorageException $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}


/* =========================================================================
 * 6) REGISTRO DAS TAREFAS — convenção + function_exists + array_keys
 * -------------------------------------------------------------------------
 * O QUE FAZ:
 *   Para cada chave em `$config['tasks']`:
 *     1. Converte o nome lógico (`snake_case`) para o nome da função PHP
 *        (`camelCase`) via `snakeParaCamel()`.
 *     2. Se a função existir, registra com `$auto->register($nome, $funcao)`.
 *     3. Se não existir, pula — a tarefa fica "não registrada" e o
 *        `runPending()` / `runTask()` tratam isso com mensagem própria.
 *
 * POR QUÊ:
 *   - `array_keys($config['tasks'])` (em vez de hardcode `'hello_world'`)
 *     faz o registro acompanhar a config: acrescentar uma tarefa em
 *     `config/tasks.php` basta para ela ser registrada, sem editar o
 *     `run.php`.
 *   - A convenção `snake_case → camelCase` é a mesma usada em
 *     `tests/cenario_normal.php` (`hello_world` → `helloWorld()`). Mantém
 *     coerência entre teste e CLI e evita um array de mapeamento manual.
 *   - `function_exists()` antes de `register()` evita `Fatal error:
 *     Call to undefined function` (ou, no caso do `register`, um callable
 *     inválido) quando `apps/<algo>/tasks.php` ainda não define a função
 *     correspondente — situação real enquanto as issues #14 e afins não
 *     estão fechadas.
 * ========================================================================= */
foreach (array_keys($config['tasks'] ?? []) as $nomeTarefa) {
    $funcao = snakeParaCamel($nomeTarefa);
    if (function_exists($funcao)) {
        $auto->register($nomeTarefa, $funcao);
    }
}


/* =========================================================================
 * 7) VALIDAÇÃO DE $argv[1] — comando ausente ou inválido
 * -------------------------------------------------------------------------
 * O QUE FAZ:
 *   - `$argv[1] ?? null`: `$argv[1]` não existe se o script foi chamado
 *     sem argumento; o `?? null` normaliza isso.
 *   - Se for `null` → mostra o uso e `exit(1)` (mensagem "Uso: ...").
 *   - Se for desconhecido → mensagem "Comando inválido: <x>" + uso + exit 1.
 *
 * POR QUÊ:
 *   - Separar os dois casos (sem argumento vs. comando errado) dá mensagem
 *     mais precisa ao usuário. O critério "php run.php sem argumento mostra
 *     uso" é atendido no primeiro `if`; o critério "comando inválido mostra
 *     uso + exit 1" no segundo.
 *   - O README pede "mensagem de uso + exit code 1" para comando inválido;
 *     manter o `exit(1)` só nesse caso (e não no `pending` bem-sucedido)
 *     atende ao item 13 de exit(0) explícito nos caminhos de sucesso.
 * ========================================================================= */
$comando = $argv[1] ?? null;

if ($comando === null) {
    mostrarUso();
    exit(1);
}

if ($comando === 'pending') {
    $auto->runPending();
    exit(0);
}

if ($comando === 'task') {
    /* =====================================================================
     * 8) VALIDAÇÃO DE $argv[2] — nome da tarefa ausente
     * ---------------------------------------------------------------------
     * Sem esta validação, `$argv[2]` seria `null` e `runTask(null)` daria
     * `TypeError` do PHP ao passar null para parâmetro `string`. Aqui
     * imprimimos "Erro: informe o nome da tarefa." + uso + exit 1.
     * ===================================================================== */
    $nomeTarefa = $argv[2] ?? null;

    if ($nomeTarefa === null) {
        fwrite(STDERR, 'Erro: informe o nome da tarefa.' . PHP_EOL);
        mostrarUso();
        exit(1);
    }

    /* =====================================================================
     * 9) try/catch (\Throwable) em volta do runTask()
     * ---------------------------------------------------------------------
     * `runTask()` pode lançar:
     *   - InvalidArgumentException  (tarefa não configurada / não registrada)
     *   - StorageException          (lock não pode ser aberto)
     *   - \Throwable qualquer        (a ÚLTIMA exceção da tarefa, quando
     *                                todas as retentativas falham)
     * O `catch (\Throwable)` captura todas e transforma em mensagem clara
     * + exit(1). Sem ele, o usuário via stack trace cru, mesmo que o
     * critério "task inexistente mostra erro claro" ficasse no limite.
     * ===================================================================== */
    try {
        $auto->runTask($nomeTarefa);
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Erro ao executar a tarefa "' . $nomeTarefa . '": ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

fwrite(STDERR, 'Comando inválido: ' . $comando . PHP_EOL);
mostrarUso();
exit(1);


/* =========================================================================
 * Funções auxiliares
 * ========================================================================= */

/**
 * Mostra a mensagem de uso. Centralizada para não duplicar a string de uso
 * em cada ponto de erro.
 */
function mostrarUso(): void
{
    echo 'Uso:' . PHP_EOL;
    echo '  php run.php pending          Executa as tarefas pendentes' . PHP_EOL;
    echo '  php run.php task <nome>      Executa uma tarefa específica' . PHP_EOL;
}

/**
 * Converte o nome lógico da tarefa (snake_case) para o nome da função PHP
 * (camelCase), seguindo a convenção do projeto:
 *     hello_world -> helloWorld
 *     limpeza_logs -> limpezaLogs
 */
function snakeParaCamel(string $nome): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $nome))));
}