<?php

/**
 * Configuração das tarefas.
 *
 * A chave é o nome lógico da tarefa (o mesmo nome usado em register()).
 *
 * Tipos de frequência aceitos (ver deveExecutar() em src/AutoTask.php):
 *   - interval: roda a cada N minutos        ['type' => 'interval', 'minutes' => 5]
 *   - daily:    roda 1x por dia a partir da hora   ['type' => 'daily', 'time' => '08:00']
 *   - times:    roda nos horários da lista          ['type' => 'times', 'times' => ['08:00', '14:30']]
 *
 * max_retries: quantas RETENTATIVAS fazer se a tarefa falhar
 * (total de tentativas = max_retries + 1). Se omitido, não retenta.
 */
return [
    'tasks' => [
        // Tarefa de exemplo, usada para testar o framework.
        'hello_world' => [
            'frequency'   => ['type' => 'interval', 'minutes' => 1],
            'max_retries' => 2,
        ],
    ],
];
