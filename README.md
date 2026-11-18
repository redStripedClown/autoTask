# autoTask

Framework em **PHP** para **agendar e executar tarefas** automaticamente.

Você diz quais tarefas existem, de quanto em quanto tempo cada uma deve rodar e o que ela faz. O autoTask decide **quando** executar, **tenta de novo** se a tarefa falhar, **evita execuções simultâneas** da mesma tarefa e **registra tudo** em um log.

> Projeto em desenvolvimento, feito em grupo. Veja a seção [Status do projeto](#status-do-projeto).

## Requisitos

- PHP 8.1 ou superior
- Composer é **opcional** (veja [Com ou sem Composer](#com-ou-sem-composer))

## Estrutura do projeto

```text
autotask/
├── src/                  # o framework (código genérico, reaproveitável)
│   ├── AutoTask.php
│   ├── Retryer.php
│   ├── StopStrategy.php
│   ├── WaitStrategy.php
│   └── Exception/
│       └── StorageException.php
├── config/
│   └── tasks.php         # quais tarefas existem e quando rodam
├── apps/                 # as tarefas de verdade, uma pasta por aplicação
│   ├── limpeza/tasks.php       # ainda vazio
│   └── relatorios/tasks.php    # ainda vazio
├── storage/              # o que o framework grava ao rodar
│   ├── state.json        # última execução de cada tarefa
│   ├── logs.jsonl        # log de execuções (uma linha JSON por execução)
│   └── locks/            # travas para evitar execuções simultâneas (criada ao rodar)
├── tests/
│   └── cenario_normal.php
├── bootstrap.php         # carrega núcleo, apps e config e registra as tarefas
├── run.php               # entrada de linha de comando (pending / task)
├── run-task.php          # atalho para executar uma única tarefa
└── composer.json
```

A ideia é separar **o que é código** (`src/`), **o que é configuração** (`config/`), **o que a tarefa faz** (`apps/`) e **o que o programa escreve** (`storage/`). Assim, mudar a frequência de uma tarefa não exige mexer no framework, e só a pasta `storage/` precisa de permissão de escrita.

## Como rodar

```bash
php run.php pending          # executa as tarefas que já venceram
php run.php task <nome>      # executa uma tarefa específica, mesmo que não tenha vencido
php run-task.php <nome>      # o mesmo que "run.php task <nome>"
```

Códigos de saída: `0` em sucesso e `1` em erro (comando inválido, tarefa inexistente ou não registrada, falha da tarefa).

Para agendar de verdade, chame `php run.php pending` de tempos em tempos com o agendador do sistema, por exemplo no `cron` (a cada minuto):

```cron
* * * * * cd /caminho/para/autotask && php run.php pending
```

> **Importante:** uma tarefa só roda se existir uma **função PHP** para ela. Veja [Criando uma tarefa](#criando-uma-tarefa).

## Configurando as tarefas

As tarefas ficam em `config/tasks.php`. A chave é o nome da tarefa:

```php
<?php

return [
    'tasks' => [
        'hello_world' => [
            'frequency'   => ['type' => 'interval', 'minutes' => 1],
            'max_retries' => 2,
        ],
    ],
];
```

### Tipos de frequência

| Tipo | Significado | Exemplo |
|------|-------------|---------|
| `interval` | roda a cada N minutos | `['type' => 'interval', 'minutes' => 5]` |
| `daily` | roda 1 vez por dia, a partir do horário | `['type' => 'daily', 'time' => '08:00']` |
| `times` | roda nos horários da lista | `['type' => 'times', 'times' => ['08:00', '14:30']]` |

### Retentativas

`max_retries` é quantas **retentativas** fazer se a tarefa falhar. O total de tentativas é `max_retries + 1`:

| `max_retries` | Tentativas |
|---------------|------------|
| `0` (ou omitido) | 1 |
| `3` | até 4 |

## Criando uma tarefa

1. Declare a tarefa em `config/tasks.php` (nome em `snake_case`, por exemplo `limpeza_logs`).
2. Crie a função PHP em `apps/<app>/tasks.php`, com o nome em `camelCase` (`limpezaLogs`).
3. Pronto: o `bootstrap.php` carrega todos os `apps/*/tasks.php` e registra automaticamente cada tarefa cuja função exista.

```php
<?php
// apps/limpeza/tasks.php

function helloWorld(): void
{
    echo "Hello World!\n";
}
```

Se a tarefa está na config mas não tem função, o `run.php pending` avisa `[ignorada] ... não está registrada` e `run.php task <nome>` termina com erro.

Quem usa o framework **sem** o `bootstrap.php` registra manualmente com `$auto->register('nome', $funcao)`.

## Como funciona

```text
config/tasks.php ──► register()            associa o nome da tarefa a uma função
                         │
                         ▼
                    runPending()           para cada tarefa: deveExecutar()? (lê state.json)
                         │
                         ▼
                    runTask()              pega o lock da tarefa (flock)
                         │
                         ▼
                    Retryer                executa e tenta de novo se falhar
                         │
                         ▼
                    registrarLog()         anota o resultado em logs.jsonl
                         │
                         ▼
                    registrarExecucao()    atualiza state.json e libera o lock
```

### Peças principais

- **`AutoTask`**: classe principal. Recebe a configuração e a pasta de `storage/` e valida que ela existe e é gravável.
  - `register($name, $fn)`: associa uma tarefa a uma função. Lança exceção se a tarefa não estiver em `config/tasks.php`.
  - `deveExecutar($id, $tarefa)`: diz se a tarefa deve rodar agora, de acordo com a frequência e a última execução.
  - `runTask($name)`: executa **uma** tarefa, forçando a execução. Grava o log e o estado e, se a tarefa falhar depois de todas as retentativas, lança a última exceção de novo.
  - `runPending()`: percorre todas as tarefas e executa só as vencidas. Cada tarefa roda isolada: se uma falhar, as seguintes continuam. Devolve um array `nome => status`.
  - `registrarLog(...)`: grava uma linha no `logs.jsonl`.
- **Bloqueio de concorrência**: cada tarefa tem o seu arquivo em `storage/locks/<nome>.lock`, travado com `flock(LOCK_EX | LOCK_NB)`. Se a tarefa já está rodando em outro processo, a nova execução é **ignorada** (status `ignorada` no log). Tarefas diferentes podem rodar ao mesmo tempo.
- **`Retryer`**: executa uma função com retentativas e, se todas falharem, propaga a **última** exceção. `getTentativas()` informa quantas tentativas foram feitas.
- **`StopStrategy`**: decide **quando parar** de tentar (`max_retries + 1`).
- **`WaitStrategy`**: decide **quanto esperar** entre tentativas: backoff progressivo (100 ms, 200 ms, 400 ms...) mais um **jitter** aleatório, para que tarefas que falharam juntas não tentem de novo no mesmo instante.

### Formato do log

Cada execução vira uma linha em `storage/logs.jsonl`:

```json
{"task":"hello_world","start":"2026-11-13T18:51:17-03:00","duration":0.0002,"status":"sucesso","attempts":1,"error":null}
```

| Campo | Descrição |
|-------|-----------|
| `task` | nome da tarefa |
| `start` | início da execução (ISO 8601) |
| `duration` | duração em segundos |
| `status` | `sucesso`, `falha` ou `ignorada` |
| `attempts` | quantas tentativas foram feitas |
| `error` | mensagem do erro, ou `null` |

## Com ou sem Composer

O projeto funciona **das duas formas**. O `bootstrap.php` usa o `vendor/autoload.php` se ele existir e, caso contrário, carrega os arquivos com `require_once`.

**Com Composer**, o `composer.json` já configura o autoload (PSR-4, namespace `AutoTask`):

```bash
composer dump-autoload
```

**Sem Composer**, carregue os arquivos manualmente:

```php
require_once __DIR__ . '/src/Exception/StorageException.php';
require_once __DIR__ . '/src/AutoTask.php';
require_once __DIR__ . '/src/StopStrategy.php';
require_once __DIR__ . '/src/WaitStrategy.php';
require_once __DIR__ . '/src/Retryer.php';
```

## Usando como biblioteca

Sem passar pelo CLI, dá para usar a classe diretamente:

```php
<?php

use AutoTask\AutoTask;

// (carregue as classes com require_once ou com o autoload do Composer)

$config = require __DIR__ . '/config/tasks.php';
$auto   = new AutoTask($config, __DIR__ . '/storage');

$auto->register('hello_world', function () {
    echo "Olá, mundo!\n";
});

$auto->runPending();            // roda o que já venceu
// ou: $auto->runTask('hello_world');   // roda esta tarefa agora
```

## Testes

```bash
php tests/cenario_normal.php
```

Verifica que `hello_world` executa com sucesso, grava o log e o `state.json` e **não** roda de novo logo em seguida. O teste usa uma pasta temporária como `storage/`, então não altera os arquivos reais.

> Atenção: este teste define a função `helloWorld` por conta própria quando ela não existe. Por isso ele passa mesmo com `apps/limpeza/tasks.php` vazio, e **não** prova que o CLI consegue executar a tarefa.

## Status do projeto

A especificação está nas [issues do repositório](https://github.com/redStripedClown/autoTask/issues).

| Item | Situação |
|------|----------|
| Estrutura de pastas e Composer | concluído |
| Classe `AutoTask` e construtor | concluído |
| `deveExecutar()` / verificação de frequência | concluído |
| Persistência de estado em `state.json` | concluído |
| `register()` | concluído |
| Retentativas: `Retryer`, `StopStrategy`, `WaitStrategy` | concluído |
| Logs em `logs.jsonl` | concluído |
| Bloqueio de concorrência com `flock` | concluído |
| `runTask()` | concluído |
| `runPending()` | concluído |
| CLI: `run.php` (`pending` / `task`) e `run-task.php` | concluído |
| Teste do cenário normal | concluído |
| Tarefas reais em `apps/` (`hello_world`, limpeza, relatórios) | **pendente** |

**O que falta para o framework funcionar de ponta a ponta:** as funções das tarefas. Hoje `apps/limpeza/tasks.php` e `apps/relatorios/tasks.php` estão vazios, então nenhuma tarefa da `config/tasks.php` fica registrada e `php run.php pending` apenas avisa que `hello_world` não está registrada.

Esta tabela é um retrato do momento; o estado atualizado está sempre nas issues.

## Como contribuir

1. Escolha uma issue e crie uma branch para ela, por exemplo `issue-4-register`.
2. Faça as mudanças e teste.
3. Abra um Pull Request descrevendo **o que foi feito, por que, como foi feito e como testar**, e use `Closes #N` para ligar à issue.
