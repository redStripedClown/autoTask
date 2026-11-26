<?php

function gerarRelatorioDiario(): void
{
    $arquivo = dirname(__DIR__, 2) . '/storage/relatorio.txt';

    file_put_contents(
        $arquivo,
        'Relatório gerado em ' . date('Y-m-d H:i:s') . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function gerarRelatorio(): void
{
    gerarRelatorioDiario();
}