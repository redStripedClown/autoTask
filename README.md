# autoTask

Framework em **PHP** para **agendar e executar tarefas** automaticamente.

Você diz quais tarefas existem, de quanto em quanto tempo cada uma deve rodar e o que ela faz. O autoTask decide **quando** executar, **tenta de novo** se a tarefa falhar e **registra tudo** em um log.

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
│   ├── limpeza/tasks.php
│   └── relatorios/tasks.php
├── storage/              # o que o framework grava ao rodar
│   ├── state.json        # última execução de cada tarefa
│   ├── logs.jsonl        # log de execuções (uma linha JSON por execução)
│   └── locks/            # travas para evitar execuções simultâneas
├── run.php               # entrada de linha de comando
├── run-task.php
└── composer.json
```

A ideia é separar **o que é código** (`src/`), **o que é configuração** (`config/`), **o que a tarefa faz** (`apps/`) e **o que o programa escreve** (`storage/`). Assim, mudar a frequência de uma tarefa não exige mexer no framework, e só a pasta `storage/` precisa de permissão de escrita.

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

## Como funciona

```text
config/tasks.php ──► register()            associa o nome da tarefa a uma função
                         │
                         ▼
                    deveExecutar()         a tarefa já venceu? (lê state.json)
                         │
                         ▼
                    Retryer                executa e tenta de novo se falhar
                         │
                         ▼
                    registrarLog()         anota o resultado em logs.jsonl
```

### Peças principais

- **`AutoTask`**: classe principal. Recebe a configuração e a pasta de `storage/` e valida que ela existe e é gravável.
  - `register($name, $fn)`: associa uma tarefa a uma função. Lança exceção se a tarefa não estiver em `config/tasks.php`.
  - `deveExecutar($id, $tarefa)`: diz se a tarefa deve rodar agora, de acordo com a frequência e a última execução.
  - `registrarLog(...)`: grava uma linha no `logs.jsonl`.
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

O projeto funciona **das duas formas**.

**Sem Composer**, carregue os arquivos com `require_once`:

```php
require_once __DIR__ . '/src/Exception/StorageException.php';
require_once __DIR__ . '/src/AutoTask.php';
require_once __DIR__ . '/src/StopStrategy.php';
require_once __DIR__ . '/src/WaitStrategy.php';
require_once __DIR__ . '/src/Retryer.php';
```

**Com Composer**, o `composer.json` já configura o autoload (PSR-4, namespace `AutoTask`):

```bash
composer dump-autoload
```

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Exemplo de uso

Enquanto o `runTask()` e o `run.php` não estão prontos, dá para montar o fluxo manualmente com as peças que já existem:

```php
<?php

use AutoTask\AutoTask;
use AutoTask\Retryer;

// (carregue as classes com require_once ou com o autoload do Composer)

$config  = require __DIR__ . '/config/tasks.php';
$auto    = new AutoTask($config, __DIR__ . '/storage');
$tarefa  = $config['tasks']['hello_world'];

if ($auto->deveExecutar('hello_world', $tarefa)) {
    $retryer = Retryer::daConfig($tarefa);
    $inicio  = new DateTimeImmutable();
    $t0      = microtime(true);

    try {
        $retryer->executar(function () {
            echo "Olá, mundo!\n";
        });
        $auto->registrarLog('hello_world', $inicio, microtime(true) - $t0, 'sucesso', $retryer->getTentativas());
    } catch (Exception $e) {
        $auto->registrarLog('hello_world', $inicio, microtime(true) - $t0, 'falha', $retryer->getTentativas(), $e->getMessage());
    }

    $auto->registrarExecucao('hello_world');
}
```

## Status do projeto

A especificação está nas [issues do repositório](https://github.com/redStripedClown/autoTask/issues).

| Item | Situação |
|------|----------|
| Estrutura de pastas e Composer (#1, #2) | concluído |
| Classe `AutoTask` e construtor (#3) | concluído |
| `deveExecutar()` / verificação de frequência (#5) | concluído |
| Persistência de estado em `state.json` (#11) | código na `main` |
| `register()` (#4) | em revisão |
| Retentativas: `Retryer`, `StopStrategy`, `WaitStrategy` (#8) | em revisão |
| Logs em `logs.jsonl` (#10) | em revisão |
| Bloqueio de concorrência com `flock` (#9) | pendente |
| `runTask()` (#6) | pendente |
| `runPending()` (#7) | pendente |
| `run.php`, a entrada de linha de comando (#12) | pendente |

Esta tabela é um retrato do momento; o estado atualizado está sempre nas issues.

## Como contribuir

1. Escolha uma issue e crie uma branch para ela, por exemplo `issue-4-register`.
2. Faça as mudanças e teste.
3. Abra um Pull Request descrevendo **o que foi feito, por que, como foi feito e como testar**, e use `Closes #N` para ligar à issue.
